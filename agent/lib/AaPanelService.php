<?php

declare(strict_types=1);

namespace Agent;

/**
 * Integracao com o aaPanel (secao 12 do PLAN).
 *
 * Mesma assinatura publica do CyberPanelService: a descoberta e agnostica ao
 * painel. Todo detalhe interno do aaPanel (宝塔/aaPanel) fica ISOLADO aqui.
 *
 * FONTE DOS DADOS: os arquivos de vhost gerados pelo proprio painel em
 * /www/server/panel/vhost/{nginx,apache}/*.conf. Nao usamos o banco nem a
 * API HTTP do painel: os vhosts estao no disco, legiveis por root, e trazem
 * o dominio, o document_root e a versao de PHP selecionada por site
 * (include enable-php-XX.conf). O document_root segue a convencao do aaPanel:
 * /www/wwwroot/<dominio>.
 */
final class AaPanelService
{
    private const PANEL_PATH = '/www/server/panel';

    /** Diretorio de vhosts => tipo de servidor web que os gera. */
    private const VHOST_DIRS = [
        '/www/server/panel/vhost/nginx'  => 'nginx',
        '/www/server/panel/vhost/apache' => 'apache',
    ];

    /** Arquivos candidatos a conter a versao do painel (best-effort). */
    private const VERSION_FILES = [
        '/www/server/panel/data/version.pl',
        '/www/server/panel/version.pl',
        '/www/server/panel/version.txt',
    ];

    private ?string $lastError = null;

    public function __construct(private Logger $logger)
    {
    }

    public static function isInstalled(): bool
    {
        return is_dir(self::PANEL_PATH);
    }

    public static function detectVersion(): ?string
    {
        foreach (self::VERSION_FILES as $file) {
            $content = Shell::readFile($file, 128);

            if ($content === null) {
                continue;
            }

            if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', trim($content), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Lista os dominios cadastrados no aaPanel a partir dos vhosts.
     *
     * Um unico arquivo .conf pode conter o bloco da porta 80 e o da 443 com o
     * mesmo dominio; a lista e deduplicada por dominio e preserva a entrada
     * mais completa.
     *
     * @return array<int,array{domain:string,php_version:?string,document_root:?string}>|null
     *         null quando a fonte nao esta disponivel (o chamador tenta outra)
     */
    public function listWebsites(): ?array
    {
        if (!self::isInstalled()) {
            $this->lastError = 'aaPanel nao instalado';

            return null;
        }

        $sites   = [];
        $scanned = 0;

        foreach (self::VHOST_DIRS as $dir => $type) {
            if (!is_dir($dir)) {
                continue;
            }

            $entries = @scandir($dir);

            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.conf')) {
                    continue;
                }

                $parsed = $type === 'nginx'
                    ? $this->parseNginxVhost($dir . '/' . $entry)
                    : $this->parseApacheVhost($dir . '/' . $entry);

                if ($parsed === null) {
                    continue;
                }

                $scanned++;
                $domain = $parsed['domain'];

                if (!isset($sites[$domain])) {
                    $sites[$domain] = [
                        'domain'        => $domain,
                        'php_version'   => $parsed['php_version'],
                        'document_root' => $parsed['document_root'] ?? $this->documentRootFor($domain),
                    ];
                    continue;
                }

                // Blocos 80 + 443 no mesmo arquivo: preserva o campo mais completo.
                foreach (['php_version', 'document_root'] as $field) {
                    if (($sites[$domain][$field] ?? null) === null && ($parsed[$field] ?? null) !== null) {
                        $sites[$domain][$field] = $parsed[$field];
                    }
                }
            }
        }

        if ($scanned === 0) {
            $this->lastError = 'Nenhum vhost encontrado em ' . implode(', ', array_keys(self::VHOST_DIRS));

            return null;
        }

        $this->logger->debug('aaPanel: dominios lidos dos vhosts.', ['total' => \count($sites)]);

        return array_values($sites);
    }

