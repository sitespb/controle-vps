<?php
/**
 * Pagina individual do servidor - secao 13 do PLAN.
 *
 * Blocos: Informacoes, Recursos (graficos), Servicos e Sites.
 *
 * @var array<string,mixed>            $server
 * @var array<string,mixed>|null       $metric
 * @var array<int,array<string,mixed>> $services
 * @var array<int,array<string,mixed>> $sites
 * @var array<int,array<string,mixed>> $alerts
 * @var array<int,array<string,mixed>> $series
 * @var int                            $hours
 * @var array<string,mixed>|null       $tokenInfo
 * @var array<int,array<string,mixed>> $auditTrail
 * @var array<string,mixed>|null       $currentUser
 */

use App\Core\View;
use App\Models\Alert;
use App\Services\SslService;

$isAdmin = ($currentUser['role'] ?? '') === 'admin';

$phpVersion = null;
$olsVersion = null;
foreach ($services as $service) {
    if ($service['name'] === 'php') {
        $phpVersion = $service['version'];
    }
    if ($service['name'] === 'openlitespeed') {
        $olsVersion = $service['version'];
    }
}

$ranges = [6 => '6 h', 24 => '24 h', 72 => '3 dias', 168 => '7 dias', 720 => '30 dias'];
?>

<!-- ======================================================================
     CABECALHO
     ====================================================================== -->
<div class="mb-6">
    <a href="<?= e(url('/servidores')) ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-3">
        <svg class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7" />
        </svg>
        Voltar para servidores
    </a>

    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 flex-wrap">
                <h2 class="text-2xl font-bold text-gray-900"><?= e($server['name']) ?></h2>
                <span class="px-2 py-1 inline-flex items-center gap-1.5 text-xs leading-5 font-semibold rounded-full <?= status_badge_class((string) $server['status']) ?>">
                    <span class="h-1.5 w-1.5 rounded-full <?= status_dot_class((string) $server['status']) ?>"></span>
                    <?= e(status_label((string) $server['status'])) ?>
                </span>
                <?php if ((int) $server['is_demo'] === 1) : ?>
                    <span class="px-2 py-0.5 bg-purple-100 text-purple-800 text-[10px] font-bold uppercase tracking-wider rounded">Dados de demonstracao</span>
                <?php endif; ?>
            </div>
            <p class="text-sm text-gray-600 mt-1">
                <?= e($server['provider'] ?? 'Sem provedor') ?>
                &middot; <?= e($server['hostname'] ?? 'sem hostname') ?>
                &middot; <span class="font-mono"><?= e($server['ip'] ?? '--') ?></span>
                &middot; ultima comunicacao <?= e(time_ago($server['last_seen_at'])) ?>
            </p>
        </div>

        <div class="flex items-center gap-2 flex-shrink-0">
            <a href="<?= e(url('/sites?servidor=' . $server['id'])) ?>"
               class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                <svg class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18" />
                </svg>
                <?= (int) $server['sites_count'] ?> site(s)
            </a>

            <?php if ($isAdmin) : ?>
                <a href="<?= e(url('/servidores/' . $server['id'] . '/agente')) ?>"
                   class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                    Agente
                </a>
                <a href="<?= e(url('/servidores/' . $server['id'] . '/editar')) ?>"
                   class="inline-flex items-center px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
                    Editar
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($server['status'] === 'offline') : ?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-md mb-6">
        <p class="text-sm text-red-700">
            Este servidor nao envia dados desde <?= e(format_datetime($server['last_seen_at'])) ?>.
            Os valores abaixo sao os da ultima coleta bem sucedida.
        </p>
    </div>
<?php endif; ?>

