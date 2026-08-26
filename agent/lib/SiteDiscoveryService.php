<?php

declare(strict_types=1);

namespace Agent;

/**
 * Descoberta automatica dos dominios hospedados (secoes 7 e 8 do PLAN).
 *
 * ---------------------------------------------------------------------------
 * CADEIA DE MECANISMOS
 * ---------------------------------------------------------------------------
 * Tenta na ordem, parando no primeiro que devolver resultado:
 *
 *   1. aaPanel      - vhosts em /www/server/panel/vhost/{nginx,apache}. Traz
 *                    dominio, document_root e a versao de PHP por site.
 *   2. CyberPanel   - banco `cyberpanel`. Fonte oficial: traz dominio, versao
 *                    de PHP e estado (secao 8: "priorizar mecanismos nativos").
 *   3. OpenLiteSpeed - /usr/local/lsws/conf/vhosts/<dominio>/. Funciona mesmo
 *                    sem acesso ao banco.
 *   4. /home        - convencao do CyberPanel: /home/<dominio>/public_html.
 *                    Ultimo recurso, mas cobre o caso de MySQL parado.
 *
 * Trocar o mecanismo no futuro (outro painel, API diferente) exige apenas
 * escrever um novo metodo e coloca-lo na cadeia. Nada fora desta classe
 * conhece a estrutura interna de cada painel.
 */
final class SiteDiscoveryService
{
    private const VHOSTS_PATH = '/usr/local/lsws/conf/vhosts';

    private const HOME_PATH = '/home';

    /** Diretorios em /home que nunca sao dominios. */
    private const HOME_IGNORE = [
        'lost+found', 'cyberpanel', 'backup', 'vmail', 'docker', 'ubuntu', 'root',
    ];

    private CyberPanelService $cyberPanel;

    private AaPanelService $aaPanel;

    public function __construct(private Logger $logger)
    {
        $this->cyberPanel = new CyberPanelService($logger);
        $this->aaPanel    = new AaPanelService($logger);
    }

    /**
     * @return array{sites:array<int,array<string,mixed>>,source:string}
     */
    public function discover(): array
    {
        $mechanisms = [
            'aapanel'       => fn (): ?array => $this->fromAaPanel(),
            'cyberpanel'    => fn (): ?array => $this->fromCyberPanel(),
            'openlitespeed' => fn (): ?array => $this->fromOpenLiteSpeed(),
            'home'          => fn (): ?array => $this->fromHomeDirectories(),
        ];

        foreach ($mechanisms as $source => $mechanism) {
            try {
                $sites = $mechanism();
            } catch (\Throwable $e) {
                $this->logger->warning("Descoberta via {$source} falhou: " . $e->getMessage());
                continue;
            }

            if ($sites !== null && $sites !== []) {
                $this->logger->info(
                    sprintf('Descoberta concluida via %s: %d dominio(s).', $source, \count($sites))
                );

                return ['sites' => $this->deduplicate($sites), 'source' => $source];
            }
        }

        $this->logger->warning(
            'Nenhum dominio descoberto. Verifique se o CyberPanel/aaPanel esta instalado e se o agente roda como root.'
        );

        return ['sites' => [], 'source' => 'nenhum'];
    }

    // -----------------------------------------------------------------
    // Mecanismo 1 - aaPanel (vhosts do painel)
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>>|null */
    private function fromAaPanel(): ?array
    {
        $websites = $this->aaPanel->listWebsites();

        if ($websites === null) {
            return null;
        }

        $sites = [];

        foreach ($websites as $website) {
            $domain = $this->normalizeDomain($website['domain']);

            if ($domain === null) {
                continue;
            }

            $sites[] = [
                'domain'        => $domain,
                'php_version'   => $website['php_version'],
                'document_root' => $website['document_root'] ?? $this->guessDocumentRoot($domain),
            ];
        }

        return $sites;
    }

    // -----------------------------------------------------------------
    // Mecanismo 2 - CyberPanel
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>>|null */
    private function fromCyberPanel(): ?array
    {
        $websites = $this->cyberPanel->listWebsites();

        if ($websites === null) {
            return null;
        }

        $sites = [];

        foreach ($websites as $website) {
            $domain = $this->normalizeDomain($website['domain']);

            if ($domain === null) {
                continue;
            }

            $sites[] = [
                'domain'        => $domain,
                'php_version'   => $website['php_version'],
                'document_root' => $website['document_root'] ?? $this->guessDocumentRoot($domain),
            ];
        }

        return $sites;
    }