    /** @return array{domain:string,php_version:?string,document_root:?string}|null */
    private function parseNginxVhost(string $file): ?array
    {
        $content = Shell::readFile($file, 131072);

        if ($content === null) {
            return null;
        }

        // server_name lista o dominio principal + aliases: "exemplo.com www.exemplo.com".
        if (preg_match('/server_name\s+([^;]+);/', $content, $m) !== 1) {
            return null;
        }

        $domain = $this->primaryDomain($m[1]);

        if ($domain === null) {
            return null;
        }

        $root = null;

        if (preg_match('/root\s+([^;]+);/', $content, $m) === 1) {
            $root = $this->cleanPath($m[1]);
        }

        return [
            'domain'        => $domain,
            'php_version'   => $this->phpFromInclude($content),
            'document_root' => $root,
        ];
    }

    /** @return array{domain:string,php_version:?string,document_root:?string}|null */
    private function parseApacheVhost(string $file): ?array
    {
        $content = Shell::readFile($file, 131072);

        if ($content === null) {
            return null;
        }

        if (preg_match('/ServerName\s+(\S+)/i', $content, $m) !== 1) {
            return null;
        }

        $domain = $this->primaryDomain($m[1]);

        if ($domain !== null) {
            $domain = self::stripInternalPrefix($domain);
        }

        if ($domain === null) {
            return null;
        }

        $root = null;

        if (preg_match('/DocumentRoot\s+"?([^"\s]+)"?/i', $content, $m) === 1) {
            $root = $this->cleanPath($m[1]);
        }

        return [
            'domain'        => $domain,
            'php_version'   => $this->phpFromInclude($content),
            'document_root' => $root,
        ];
    }

    /** include enable-php-74.conf; => 7.4 */
    private function phpFromInclude(string $content): ?string
    {
        if (preg_match('/enable-php-(\d)(\d)\.conf/', $content, $m) === 1) {
            return $m[1] . '.' . $m[2];
        }

        return null;
    }

    /** Primeiro token que parece dominio, ignorando '_' e wildcards. */
    private function primaryDomain(string $value): ?string
    {
        $tokens = preg_split('/[\s,]+/', trim($value)) ?: [];

        foreach ($tokens as $token) {
            $token = strtolower(trim($token, " \t\n\r\0\x0B;\"'"));

            if ($token === '' || $token === '_' || str_contains($token, '*')) {
                continue;
            }

            // Vhosts internos do painel (phpfpm_status.conf, 0.default.conf etc.)
            // usam loopback, "localhost" ou o placeholder padrao como server_name;
            // nao sao sites reais.
            if (
                $token === 'localhost'
                || $token === 'bt.default.com'
                || filter_var($token, FILTER_VALIDATE_IP) !== false
            ) {
                continue;
            }

            return rtrim($token, '.');
        }

        return null;
    }

    private function cleanPath(string $value): ?string
    {
        $path = trim($value, " \t\n\r\0\x0B;\"'");

        return $path === '' ? null : $path;
    }

    /**
     * O aaPanel gera, nos vhosts Apache usados para terminacao SSL, ServerName
     * com prefixos INTERNOS que nao sao sites reais (nao tem DNS):
     *
     *   <8 hex>.<dominio>   => 305f7428.amiljoaopessoa.com.br  (id interno)
     *   SSL.<dominio>       => SSL.amiljoaopessoa.com.br        (marcador SSL)
     *
     * O dominio verdadeiro esta no vhost nginx (server_name) e no DocumentRoot.
     * Removemos o prefixo para a deduplicacao enxergar o dominio real.
     */
    private static function stripInternalPrefix(string $domain): string
    {
        return preg_replace('/^(?:[0-9a-f]{8}|ssl)\./', '', $domain) ?? $domain;
    }

    /** Convencao do aaPanel: /www/wwwroot/<dominio>. */
    public function documentRootFor(string $domain): ?string
    {
        $path = '/www/wwwroot/' . $domain;

        return is_dir($path) ? $path : null;
    }

    /** Mensagem do ultimo problema, para diagnostico. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }
}
