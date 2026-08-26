<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Models\Server;
use App\Models\Site;
use App\Services\AlertService;
use App\Services\MonitoringService;
use App\Services\SslService;

/**
 * ============================================================================
 *  SEEDER DE DEMONSTRACAO - DADOS FICTICIOS
 * ============================================================================
 *
 * Popula o banco com uma infraestrutura ficticia para que o funcionamento do
 * painel possa ser avaliado sem precisar de VPS reais.
 *
 * TUDO que este seeder cria fica marcado com `is_demo = 1` nas tabelas
 * `servers` e `sites` (secao 38 do PLAN: "deixar claramente identificados
 * como dados de teste"). A interface exibe o selo "DEMO" ao lado do nome, e o
 * comando `db:seed --remove` apaga todos eles de uma vez.
 *
 * IMPORTANTE: os ALERTAS nao sao inventados. Depois de gravar servidores,
 * metricas, sites e certificados, o seeder chama o motor real de alertas
 * (MonitoringService / SslService). O que aparece na tela de Alertas foi
 * produzido pelas mesmas regras que rodariam em producao - e por isso os
 * numeros batem com os limites configurados.
 */
final class DemoSeeder
{
    /** Semente fixa: rodar de novo produz a mesma infraestrutura. */
    private const SEED = 20260814;

    private const PROVIDERS = [
        'Hostinger', 'Contabo', 'DigitalOcean', 'Hetzner',
        'Vultr', 'HostGator', 'Locaweb', 'UOL Host',
    ];

    /**
     * Perfis dos servidores ficticios.
     *
     * `profile` define o comportamento das metricas geradas e, por
     * consequencia, quais alertas o motor real vai abrir.
     */
    private const SERVERS = [
        ['name' => 'VPS Joao Pessoa',     'provider' => 'Hostinger',    'ip' => '45.132.74.18',   'host' => 'jp01.infra-demo.com.br',  'profile' => 'saudavel',   'sites' => 32, 'cores' => 4,  'ram' => 8,  'disk' => 160],
        ['name' => 'VPS Recife',          'provider' => 'Contabo',      'ip' => '45.132.88.204',  'host' => 'rec01.infra-demo.com.br', 'profile' => 'disco',      'sites' => 41, 'cores' => 6,  'ram' => 16, 'disk' => 200],
        ['name' => 'VPS Sao Paulo 01',    'provider' => 'DigitalOcean', 'ip' => '157.230.201.77', 'host' => 'sp01.infra-demo.com.br',  'profile' => 'saudavel',   'sites' => 28, 'cores' => 4,  'ram' => 8,  'disk' => 160],
        ['name' => 'VPS Sao Paulo 02',    'provider' => 'Hetzner',      'ip' => '167.235.14.92',  'host' => 'sp02.infra-demo.com.br',  'profile' => 'cpu',        'sites' => 22, 'cores' => 8,  'ram' => 32, 'disk' => 480],
        ['name' => 'VPS Natal',           'provider' => 'Vultr',        'ip' => '108.61.99.130',  'host' => 'nat01.infra-demo.com.br', 'profile' => 'offline',    'sites' => 17, 'cores' => 2,  'ram' => 4,  'disk' => 80],
        ['name' => 'VPS Fortaleza',       'provider' => 'HostGator',    'ip' => '192.185.60.44',  'host' => 'for01.infra-demo.com.br', 'profile' => 'memoria',    'sites' => 24, 'cores' => 4,  'ram' => 8,  'disk' => 160],
        ['name' => 'VPS Curitiba',        'provider' => 'Locaweb',      'ip' => '177.53.143.61',  'host' => 'cwb01.infra-demo.com.br', 'profile' => 'saudavel',   'sites' => 19, 'cores' => 4,  'ram' => 8,  'disk' => 160],
        ['name' => 'VPS Campina Grande',  'provider' => 'UOL Host',     'ip' => '200.147.36.155', 'host' => 'cg01.infra-demo.com.br',  'profile' => 'moderado',   'sites' => 15, 'cores' => 2,  'ram' => 4,  'disk' => 80],
    ];