<!-- ======================================================================
     RECURSOS ATUAIS
     ====================================================================== -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <?php
    $gauges = [
        [
            'label'  => 'CPU',
            'value'  => $metric['cpu_usage'] ?? null,
            'metric' => 'cpu',
            'detail' => $server['cpu_cores'] === null ? null : $server['cpu_cores'] . ' vCPU',
        ],
        [
            'label'  => 'Memoria RAM',
            'value'  => $metric['ram_percent'] ?? null,
            'metric' => 'ram',
            'detail' => ($metric['ram_used'] ?? null) === null
                ? null
                : format_bytes((float) $metric['ram_used']) . ' de ' . format_bytes((float) $metric['ram_total']),
        ],
        [
            'label'  => 'Disco',
            'value'  => $metric['disk_percent'] ?? null,
            'metric' => 'disk',
            'detail' => ($metric['disk_used'] ?? null) === null
                ? null
                : format_bytes((float) $metric['disk_used']) . ' de ' . format_bytes((float) $metric['disk_total']),
        ],
        [
            'label'  => 'Swap',
            'value'  => $metric['swap_percent'] ?? null,
            'metric' => 'swap',
            'detail' => ($metric['swap_total'] ?? null) === null || (int) $metric['swap_total'] === 0
                ? 'sem swap'
                : format_bytes((float) $metric['swap_used']) . ' de ' . format_bytes((float) $metric['swap_total']),
        ],
    ];

    foreach ($gauges as $gauge) :
        $value = $gauge['value'] === null ? null : (float) $gauge['value'];
        $level = threshold_level($value, $gauge['metric']);
        ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider"><?= e($gauge['label']) ?></p>
            <p class="mt-2 text-2xl font-bold <?= level_text_class($level) ?>">
                <?= $value === null ? '--' : format_percent($value, 1) ?>
            </p>
            <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden mt-3">
                <div class="h-2 rounded-full <?= level_bar_class($level) ?>" style="width: <?= $value === null ? 0 : min(100, $value) ?>%"></div>
            </div>
            <p class="text-xs text-gray-500 mt-2 truncate"><?= e($gauge['detail'] ?? '--') ?></p>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">

    <!-- ==================================================================
         GRAFICOS
         ================================================================== -->
    <div class="xl:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Historico de recursos</h3>

            <div class="flex items-center gap-1 flex-wrap" id="range-buttons">
                <?php foreach ($ranges as $value => $label) : ?>
                    <button type="button"
                            data-range="<?= (int) $value ?>"
                            class="px-3 py-1.5 rounded-lg border text-sm transition-colors <?= $hours === $value
                                ? 'bg-primary text-white border-primary'
                                : 'border-gray-300 text-gray-700 hover:bg-gray-50' ?>">
                        <?= e($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($series === []) : ?>
            <div class="py-16 text-center">
                <p class="text-gray-500 text-sm">Nenhuma metrica coletada neste periodo.</p>
                <p class="text-gray-400 text-xs mt-1">Os graficos aparecem apos a primeira execucao do agente.</p>
            </div>
        <?php else : ?>
            <div class="h-64"><canvas id="chart-resources"></canvas></div>

            <h4 class="text-base font-semibold text-gray-900 mt-8 mb-4">Load average</h4>
            <div class="h-40"><canvas id="chart-load"></canvas></div>

            <?php if ($metric !== null) : ?>
                <div class="grid grid-cols-3 gap-4 mt-6 pt-5 border-t border-gray-200 text-center">
                    <?php foreach ([['1 min', 'load_1'], ['5 min', 'load_5'], ['15 min', 'load_15']] as [$loadLabel, $loadKey]) : ?>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= e($loadLabel) ?></p>
                            <p class="text-lg font-bold text-gray-900">
                                <?= $metric[$loadKey] === null ? '--' : number_format((float) $metric[$loadKey], 2, ',', '.') ?>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ==================================================================
         INFORMACOES + SERVICOS
         ================================================================== -->
    <div class="space-y-6">

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Informacoes</h3>

            <dl class="space-y-3">
                <?php
                $info = [
                    'Provedor'    => $server['provider'] ?? null,
                    'Hostname'    => $server['hostname'] ?? null,
                    'IP'          => $server['ip'] ?? null,
                    'IP publico'  => $server['public_ip'] ?? null,
                    'Sistema'     => trim((string) ($server['os_name'] ?? '') . ' ' . (string) ($server['os_version'] ?? '')),
                    'Kernel'      => $server['kernel'] ?? null,
                    'Arquitetura' => $server['arch'] ?? null,
                    'CPU'         => $server['cpu_model'] ?? null,
                    'PHP'         => $phpVersion,
                    'OpenLiteSpeed' => $olsVersion,
                    'CyberPanel'  => $server['cyberpanel_version'] ?? null,
                    'Uptime'      => $server['uptime'] === null ? null : format_uptime((int) $server['uptime']),
                    'Agente'      => $server['agent_version'] === null ? null : 'v' . $server['agent_version'],
                    'Ultima coleta' => $server['last_metric_at'] === null ? null : format_datetime($server['last_metric_at']),
                ];

                foreach ($info as $label => $value) :
                    ?>
                    <div class="flex justify-between gap-4 text-sm">
                        <dt class="text-gray-500 flex-shrink-0"><?= e($label) ?></dt>
                        <dd class="text-gray-900 text-right truncate" title="<?= e($value ?? '') ?>">
                            <?= ($value === null || $value === '') ? '<span class="text-gray-400">--</span>' : e(str_limit((string) $value, 28)) ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>

            <?php if ($server['description'] !== null && $server['description'] !== '') : ?>
                <div class="mt-5 pt-4 border-t border-gray-200">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Descricao</p>
                    <p class="text-sm text-gray-700 leading-relaxed"><?= nl2br(e($server['description'])) ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Servicos -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Servicos</h3>

            <?php if ($services === []) : ?>
                <p class="text-sm text-gray-500 py-4 text-center">
                    O agente ainda nao reportou os servicos deste servidor.
                </p>
            <?php else : ?>
                <ul class="space-y-3">
                    <?php foreach ($services as $service) : ?>
                        <li class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm text-gray-900 truncate"><?= e($service['label'] ?? ucfirst((string) $service['name'])) ?></p>
                                <?php if (!empty($service['version'])) : ?>
                                    <p class="text-xs text-gray-500">v<?= e($service['version']) ?></p>
                                <?php elseif (!empty($service['detail'])) : ?>
                                    <p class="text-xs text-gray-400"><?= e($service['detail']) ?></p>
                                <?php endif; ?>
                            </div>

                            <?php
                            [$dot, $text] = match ((string) $service['status']) {
                                'running'       => ['bg-green-500', 'Ativo'],
                                'stopped'       => ['bg-red-500', 'Parado'],
                                'not_installed' => ['bg-gray-300', 'Nao instalado'],
                                default         => ['bg-gray-300', 'Desconhecido'],
                            };
                            ?>
                            <span class="flex items-center gap-2 flex-shrink-0">
                                <span class="text-xs text-gray-500"><?= e($text) ?></span>
                                <span class="h-2.5 w-2.5 rounded-full <?= $dot ?>"></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <p class="text-xs text-gray-400 mt-4 pt-3 border-t border-gray-200 leading-relaxed">
                    Um servico ausente nao e erro: cada VPS pode ter uma configuracao diferente.
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ======================================================================
     ALERTAS DESTE SERVIDOR
     ====================================================================== -->
<?php if ($alerts !== []) : ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Alertas</h3>
        </div>
        <ul class="divide-y divide-gray-200">
            <?php foreach ($alerts as $alert) : ?>
                <li>
                    <a href="<?= e(url('/alertas/' . $alert['id'])) ?>" class="flex items-center gap-3 px-6 py-3.5 hover:bg-gray-50 transition-colors">
                        <span class="h-2 w-2 rounded-full flex-shrink-0 <?= $alert['status'] === 'resolved'
                            ? 'bg-gray-300'
                            : ($alert['severity'] === 'critical' ? 'bg-red-500' : 'bg-yellow-400') ?>"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900 truncate"><?= e($alert['title']) ?></p>
                            <p class="text-xs text-gray-500 mt-0.5">
                                <?= e(Alert::typeLabel((string) $alert['type'])) ?> &middot; <?= e(time_ago($alert['last_seen_at'])) ?>
                            </p>
                        </div>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full flex-shrink-0 <?= status_badge_class((string) $alert['status']) ?>">
                            <?= e(status_label((string) $alert['status'])) ?>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ======================================================================
     SITES DESTE SERVIDOR
     ====================================================================== -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">Sites hospedados</h3>
            <p class="text-xs text-gray-500 mt-0.5">
                <?= (int) $server['sites_count'] ?> descoberto(s) &middot; <?= (int) $server['sites_online'] ?> online
            </p>
        </div>
        <a href="<?= e(url('/sites?servidor=' . $server['id'])) ?>" class="text-sm text-gray-500 hover:text-gray-700">Ver na pagina de sites</a>
    </div>

    <?php if ($sites === []) : ?>
        <p class="px-6 py-12 text-center text-gray-500 text-sm">
            Nenhum site descoberto ainda. O agente envia a lista de dominios a cada coleta.
        </p>
    <?php else : ?>
        <div class="overflow-x-auto scrollbar-thin">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dominio</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">HTTP</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">SSL</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">PHP</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Resposta</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($sites as $site) : ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <a href="<?= e(url('/sites/' . $site['id'])) ?>" class="text-sm text-gray-900 hover:text-primary">
                                    <?= e($site['domain']) ?>
                                </a>
                                <?php if ((int) $site['wordpress_detected'] === 1) : ?>
                                    <span class="ml-2 px-1.5 py-0.5 bg-blue-100 text-blue-800 text-[10px] font-bold uppercase tracking-wider rounded">WP</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= status_badge_class((string) $site['status']) ?>">
                                    <?= e(status_label((string) $site['status'])) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?= $site['http_status'] === null ? '--' : (int) $site['http_status'] ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= SslService::badgeClass($site['ssl_status'] ?? null) ?>">
                                    <?= e(SslService::label(
                                        $site['ssl_status'] ?? null,
                                        $site['ssl_days_remaining'] === null ? null : (int) $site['ssl_days_remaining']
                                    )) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= e($site['php_version'] ?? '--') ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?= $site['response_time'] === null ? '--' : number_format((int) $site['response_time'], 0, ',', '.') . ' ms' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
if ($series !== []) {
    $chartPayload = [
        'labels' => [],
        'cpu'    => [],
        'ram'    => [],
        'disk'   => [],
        'load'   => [],
    ];

    $format = $hours > 48 ? 'd/m H:i' : 'H:i';

    foreach ($series as $point) {
        $chartPayload['labels'][] = date($format, (int) strtotime((string) $point['created_at']));
        $chartPayload['cpu'][]    = $point['cpu_usage'] === null ? null : (float) $point['cpu_usage'];
        $chartPayload['ram'][]    = $point['ram_percent'] === null ? null : (float) $point['ram_percent'];
        $chartPayload['disk'][]   = $point['disk_percent'] === null ? null : (float) $point['disk_percent'];
        $chartPayload['load'][]   = $point['load_1'] === null ? null : (float) $point['load_1'];
    }

    $json     = json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $serverId = (int) $server['id'];

    // Chart.js e vendorizado localmente; charts.js precisa vir depois dele.
    $chartJs  = e(asset('vendor/chart.umd.min.js'));
    $chartsJs = e(asset('js/charts.js'));

    View::pushScript(<<<HTML
<script src="{$chartJs}"></script>
<script src="{$chartsJs}"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const initial = {$json};
    window.ControleVPSCharts.serverResources('chart-resources', 'chart-load', initial);

    document.querySelectorAll('#range-buttons [data-range]').forEach((button) => {
        button.addEventListener('click', async () => {
            const hours = button.getAttribute('data-range');

            document.querySelectorAll('#range-buttons [data-range]').forEach((other) => {
                other.className = 'px-3 py-1.5 rounded-lg border text-sm transition-colors border-gray-300 text-gray-700 hover:bg-gray-50';
            });
            button.className = 'px-3 py-1.5 rounded-lg border text-sm transition-colors bg-primary text-white border-primary';

            try {
                await window.ControleVPSCharts.loadServerSeries({$serverId}, hours, 'chart-resources', 'chart-load');
            } catch (e) {
                console.error('Falha ao carregar a serie:', e.message);
            }
        });
    });
});
</script>
HTML);
}
?>
