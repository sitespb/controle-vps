<?php
/**
 * Metricas - visao consolidada e graficos (secoes 14 e 20 do PLAN).
 *
 * @var array<string,mixed>            $summary
 * @var array<int,array<string,mixed>> $servers
 * @var int                            $selectedServer
 * @var array<int,array<string,mixed>> $series
 * @var int                            $hours
 * @var array<string,mixed>            $alertTrend
 * @var array<int,array{version:string,total:int}> $phpDistribution
 * @var array<string,int>              $sslSummary
 * @var array<string,int>              $volume
 */

use App\Core\Config;
use App\Core\View;

$ranges = [6 => '6 h', 24 => '24 h', 72 => '3 dias', 168 => '7 dias', 720 => '30 dias'];
$usage  = $summary['usage'];

$selected = null;
foreach ($servers as $server) {
    if ((int) $server['id'] === $selectedServer) {
        $selected = $server;
        break;
    }
}
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-900">Métricas</h2>
    <p class="text-sm text-gray-600 mt-1">
        Histórico coletado a cada <?= (int) round((int) Config::get('monitoring.agent_interval', 300) / 60) ?> minuto(s),
        mantido por <?= (int) Config::get('monitoring.retention.metrics', 30) ?> dias.
    </p>
</div>

<!-- Medias da infraestrutura -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <?php
    $meters = [
        ['label' => 'CPU média',   'data' => $usage['cpu']],
        ['label' => 'RAM média',   'data' => $usage['ram']],
        ['label' => 'Disco medio', 'data' => $usage['disk']],
    ];

    foreach ($meters as $meter) :
        $value = $meter['data']['value'];
        $level = $meter['data']['level'];
        ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider"><?= e($meter['label']) ?></p>
            <p class="mt-2 text-2xl font-bold <?= level_text_class($level) ?>">
                <?= $value === null ? '--' : format_percent((float) $value, 1) ?>
            </p>
            <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden mt-3">
                <div class="h-2 rounded-full <?= level_bar_class($level) ?>" style="width: <?= $value === null ? 0 : min(100, (float) $value) ?>%"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ======================================================================
     HISTORICO POR SERVIDOR
     ====================================================================== -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-5">
        <h3 class="text-lg font-semibold text-gray-900">Histórico por servidor</h3>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
            <form method="GET" action="<?= e(url('/metricas')) ?>" class="flex items-center gap-2">
                <input type="hidden" name="horas" value="<?= (int) $hours ?>">
                <select name="servidor" data-auto-submit
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary focus:border-primary">
                    <?php foreach ($servers as $server) : ?>
                        <option value="<?= (int) $server['id'] ?>" <?= (int) $server['id'] === $selectedServer ? 'selected' : '' ?>>
                            <?= e($server['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="flex items-center gap-1 flex-wrap" id="range-buttons">
                <?php foreach ($ranges as $value => $label) : ?>
                    <button type="button" data-range="<?= (int) $value ?>"
                            class="px-3 py-1.5 rounded-lg border text-sm transition-colors <?= $hours === $value
                                ? 'bg-primary text-white border-primary'
                                : 'border-gray-300 text-gray-700 hover:bg-gray-50' ?>">
                        <?= e($label) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($servers === []) : ?>
        <p class="py-16 text-center text-gray-500 text-sm">Nenhum servidor cadastrado.</p>
    <?php elseif ($series === []) : ?>
        <p class="py-16 text-center text-gray-500 text-sm">Nenhuma métrica coletada neste período.</p>
    <?php else : ?>
        <?php if ($selected !== null) : ?>
            <div class="flex items-center gap-2 mb-4">
                <span class="h-2 w-2 rounded-full <?= status_dot_class((string) $selected['status']) ?>"></span>
                <span class="text-sm text-gray-600">
                    <?= e($selected['name']) ?> &middot; <?= e($selected['provider'] ?? 'sem provedor') ?>
                    &middot; última comunicação <?= e(time_ago($selected['last_seen_at'])) ?>
                </span>
            </div>
        <?php endif; ?>

        <div class="h-72"><canvas id="chart-resources"></canvas></div>

        <h4 class="text-base font-semibold text-gray-900 mt-8 mb-4">Load average</h4>
        <div class="h-40"><canvas id="chart-load"></canvas></div>
    <?php endif; ?>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">

    <!-- Tendencia de alertas -->
    <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Alertas por dia</h3>
        <div class="h-64"><canvas id="chart-alerts"></canvas></div>
    </div>

    <!-- Situacao dos certificados -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Certificados SSL</h3>
        <?php if (array_sum($sslSummary) === 0) : ?>
            <p class="py-16 text-center text-gray-500 text-sm">Nenhum certificado registrado.</p>
        <?php else : ?>
            <div class="h-64"><canvas id="chart-ssl"></canvas></div>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Distribuicao de PHP -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Versões de PHP nos sites</h3>

        <?php if ($phpDistribution === []) : ?>
            <p class="py-12 text-center text-gray-500 text-sm">Nenhum site descoberto ainda.</p>
        <?php else : ?>
            <?php $totalSites = array_sum(array_column($phpDistribution, 'total')); ?>
            <div class="space-y-3">
                <?php foreach ($phpDistribution as $row) : ?>
                    <?php $percent = $totalSites === 0 ? 0 : ($row['total'] / $totalSites) * 100; ?>
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm text-gray-700">PHP <?= e($row['version']) ?></span>
                            <span class="text-sm text-gray-500">
                                <?= number_format($row['total'], 0, ',', '.') ?>
                                <span class="text-xs text-gray-400">(<?= number_format($percent, 1, ',', '.') ?>%)</span>
                            </span>
                        </div>
                        <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-2 rounded-full bg-gray-400" style="width: <?= $percent ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Volume de dados armazenado -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-1">Volume armazenado</h3>
        <p class="text-xs text-gray-500 mb-4">
            A limpeza automática (cron/cleanup.php) mantem estes números sob controle.
        </p>

        <dl class="space-y-3">
            <?php
            $volumeLabels = [
                'server_metrics' => 'Amostras de métricas',
                'site_checks'    => 'Verificações de site',
                'alerts'         => 'Alertas',
                'audit_logs'     => 'Registros de auditoria',
            ];

            foreach ($volumeLabels as $key => $label) :
                ?>
                <div class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                    <dt class="text-sm text-gray-600"><?= e($label) ?></dt>
                    <dd class="text-sm font-semibold text-gray-900"><?= number_format((int) ($volume[$key] ?? 0), 0, ',', '.') ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>

        <div class="mt-5 bg-blue-50 border-l-4 border-blue-500 p-4 rounded-md">
            <p class="text-sm text-blue-700 leading-relaxed">
                Retenção atual: métricas <?= (int) Config::get('monitoring.retention.metrics', 30) ?> dias,
                verificações <?= (int) Config::get('monitoring.retention.site_checks', 30) ?> dias.
                Ajuste em <a href="<?= e(url('/configuracoes')) ?>" class="underline font-medium">Configurações</a>.
            </p>
        </div>
    </div>
</div>

<?php
$chartJs  = e(asset('vendor/chart.umd.min.js'));
$chartsJs = e(asset('js/charts.js'));

$scripts = [];

if ($series !== []) {
    $payload = ['labels' => [], 'cpu' => [], 'ram' => [], 'disk' => [], 'load' => []];
    $format  = $hours > 48 ? 'd/m H:i' : 'H:i';

    foreach ($series as $point) {
        $payload['labels'][] = date($format, (int) strtotime((string) $point['created_at']));
        $payload['cpu'][]    = $point['cpu_usage'] === null ? null : (float) $point['cpu_usage'];
        $payload['ram'][]    = $point['ram_percent'] === null ? null : (float) $point['ram_percent'];
        $payload['disk'][]   = $point['disk_percent'] === null ? null : (float) $point['disk_percent'];
        $payload['load'][]   = $point['load_1'] === null ? null : (float) $point['load_1'];
    }

    $seriesJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $scripts[]  = "window.ControleVPSCharts.serverResources('chart-resources', 'chart-load', {$seriesJson});";
    $scripts[]  = <<<JS
    document.querySelectorAll('#range-buttons [data-range]').forEach((button) => {
        button.addEventListener('click', async () => {
            const hours = button.getAttribute('data-range');

            document.querySelectorAll('#range-buttons [data-range]').forEach((other) => {
                other.className = 'px-3 py-1.5 rounded-lg border text-sm transition-colors border-gray-300 text-gray-700 hover:bg-gray-50';
            });
            button.className = 'px-3 py-1.5 rounded-lg border text-sm transition-colors bg-primary text-white border-primary';

            document.querySelectorAll('input[name="horas"]').forEach((input) => { input.value = hours; });

            try {
                await window.ControleVPSCharts.loadServerSeries({$selectedServer}, hours, 'chart-resources', 'chart-load');
            } catch (e) {
                console.error('Falha ao carregar a serie:', e.message);
            }
        });
    });
JS;
}

$trendJson = json_encode($alertTrend, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$scripts[] = "window.ControleVPSCharts.alertTrend('chart-alerts', {$trendJson});";

if (array_sum($sslSummary) > 0) {
    $sslLabels = json_encode(['Valido', 'A vencer', 'Expirado', 'Não verificado', 'Sem SSL'], JSON_UNESCAPED_UNICODE);
    $sslValues = json_encode(array_values([
        $sslSummary['valid'],
        $sslSummary['expiring'],
        $sslSummary['expired'],
        $sslSummary['unknown'],
        $sslSummary['none'],
    ]));
    // Cores semanticas: aqui verde/amarelo/vermelho SAO o significado.
    $sslColors = json_encode(['#22c55e', '#facc15', '#ef4444', '#d1d5db', '#9ca3af']);

    $scripts[] = "window.ControleVPSCharts.distribution('chart-ssl', {$sslLabels}, {$sslValues}, {$sslColors});";
}

$body = implode("\n    ", $scripts);

View::pushScript(<<<HTML
<script src="{$chartJs}"></script>
<script src="{$chartsJs}"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    {$body}
});
</script>
HTML);
?>