    /** Radicais usados para montar dominios plausiveis. */
    private const WORDS_A = [
        'loja', 'clinica', 'escritorio', 'agencia', 'padaria', 'oficina', 'estudio',
        'imobiliaria', 'restaurante', 'academia', 'pousada', 'construtora', 'contabilidade',
        'farmacia', 'petshop', 'grafica', 'transportadora', 'consultoria', 'distribuidora',
        'marmoraria', 'floricultura', 'joalheria', 'sorveteria', 'lavanderia', 'serralheria',
    ];

    private const WORDS_B = [
        'central', 'nordeste', 'brasil', 'prime', 'sul', 'norte', 'litoral', 'vale',
        'real', 'nova', 'bom', 'forte', 'alfa', 'delta', 'vitoria', 'aurora', 'sertao',
        'atlantico', 'horizonte', 'planalto', 'campo', 'serra', 'rio', 'mar', 'sol',
    ];

    private const TLDS = ['.com.br', '.com.br', '.com.br', '.com', '.net.br', '.org.br', '.app.br'];

    private const PHP_VERSIONS = ['8.1', '8.2', '8.2', '8.2', '8.3', '8.3', '7.4'];

    private const WP_VERSIONS = ['6.4.3', '6.5.5', '6.6.2', '6.7.1', '6.7.1', '6.8.1'];

    private const SSL_ISSUERS = [
        "Let's Encrypt",
        "Let's Encrypt",
        "Let's Encrypt",
        'ZeroSSL',
        'Sectigo Limited',
        'Google Trust Services',
    ];

    /** @var callable(string):void */
    private $output;

    /** @var array<int,string> Dominios ja usados, para nao repetir. */
    private array $usedDomains = [];

    public function __construct(?callable $output = null)
    {
        $this->output = $output ?? static function (string $line): void {
            echo $line . \PHP_EOL;
        };
    }

    /**
     * @return array<string,int>
     */
    public function run(): array
    {
        mt_srand(self::SEED);

        $this->say('Gerando infraestrutura ficticia...');

        $counters = [
            'servidores'   => 0,
            'servicos'     => 0,
            'metricas'     => 0,
            'sites'        => 0,
            'checagens'    => 0,
            'certificados' => 0,
        ];

        foreach (self::SERVERS as $index => $spec) {
            $serverId = $this->createServer($spec, $index);
            $counters['servidores']++;

            $counters['servicos'] += $this->createServices($serverId, $spec);
            $counters['metricas'] += $this->createMetrics($serverId, $spec);

            $siteResult = $this->createSites($serverId, $spec);
            $counters['sites']        += $siteResult['sites'];
            $counters['checagens']    += $siteResult['checks'];
            $counters['certificados'] += $siteResult['certs'];

            $this->say(sprintf(
                '  [%d/%d] %-22s %3d sites  %4d metricas  perfil: %s',
                $index + 1,
                \count(self::SERVERS),
                $spec['name'],
                $siteResult['sites'],
                $counters['metricas'],
                $spec['profile']
            ));
        }

        $this->say('');
        $this->say('Executando o motor REAL de alertas sobre os dados gerados...');

        $ssl       = SslService::refreshAll();
        $resources = MonitoringService::evaluateResourceAlerts();
        $siteAlrt  = MonitoringService::evaluateSiteAlerts();
        $offline   = MonitoringService::detectOfflineServers();

        $this->say(sprintf('  SSL recalculados ...: %d', $ssl['recalculated']));
        $this->say(sprintf('  Recursos avaliados .: %d servidores, %d alertas', $resources['servers'], $resources['alerts']));
        $this->say(sprintf('  Sites avaliados ....: %d offline', $siteAlrt['offline']));
        $this->say(sprintf('  Servidores offline .: %d', $offline['went_offline']));

        $this->createAuditTrail();

        $counters['alertas'] = (int) Database::scalar('SELECT COUNT(*) FROM alerts');

        return $counters;
    }

