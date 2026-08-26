<?php

declare(strict_types=1);

namespace Agent;

use PDO;

/**
 * Integracao com o CyberPanel (secao 8 do PLAN).
 *
 * Toda dependencia de detalhe interno do CyberPanel esta ISOLADA aqui. Se um
 * dia a estrutura mudar - ou se outro painel entrar em cena - basta escrever
 * um novo servico com a mesma assinatura publica e registrar no
 * SiteDiscoveryService. Nada mais no agente precisa mudar.
 *
 * FONTE DOS DADOS: o banco `cyberpanel`, tabela `websiteFunctions_websites`.
 * E a fonte oficial e a mais completa - traz dominio, selecao de PHP e estado
 * do site. A senha do MySQL fica em /etc/cyberpanel/mysqlPassword, legivel
 * apenas por root (por isso o agente roda como root).
 *
 * Nao usamos a API HTTP do CyberPanel de proposito: ela exigiria credenciais
 * de administrador em texto no config do agente e faria uma chamada de rede
 * para ler dado que ja esta no disco local.
 */
final class CyberPanelService
{
    private const PASSWORD_FILE = '/etc/cyberpanel/mysqlPassword';

    private const VERSION_FILES = [
        '/usr/local/CyberCP/version.txt',
        '/usr/local/CyberCP/CyberCP/version.txt',
        '/usr/local/cyberpanel/version.txt',
    ];

    private ?PDO $connection = null;

    private ?string $connectionError = null;

    public function __construct(private Logger $logger)
    {
    }

    public static function isInstalled(): bool
    {
        return is_dir('/usr/local/CyberCP');
    }

    public static function detectVersion(): ?string
    {
        foreach (self::VERSION_FILES as $file) {
            $content = Shell::readFile($file, 128);

            if ($content === null) {
                continue;
            }

            $content = trim($content);

            // Arquivos do CyberPanel costumam trazer JSON: {"version":"2.3","build":7}
            $decoded = json_decode($content, true);

            if (\is_array($decoded)) {
                $version = (string) ($decoded['version'] ?? '');
                $build   = (string) ($decoded['build'] ?? '');

                if ($version !== '') {
                    return $build !== '' ? $version . '.' . $build : $version;
                }
            }

            if ($content !== '' && preg_match('/^[0-9][0-9.]*$/', $content) === 1) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Lista os dominios cadastrados no CyberPanel.
     *
     * A consulta pede DELIBERADAMENTE so `domain` e `phpSelection`. Colunas
     * como `state` existem na tabela principal de sites mas nem sempre na de
     * subdominios (varia entre versoes/forks do CyberPanel) - e como nenhuma
     * delas e usada pelo restante do agente, pedir a menor quantidade
     * possivel de colunas e o que torna esta consulta compativel com o maior
     * numero de instalacoes.
     *
     * @return array<int,array{domain:string,php_version:?string,document_root:?string}>|null
     *         null quando a fonte nao esta disponivel (o chamador tenta outra)
     */
    public function listWebsites(): ?array
    {
        $pdo = $this->connect();

        if ($pdo === null) {
            $this->logger->debug('CyberPanel: banco indisponivel, usando fallback.', [
                'motivo' => $this->connectionError,
            ]);

            return null;
        }

        try {
            // childdomains cobre os subdominios criados pelo painel; a UNION
            // devolve tudo em uma consulta so.
            $sql = 'SELECT domain, phpSelection FROM websiteFunctions_websites
                    UNION ALL
                    SELECT domain, phpSelection FROM websiteFunctions_childdomains';

            $rows = $pdo->query($sql);

            if ($rows === false) {
                return null;
            }

            $sites = [];

            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $domain = strtolower(trim((string) ($row['domain'] ?? '')));

                if ($domain === '') {
                    continue;
                }

                $sites[] = [
                    'domain'        => $domain,
                    'php_version'   => $this->normalizePhpVersion($row['phpSelection'] ?? null),
                    'document_root' => $this->documentRootFor($domain),
                ];
            }

            $this->logger->debug('CyberPanel: dominios lidos do banco.', ['total' => \count($sites)]);

            return $sites;
        } catch (\Throwable $e) {
            $this->logger->warning('CyberPanel: falha ao consultar o banco: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Conexao com o banco do CyberPanel.
     *
     * Falhar aqui e uma situacao NORMAL (servidor sem CyberPanel, sem
     * permissao de root, MySQL parado). Por isso devolve null em vez de
     * lancar - a descoberta simplesmente segue para o proximo mecanismo.
     */
    private function connect(): ?PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        if ($this->connectionError !== null) {
            return null; // Ja tentamos e falhamos neste ciclo.
        }

        if (!self::isInstalled()) {
            $this->connectionError = 'CyberPanel nao instalado';

            return null;
        }

        $password = Shell::readFile(self::PASSWORD_FILE, 256);

        if ($password === null) {
            $this->connectionError = 'Nao foi possivel ler ' . self::PASSWORD_FILE . ' (o agente precisa rodar como root)';

            return null;
        }

        try {
            $this->connection = new PDO(
                'mysql:host=localhost;dbname=cyberpanel;charset=utf8mb4',
                'root',
                trim($password),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 5,
                ]
            );

            return $this->connection;
        } catch (\Throwable $e) {
            $this->connectionError = $e->getMessage();

            return null;
        }
    }

    /**
     * "PHP 8.2" / "php82" / "8.2" chegam de formas diferentes conforme a
     * versao do CyberPanel. Normalizamos tudo para "8.2".
     */
    private function normalizePhpVersion(mixed $value): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/(\d)\.?(\d)/', $value, $m) === 1) {
            return $m[1] . '.' . $m[2];
        }

        return null;
    }

    /**
     * O CyberPanel cria /home/<dominio>/public_html para cada site.
     */
    public function documentRootFor(string $domain): ?string
    {
        $path = '/home/' . $domain . '/public_html';

        return is_dir($path) ? $path : null;
    }

    /** Mensagem do ultimo problema de conexao, para diagnostico. */
    public function lastError(): ?string
    {
        return $this->connectionError;
    }
}