    // -----------------------------------------------------------------
    // Mecanismo 3 - vhosts do OpenLiteSpeed
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>>|null */
    private function fromOpenLiteSpeed(): ?array
    {
        if (!is_dir(self::VHOSTS_PATH)) {
            return null;
        }

        $entries = @scandir(self::VHOSTS_PATH);

        if ($entries === false) {
            return null;
        }

        $sites = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $domain = $this->normalizeDomain($entry);

            if ($domain === null || !is_dir(self::VHOSTS_PATH . '/' . $entry)) {
                continue;
            }

            $sites[] = [
                'domain'        => $domain,
                'php_version'   => $this->phpFromVhostConf(self::VHOSTS_PATH . '/' . $entry . '/vhost.conf'),
                'document_root' => $this->guessDocumentRoot($domain),
            ];
        }

        return $sites;
    }

    /**
     * A selecao de PHP aparece no vhost.conf como caminho do socket lsphp:
     *   lsphp82/bin/lsphp  =>  8.2
     */
    private function phpFromVhostConf(string $file): ?string
    {
        $content = Shell::readFile($file, 65536);

        if ($content === null) {
            return null;
        }

        if (preg_match('/lsphp(\d)(\d)/', $content, $m) === 1) {
            return $m[1] . '.' . $m[2];
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Mecanismo 4 - convencao de diretorios em /home
    // -----------------------------------------------------------------

    /** @return array<int,array<string,mixed>>|null */
    private function fromHomeDirectories(): ?array
    {
        if (!is_dir(self::HOME_PATH)) {
            return null;
        }

        $entries = @scandir(self::HOME_PATH);

        if ($entries === false) {
            return null;
        }

        $sites = [];

        foreach ($entries as $entry) {
            if (
                $entry === '.'
                || $entry === '..'
                || \in_array(strtolower($entry), self::HOME_IGNORE, true)
            ) {
                continue;
            }

            $domain = $this->normalizeDomain($entry);

            if ($domain === null) {
                continue;
            }

            // So conta como site se tiver a estrutura de documento do painel.
            $root = self::HOME_PATH . '/' . $entry . '/public_html';

            if (!is_dir($root)) {
                continue;
            }

            $sites[] = [
                'domain'        => $domain,
                'php_version'   => null,
                'document_root' => $root,
            ];
        }

        return $sites;
    }

    // -----------------------------------------------------------------
    // Enriquecimento local (nao depende de rede)
    // -----------------------------------------------------------------

    /**
     * Detecta WordPress lendo o disco.
     *
     * Muito mais confiavel que inspecionar o HTML: um site com cache de pagina
     * ou com a meta generator removida continua sendo detectado, e a versao
     * exata vem do proprio wp-includes/version.php.
     *
     * @return array{detected:bool,version:?string}
     */
    public function detectWordPress(?string $documentRoot): array
    {
        if ($documentRoot === null || !is_dir($documentRoot)) {
            return ['detected' => false, 'version' => null];
        }

        $versionFile = $documentRoot . '/wp-includes/version.php';

        if (!is_file($versionFile)) {
            // Instalacao em subdiretorio nao e coberta aqui de proposito:
            // varrer a arvore inteira seria caro em servidores com centenas
            // de sites.
            return ['detected' => false, 'version' => null];
        }

        $content = Shell::readFile($versionFile, 8192);

        if ($content === null) {
            return ['detected' => true, 'version' => null];
        }

        if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m) === 1) {
            return ['detected' => true, 'version' => $m[1]];
        }

        return ['detected' => true, 'version' => null];
    }

    private function guessDocumentRoot(string $domain): ?string
    {
        $path = self::HOME_PATH . '/' . $domain . '/public_html';

        return is_dir($path) ? $path : null;
    }

    /**
     * Aceita apenas nomes que realmente parecem dominio. Filtra diretorios de
     * usuario, arquivos soltos e nomes invalidos.
     */
    private function normalizeDomain(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $domain = strtolower(trim($value));
        $domain = rtrim($domain, '.');

        if ($domain === '' || \strlen($domain) > 190) {
            return null;
        }

        if (preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9_-]{1,63}(?<!-)(\.(?!-)[a-z0-9_-]{1,63}(?<!-))+$/', $domain) !== 1) {
            return null;
        }

        return $domain;
    }

    /**
     * @param  array<int,array<string,mixed>> $sites
     * @return array<int,array<string,mixed>>
     */
    private function deduplicate(array $sites): array
    {
        $unique = [];

        foreach ($sites as $site) {
            $domain = (string) $site['domain'];

            if (!isset($unique[$domain])) {
                $unique[$domain] = $site;
                continue;
            }

            // Mantem a entrada mais completa entre duplicatas.
            foreach (['php_version', 'document_root'] as $field) {
                if (($unique[$domain][$field] ?? null) === null && ($site[$field] ?? null) !== null) {
                    $unique[$domain][$field] = $site[$field];
                }
            }
        }

        ksort($unique);

        return array_values($unique);
    }
}