    // -----------------------------------------------------------------
    // Servidores
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $spec */
    private function createServer(array $spec, int $index): int
    {
        $offline = $spec['profile'] === 'offline';

        // Offline ha ~2h20; os demais reportaram ha poucos minutos.
        $lastSeen = $offline
            ? date('Y-m-d H:i:s', time() - 8400)
            : date('Y-m-d H:i:s', time() - mt_rand(30, 280));

        $osList = [
            ['Ubuntu', '22.04.4 LTS', '5.15.0-119-generic'],
            ['Ubuntu', '20.04.6 LTS', '5.4.0-192-generic'],
            ['AlmaLinux', '8.10', '4.18.0-553.16.1.el8_10.x86_64'],
            ['Ubuntu', '22.04.4 LTS', '5.15.0-117-generic'],
        ];
        $os = $osList[$index % \count($osList)];

        $cpuModels = [
            'AMD EPYC 7282 16-Core Processor',
            'Intel(R) Xeon(R) CPU E5-2680 v4 @ 2.40GHz',
            'AMD EPYC 7763 64-Core Processor',
            'Intel(R) Xeon(R) Gold 6226R CPU @ 2.90GHz',
        ];

        return Database::insert('servers', [
            'uid'                => Server::generateUid(),
            'name'               => $spec['name'],
            'provider'           => $spec['provider'],
            'hostname'           => $spec['host'],
            'ip'                 => $spec['ip'],
            'public_ip'          => $spec['ip'],
            'description'        => sprintf(
                'Servidor de demonstracao (%s) com CyberPanel e OpenLiteSpeed. %d vCPU, %d GB RAM, %d GB SSD.',
                $spec['provider'],
                $spec['cores'],
                $spec['ram'],
                $spec['disk']
            ),
            // Mesmo o servidor "caido" nasce como online: quem o marca como
            // offline e o motor real (MonitoringService::detectOfflineServers),
            // chamado no fim do seeder, porque o last_seen_at ficou velho.
            // Assim o alerta de servidor offline tambem e genuino.
            'status'             => Server::STATUS_ONLINE,
            'os_name'            => $os[0],
            'os_version'         => $os[1],
            'arch'               => 'x86_64',
            'kernel'             => $os[2],
            'cpu_cores'          => $spec['cores'],
            'cpu_model'          => $cpuModels[$index % \count($cpuModels)],
            'uptime'             => mt_rand(4, 210) * 86400 + mt_rand(0, 86399),
            'agent_version'      => '1.0.0',
            'cyberpanel_version' => '2.3.' . mt_rand(5, 8),
            'last_seen_at'       => $lastSeen,
            'last_metric_at'     => $lastSeen,
            'is_demo'            => 1,
            'created_at'         => date('Y-m-d H:i:s', time() - mt_rand(60, 400) * 86400),
            'updated_at'         => $lastSeen,
        ]);
    }

    /** @param array<string,mixed> $spec */
    private function createServices(int $serverId, array $spec): int
    {
        $offline = $spec['profile'] === 'offline';

        $services = [
            ['openlitespeed', 'OpenLiteSpeed',    'running', '1.7.' . mt_rand(17, 19)],
            ['mariadb',       'MariaDB / MySQL',  'running', '10.' . mt_rand(6, 11) . '.' . mt_rand(2, 9)],
            ['cyberpanel',    'CyberPanel',       'running', '2.3.' . mt_rand(5, 8)],
            ['php',           'PHP',              'running', '8.' . mt_rand(1, 3) . '.' . mt_rand(10, 30)],
        ];

        // Nem todo servidor tem Redis - ausencia nao e erro (secao 6 do PLAN).
        if ($serverId % 3 !== 0) {
            $services[] = ['redis', 'Redis', 'running', '7.0.' . mt_rand(10, 15)];
        } else {
            $services[] = ['redis', 'Redis', 'not_installed', null];
        }

        $checkedAt = now_string();
        $count     = 0;

        foreach ($services as [$name, $label, $status, $version]) {
            // Servidor offline: ultimo estado conhecido vira "desconhecido".
            $finalStatus = $offline && $status === 'running' ? 'unknown' : $status;

            Database::insert('server_services', [
                'server_id'  => $serverId,
                'name'       => $name,
                'label'      => $label,
                'status'     => $finalStatus,
                'version'    => $version,
                'detail'     => $finalStatus === 'not_installed' ? 'Servico nao instalado neste servidor' : null,
                'checked_at' => $checkedAt,
                'created_at' => $checkedAt,
                'updated_at' => $checkedAt,
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Serie temporal de metricas.
     *
     * Resolucao decrescente conforme o dado envelhece - exatamente o que
     * acontece na pratica quando o agente roda de 5 em 5 minutos e a retencao
     * mantem 30 dias:
     *   ultimas 24 h .....: 1 ponto a cada 5 min
     *   24 h a 72 h ......: 1 ponto a cada 15 min
     *   3 a 30 dias ......: 1 ponto por hora
     *
     * @param array<string,mixed> $spec
     */
    private function createMetrics(int $serverId, array $spec): int
    {
        $ramTotal  = $spec['ram'] * 1024 * 1024 * 1024;
        $diskTotal = $spec['disk'] * 1024 * 1024 * 1024;
        $cores     = (int) $spec['cores'];
        $offline   = $spec['profile'] === 'offline';

        // Alvo final de cada metrica conforme o perfil. E a partir do ultimo
        // ponto que o motor de alertas decide o que abrir.
        [$cpuBase, $ramBase, $diskBase] = match ($spec['profile']) {
            'disco'    => [31.0, 54.0, 87.4],
            'cpu'      => [93.2, 61.0, 44.0],
            'memoria'  => [38.0, 84.6, 58.0],
            'moderado' => [58.0, 71.0, 68.0],
            'offline'  => [22.0, 45.0, 51.0],
            default    => [24.0, 42.0, 46.0],
        };

        $now = time();

        // Uptime coerente: cresce junto com o tempo da amostra.
        $bootedAt = $now - mt_rand(12, 210) * 86400;

        // [timestamp inicial, timestamp final, passo em segundos]
        $windows = [
            [$now - 30 * 86400, $now - 3 * 86400, 3600],
            [$now - 3 * 86400,  $now - 86400,     900],
            [$now - 86400,      $offline ? $now - 8400 : $now, 300],
        ];

        $rows  = [];
        $total = 0;

        foreach ($windows as [$from, $to, $step]) {
            for ($t = $from; $t <= $to; $t += $step) {
                // Quanto mais recente, mais perto do alvo.
                $progress = ($t - ($now - 30 * 86400)) / (30 * 86400);

                // Ondulacao diaria: pico a tarde, vale de madrugada.
                $hour  = (int) date('G', $t);
                $daily = sin(($hour - 4) / 24 * 2 * M_PI) * 0.5 + 0.5;

                $cpu = $this->clamp(
                    $cpuBase * (0.55 + 0.45 * $progress) * (0.7 + 0.6 * $daily) + $this->jitter(6),
                    1,
                    99.5
                );

                $ram = $this->clamp(
                    $ramBase * (0.80 + 0.20 * $progress) * (0.9 + 0.2 * $daily) + $this->jitter(3),
                    5,
                    98
                );

                // Disco so cresce - e o que torna o alerta de disco previsivel.
                $disk = $this->clamp(
                    $diskBase * (0.72 + 0.28 * $progress) + $this->jitter(0.6),
                    5,
                    99
                );

                $load1  = round(($cpu / 100) * $cores * (0.8 + mt_rand(0, 40) / 100), 2);
                $load5  = round($load1 * (0.85 + mt_rand(0, 20) / 100), 2);
                $load15 = round($load1 * (0.72 + mt_rand(0, 25) / 100), 2);

                $ramUsed  = (int) round($ramTotal * $ram / 100);
                $diskUsed = (int) round($diskTotal * $disk / 100);
                $swapTotal = 2 * 1024 * 1024 * 1024;
                $swapUsed  = (int) round($swapTotal * $this->clamp($ram - 55, 0, 40) / 100);

                $rows[] = [
                    $serverId,
                    round($cpu, 2),
                    $ramTotal,
                    $ramUsed,
                    $ramTotal - $ramUsed,
                    round($ram, 2),
                    $swapTotal,
                    $swapUsed,
                    round($swapUsed / $swapTotal * 100, 2),
                    $diskTotal,
                    $diskUsed,
                    $diskTotal - $diskUsed,
                    round($disk, 2),
                    $load1,
                    $load5,
                    $load15,
                    max(0, $t - $bootedAt),
                    mt_rand(90, 320),
                    date('Y-m-d H:i:s', $t),
                ];

                $total++;

                if (\count($rows) >= 400) {
                    $this->flushMetrics($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            $this->flushMetrics($rows);
        }

        // As amostras mais recentes recebem exatamente o valor-alvo do perfil.
        //
        // Sem isso a ondulacao diaria poderia deixar o ultimo ponto em um vale
        // (um servidor com perfil "cpu" seria semeado as 3h da manha com 40%
        // de CPU) e o motor de alertas - que olha SEMPRE a ultima amostra -
        // nao abriria nada. Ajustar as 3 ultimas linhas mantem a serie
        // continua e garante que a demonstracao mostre os alertas previstos.
        $this->alignLatestSamples($serverId, $cpuBase, $ramBase, $diskBase, $ramTotal, $diskTotal, $cores);

        return $total;
    }

    private function alignLatestSamples(
        int $serverId,
        float $cpu,
        float $ram,
        float $disk,
        int $ramTotal,
        int $diskTotal,
        int $cores
    ): void {
        $load1 = round(($cpu / 100) * $cores * 1.05, 2);

        Database::statement(
            'UPDATE server_metrics
             SET cpu_usage    = ?,
                 ram_percent  = ?,
                 ram_used     = ?,
                 ram_available = ?,
                 disk_percent = ?,
                 disk_used    = ?,
                 disk_free    = ?,
                 load_1       = ?,
                 load_5       = ?,
                 load_15      = ?
             WHERE server_id = ?
             ORDER BY id DESC
             LIMIT 3',
            [
                round($cpu, 2),
                round($ram, 2),
                (int) round($ramTotal * $ram / 100),
                $ramTotal - (int) round($ramTotal * $ram / 100),
                round($disk, 2),
                (int) round($diskTotal * $disk / 100),
                $diskTotal - (int) round($diskTotal * $disk / 100),
                $load1,
                round($load1 * 0.93, 2),
                round($load1 * 0.81, 2),
                $serverId,
            ]
        );
    }

    /** @param array<int,array<int,mixed>> $rows */
    private function flushMetrics(array $rows): void
    {
        $columns = '(server_id, cpu_usage, ram_total, ram_used, ram_available, ram_percent,
                     swap_total, swap_used, swap_percent, disk_total, disk_used, disk_free,
                     disk_percent, load_1, load_5, load_15, uptime, processes, created_at)';

        $placeholders = implode(',', array_fill(0, \count($rows), '(' . implode(',', array_fill(0, 19, '?')) . ')'));

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $bindings[] = $value;
            }
        }

        Database::statement(
            "INSERT INTO server_metrics {$columns} VALUES {$placeholders}",
            $bindings
        );
    }

    // -----------------------------------------------------------------
    // Sites
    // -----------------------------------------------------------------

    /**
     * @param  array<string,mixed> $spec
     * @return array{sites:int,checks:int,certs:int}
     */
    private function createSites(int $serverId, array $spec): array
    {
        $quantity = (int) $spec['sites'];
        $offline  = $spec['profile'] === 'offline';

        $result = ['sites' => 0, 'checks' => 0, 'certs' => 0];

        for ($i = 0; $i < $quantity; $i++) {
            $domain = $this->uniqueDomain();

            // Distribuicao: maioria online, alguns com problema.
            $roll = mt_rand(1, 100);

            if ($offline) {
                // Servidor caiu: os sites dele param junto.
                $httpStatus = 0;
                $error      = 'Connection timed out after 10000 milliseconds';
                $response   = null;
            } elseif ($roll <= 88) {
                $httpStatus = 200;
                $error      = null;
                $response   = mt_rand(78, 940);
            } elseif ($roll <= 92) {
                $httpStatus = 301;
                $error      = null;
                $response   = mt_rand(60, 300);
            } elseif ($roll <= 95) {
                // 4xx nao derruba o site para offline (secao 17 do PLAN).
                $httpStatus = mt_rand(0, 1) === 0 ? 403 : 404;
                $error      = null;
                $response   = mt_rand(70, 400);
            } elseif ($roll <= 98) {
                $httpStatus = [500, 502, 503][mt_rand(0, 2)];
                $error      = null;
                $response   = mt_rand(1200, 9000);
            } else {
                $httpStatus = 0;
                $error      = 'Could not resolve host: ' . $domain;
                $response   = null;
            }

            $status = \App\Services\HttpStatusService::classify(
                $httpStatus === 0 ? null : $httpStatus,
                $response,
                $error
            );

            $isWordpress = mt_rand(1, 100) <= 64;
            $https       = $httpStatus !== 0 && mt_rand(1, 100) <= 94;
            $now         = now_string();

            $siteId = Database::insert('sites', [
                'server_id'          => $serverId,
                'domain'             => $domain,
                'url'                => ($https ? 'https://' : 'http://') . $domain,
                'status'             => $status,
                'http_status'        => $httpStatus === 0 ? null : $httpStatus,
                'response_time'      => $response,
                'https_available'    => $https ? 1 : 0,
                'ip'                 => $spec['ip'],
                'php_version'        => self::PHP_VERSIONS[array_rand(self::PHP_VERSIONS)],
                'wordpress_detected' => $isWordpress ? 1 : 0,
                'wordpress_version'  => $isWordpress ? self::WP_VERSIONS[array_rand(self::WP_VERSIONS)] : null,
                'document_root'      => '/home/' . $domain . '/public_html',
                'last_error'         => $error,
                'last_check_at'      => $now,
                'last_online_at'     => $status === Site::STATUS_ONLINE
                    ? $now
                    : date('Y-m-d H:i:s', time() - mt_rand(3600, 172800)),
                'discovered'         => 1,
                'is_demo'            => 1,
                'created_at'         => date('Y-m-d H:i:s', time() - mt_rand(30, 380) * 86400),
                'updated_at'         => $now,
            ]);

            $result['sites']++;
            $result['checks'] += $this->createSiteChecks($siteId, $status, $httpStatus, $response, $error);

            if ($https) {
                $this->createCertificate($siteId, $domain);
                $result['certs']++;
            }
        }

        return $result;
    }

    /** Historico de verificacoes das ultimas 24 h, de hora em hora. */
    private function createSiteChecks(
        int $siteId,
        string $currentStatus,
        int $httpStatus,
        ?int $response,
        ?string $error
    ): int {
        $rows       = [];
        $previous   = null;
        $now        = time();

        for ($h = 24; $h >= 0; $h--) {
            $timestamp = $now - $h * 3600;

            // As ultimas 3 horas refletem o estado atual; antes disso o site
            // estava predominantemente no ar.
            if ($h <= 2) {
                $status = $currentStatus;
                $code   = $httpStatus === 0 ? null : $httpStatus;
                $time   = $response;
                $err    = $error;
            } else {
                $glitch = mt_rand(1, 100) <= 4;
                $status = $glitch ? Site::STATUS_OFFLINE : Site::STATUS_ONLINE;
                $code   = $glitch ? 502 : 200;
                $time   = $glitch ? mt_rand(3000, 9000) : mt_rand(80, 900);
                $err    = null;
            }

            $changed  = $previous !== null && $previous !== $status;
            $previous = $status;

            $rows[] = [$siteId, $status, $code, $time, $err, $changed ? 1 : 0, date('Y-m-d H:i:s', $timestamp)];
        }

        $placeholders = implode(',', array_fill(0, \count($rows), '(?,?,?,?,?,?,?)'));
        $bindings     = [];

        foreach ($rows as $row) {
            foreach ($row as $value) {
                $bindings[] = $value;
            }
        }

        Database::statement(
            'INSERT INTO site_checks (site_id, status, http_status, response_time, error, status_changed, created_at)
             VALUES ' . $placeholders,
            $bindings
        );

        return \count($rows);
    }

    /**
     * Certificado com distribuicao proposital: a maioria valida, alguns
     * vencendo e dois ou tres expirados - para que as quatro cores do
     * monitoramento SSL aparecam na tela.
     */
    private function createCertificate(int $siteId, string $domain): void
    {
        $roll = mt_rand(1, 100);

        $daysRemaining = match (true) {
            $roll <= 68 => mt_rand(45, 300),   // verde
            $roll <= 84 => mt_rand(8, 30),     // amarelo
            $roll <= 92 => mt_rand(1, 7),      // amarelo/vermelho (critico)
            $roll <= 97 => -mt_rand(1, 40),    // vermelho
            default     => null,               // cinza: nao verificado
        };

        if ($daysRemaining === null) {
            Database::insert('ssl_certificates', [
                'site_id'        => $siteId,
                'issuer'         => null,
                'subject'        => $domain,
                'valid_from'     => null,
                'valid_until'    => null,
                'days_remaining' => null,
                'status'         => 'unknown',
                'error'          => 'Nao foi possivel completar o handshake TLS',
                'checked_at'     => now_string(),
                'created_at'     => now_string(),
                'updated_at'     => now_string(),
            ]);

            return;
        }

        $validUntil = date('Y-m-d', strtotime("+{$daysRemaining} days"));
        $validFrom  = date('Y-m-d', strtotime($validUntil . ' -90 days'));

        Database::insert('ssl_certificates', [
            'site_id'        => $siteId,
            'issuer'         => self::SSL_ISSUERS[array_rand(self::SSL_ISSUERS)],
            'subject'        => $domain,
            'valid_from'     => $validFrom,
            'valid_until'    => $validUntil,
            'days_remaining' => $daysRemaining,
            // O status definitivo vem de SslService::refreshAll(), que roda em
            // seguida usando os limites configurados de verdade.
            'status'         => 'unknown',
            'error'          => null,
            'checked_at'     => now_string(),
            'created_at'     => now_string(),
            'updated_at'     => now_string(),
        ]);
    }

    // -----------------------------------------------------------------
    // Auxiliares
    // -----------------------------------------------------------------

    private function uniqueDomain(): string
    {
        do {
            $domain = self::WORDS_A[array_rand(self::WORDS_A)]
                . self::WORDS_B[array_rand(self::WORDS_B)]
                . (mt_rand(1, 100) <= 22 ? (string) mt_rand(2, 99) : '')
                . self::TLDS[array_rand(self::TLDS)];
        } while (\in_array($domain, $this->usedDomains, true));

        $this->usedDomains[] = $domain;

        return $domain;
    }

    /** Registra alguns eventos administrativos para a tela de Logs. */
    private function createAuditTrail(): void
    {
        $servers = Database::select('SELECT id, name FROM servers WHERE is_demo = 1 ORDER BY id ASC');
        $user    = Database::selectOne("SELECT id, name FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");

        if ($user === null) {
            return;
        }

        foreach ($servers as $index => $server) {
            Database::insert('audit_logs', [
                'user_id'     => (int) $user['id'],
                'actor'       => (string) $user['name'],
                'action'      => 'server.created',
                'entity_type' => 'server',
                'entity_id'   => (int) $server['id'],
                'description' => sprintf('Servidor "%s" cadastrado.', $server['name']),
                'level'       => 'info',
                'ip'          => '127.0.0.1',
                'user_agent'  => 'seeder',
                'context'     => json_encode(['origem' => 'seeder de demonstracao'], JSON_UNESCAPED_UNICODE),
                'created_at'  => date('Y-m-d H:i:s', time() - (30 - $index) * 86400),
            ]);
        }

        Database::insert('audit_logs', [
            'user_id'     => (int) $user['id'],
            'actor'       => (string) $user['name'],
            'action'      => 'seeder.demo',
            'entity_type' => null,
            'entity_id'   => null,
            'description' => 'Dados ficticios de demonstracao inseridos no banco.',
            'level'       => 'warning',
            'ip'          => '127.0.0.1',
            'user_agent'  => 'cli',
            'context'     => json_encode(['servidores' => \count($servers)], JSON_UNESCAPED_UNICODE),
            'created_at'  => now_string(),
        ]);
    }

    /**
     * "Rejuvenesce" os dados de demonstracao.
     *
     * ---------------------------------------------------------------------
     * POR QUE ISSO EXISTE
     * ---------------------------------------------------------------------
     * Os dados ficticios nascem com timestamps do momento da geracao. Algumas
     * horas depois, o cron - funcionando exatamente como deveria - marca todos
     * os servidores como offline por falta de heartbeat, e a demonstracao
     * passa a mostrar uma infraestrutura inteira caida.
     *
     * Em vez de gerar tudo de novo, deslocamos a SERIE INTEIRA no tempo: a
     * diferenca entre agora e a amostra mais recente e somada a cada linha.
     * A forma das curvas, o historico e as transicoes de estado sao
     * preservados; so o relogio anda.
     *
     * O servidor de perfil "offline" e mantido propositalmente atrasado, para
     * que continue exercitando a deteccao de servidor sem comunicacao.
     *
     * @return array<string,int|string>
     */
    public static function refresh(): array
    {
        $servers = Database::select('SELECT id, name FROM servers WHERE is_demo = 1');

        if ($servers === []) {
            return ['erro' => 'Nenhum servidor de demonstracao encontrado. Rode primeiro: db:seed'];
        }

        // Quais nomes correspondem ao perfil "offline" no roteiro do seeder.
        $offlineNames = [];
        foreach (self::SERVERS as $spec) {
            if ($spec['profile'] === 'offline') {
                $offlineNames[] = $spec['name'];
            }
        }

        $ids        = array_map(static fn (array $s): int => (int) $s['id'], $servers);
        $placeholders = implode(',', array_fill(0, \count($ids), '?'));

        // Delta = quanto tempo passou desde a amostra mais recente.
        $latest = Database::scalar(
            "SELECT MAX(created_at) FROM server_metrics WHERE server_id IN ($placeholders)",
            $ids
        );

        $delta = $latest === null ? 0 : max(0, time() - (int) strtotime((string) $latest));

        if ($delta > 0) {
            Database::statement(
                "UPDATE server_metrics SET created_at = DATE_ADD(created_at, INTERVAL ? SECOND)
                 WHERE server_id IN ($placeholders)",
                array_merge([$delta], $ids)
            );

            Database::statement(
                "UPDATE site_checks c
                 INNER JOIN sites s ON s.id = c.site_id
                 SET c.created_at = DATE_ADD(c.created_at, INTERVAL ? SECOND)
                 WHERE s.server_id IN ($placeholders)",
                array_merge([$delta], $ids)
            );
        }

        $now     = now_string();
        $stale   = date('Y-m-d H:i:s', time() - 8400); // ~2h20 sem comunicacao
        $updated = 0;

        foreach ($servers as $server) {
            $isOfflineProfile = \in_array((string) $server['name'], $offlineNames, true);
            $seenAt           = $isOfflineProfile ? $stale : $now;

            // Todos voltam a ONLINE aqui. Quem continua com last_seen_at
            // antigo sera rebaixado de novo pelo detectOfflineServers() logo
            // abaixo - assim a demonstracao passa pelo motor de verdade, em
            // vez de ter o status escrito na mao.
            Database::statement(
                'UPDATE servers
                 SET last_seen_at = ?, last_metric_at = ?, status = ?, updated_at = ?
                 WHERE id = ?',
                [$seenAt, $seenAt, Server::STATUS_ONLINE, $now, (int) $server['id']]
            );

            // Resolve o alerta de "servidor offline" que ficou aberto do
            // periodo em que a demonstracao estava envelhecida.
            if (!$isOfflineProfile) {
                AlertService::serverCameBack((int) $server['id'], (string) $server['name']);
            }

            $updated++;
        }

        Database::statement(
            "UPDATE sites SET last_check_at = ? WHERE server_id IN ($placeholders)",
            array_merge([$now], $ids)
        );

        Database::statement(
            "UPDATE server_services SET checked_at = ? WHERE server_id IN ($placeholders)",
            array_merge([$now], $ids)
        );

        // Certificados: recalcula os dias restantes a partir de hoje.
        SslService::refreshAll();

        // E o motor real reavalia tudo do zero.
        MonitoringService::detectOfflineServers();
        MonitoringService::evaluateResourceAlerts();
        MonitoringService::evaluateSiteAlerts();

        return [
            'servidores_atualizados' => $updated,
            'deslocamento_segundos'  => $delta,
            'alertas_em_aberto'      => (int) Database::scalar(
                "SELECT COUNT(*) FROM alerts WHERE status IN ('open','acknowledged')"
            ),
        ];
    }

    /**
     * Remove tudo que este seeder criou. As FKs em cascata levam junto
     * metricas, sites, checagens, certificados e alertas.
     */
    public static function remove(): array
    {
        $servers = (int) Database::scalar('SELECT COUNT(*) FROM servers WHERE is_demo = 1');

        Database::statement('DELETE FROM servers WHERE is_demo = 1');
        Database::statement('DELETE FROM sites WHERE is_demo = 1');
        Database::statement("DELETE FROM audit_logs WHERE user_agent = 'seeder' OR action = 'seeder.demo'");

        return ['servidores_removidos' => $servers];
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function jitter(float $amplitude): float
    {
        return (mt_rand(0, 2000) / 1000 - 1) * $amplitude;
    }

    private function say(string $line): void
    {
        ($this->output)($line);
    }
}
