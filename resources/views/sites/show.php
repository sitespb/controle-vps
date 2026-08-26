<?php
/**
 * Pagina individual do site - secoes 15, 16 e 17 do PLAN.
 *
 * @var array<string,mixed>            $site
 * @var string                         $httpLabel
 * @var string                         $httpExplain
 * @var array<int,array<string,mixed>> $checks
 * @var array<int,array<string,mixed>> $changes
 * @var array<int,array<string,mixed>> $series
 * @var float|null                     $uptime24h
 * @var float|null                     $uptime7d
 * @var array<int,array<string,mixed>> $alerts
 * @var int                            $hours
 */

use App\Core\View;
use App\Models\Alert;
use App\Services\SslService;

$isOnline  = $site['status'] === 'online';
$isOffline = $site['status'] === 'offline';
$sslDays   = $site['ssl_days_remaining'] === null ? null : (int) $site['ssl_days_remaining'];
$ranges    = [6 => '6 h', 24 => '24 h', 72 => '3 dias', 168 => '7 dias'];
?>

<div class="mb-6">
    <a href="<?= e(url('/sites')) ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-3">
        <svg class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7" />
        </svg>
        Voltar para sites
    </a>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="min-w-0">
            <div class="flex items-center gap-3 flex-wrap">
                <h2 class="text-2xl font-bold text-gray-900 break-all"><?= e($site['domain']) ?></h2>
                <?php if ((int) $site['is_demo'] === 1) : ?>
                    <span class="px-2 py-0.5 bg-purple-100 text-purple-800 text-[10px] font-bold uppercase tracking-wider rounded">Demo</span>
                <?php endif; ?>
            </div>
            <p class="text-sm text-gray-600 mt-1">
                Hospedado em
                <a href="<?= e(url('/servidores/' . $site['server_id'])) ?>" class="text-gray-900 hover:text-primary font-medium"><?= e($site['server_name']) ?></a>
                &middot; verificado <?= e(time_ago($site['last_check_at'])) ?>
            </p>
        </div>

        <?php if ($site['url'] !== null) : ?>
            <a href="<?= e($site['url']) ?>" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors flex-shrink-0">
                <svg class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                    <path d="M15 3h6v6M10 14L21 3" />
                </svg>
                Abrir site
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- ======================================================================
     STATUS PRINCIPAL
     ====================================================================== -->
<div class="bg-white rounded-xl shadow-sm border <?= $isOffline ? 'border-red-200' : 'border-gray-200' ?> p-6 mb-6">
    <div class="flex items-center gap-4 mb-6">
        <span class="h-4 w-4 rounded-full flex-shrink-0 <?= status_dot_class((string) $site['status']) ?>"></span>
        <div>
            <p class="text-2xl font-bold <?= $isOffline ? 'text-red-700' : ($isOnline ? 'text-gray-900' : 'text-yellow-800') ?>">
                <?= e(mb_strtoupper(status_label((string) $site['status']))) ?>
            </p>
            <p class="text-sm text-gray-600 mt-0.5"><?= e($httpExplain) ?></p>
        </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-6">
        <?php
        $facts = [
            ['label' => 'HTTP',              'value' => $httpLabel],
            ['label' => 'Tempo de resposta', 'value' => $site['response_time'] === null ? '--' : number_format((int) $site['response_time'], 0, ',', '.') . ' ms'],
            ['label' => 'HTTPS',             'value' => (int) $site['https_available'] === 1 ? 'Disponivel' : 'Nao disponivel'],
            ['label' => 'PHP',               'value' => $site['php_version'] ?? '--'],
            ['label' => 'WordPress',         'value' => (int) $site['wordpress_detected'] === 1 ? ($site['wordpress_version'] ?? 'Detectado') : 'Nao detectado'],
            ['label' => 'IP',                'value' => $site['ip'] ?? '--'],
        ];

        foreach ($facts as $fact) :
            ?>
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= e($fact['label']) ?></p>
                <p class="text-sm font-semibold text-gray-900 mt-1 truncate" title="<?= e($fact['value']) ?>"><?= e($fact['value']) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($site['last_error'] !== null && $site['last_error'] !== '') : ?>
        <div class="mt-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-md">
            <p class="text-[10px] font-bold uppercase tracking-wider text-red-700 mb-1">Ultima resposta</p>
            <p class="text-sm text-red-700 font-mono break-all"><?= e($site['last_error']) ?></p>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">

    <!-- ==================================================================
         GRAFICO DE RESPOSTA + DISPONIBILIDADE
         ================================================================== -->
    <div class="xl:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Tempo de resposta</h3>

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

        <?php if ($series === []) : ?>
            <p class="py-16 text-center text-gray-500 text-sm">Nenhuma verificacao registrada neste periodo.</p>
        <?php else : ?>
            <div class="h-56"><canvas id="chart-response"></canvas></div>
        <?php endif; ?>

        <div class="grid grid-cols-2 gap-4 mt-6 pt-5 border-t border-gray-200">
            <div class="text-center">
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Disponibilidade 24 h</p>
                <p class="text-xl font-bold mt-1 <?= $uptime24h === null ? 'text-gray-400' : ($uptime24h >= 99 ? 'text-green-700' : ($uptime24h >= 95 ? 'text-yellow-800' : 'text-red-700')) ?>">
                    <?= $uptime24h === null ? '--' : format_percent($uptime24h, 1) ?>
                </p>
            </div>
            <div class="text-center">
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Disponibilidade 7 dias</p>
                <p class="text-xl font-bold mt-1 <?= $uptime7d === null ? 'text-gray-400' : ($uptime7d >= 99 ? 'text-green-700' : ($uptime7d >= 95 ? 'text-yellow-800' : 'text-red-700')) ?>">
                    <?= $uptime7d === null ? '--' : format_percent($uptime7d, 1) ?>
                </p>
            </div>
        </div>
    </div>

    <!-- ==================================================================
         CERTIFICADO SSL - secao 16
         ================================================================== -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Certificado SSL</h3>
            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= SslService::badgeClass($site['ssl_status'] ?? null) ?>">
                <?= e(SslService::label($site['ssl_status'] ?? null, $sslDays)) ?>
            </span>
        </div>

        <?php if ($site['ssl_status'] === null) : ?>
            <p class="text-sm text-gray-500 py-6 text-center">
                Nenhum certificado registrado para este dominio.
            </p>
        <?php else : ?>
            <?php if ($sslDays !== null) : ?>
                <?php
                // Barra proporcional considerando um ciclo tipico de 90 dias
                // (Let's Encrypt). Serve como leitura rapida, nao como calculo
                // exato de validade.
                $percent = max(0, min(100, ($sslDays / 90) * 100));
                $barClass = $sslDays < 0
                    ? 'bg-red-500'
                    : ($sslDays <= 30 ? 'bg-yellow-400' : 'bg-green-500');
                ?>
                <div class="mb-5">
                    <div class="flex items-baseline justify-between mb-2">
                        <span class="text-2xl font-bold <?= $sslDays < 0 ? 'text-red-700' : ($sslDays <= 30 ? 'text-yellow-800' : 'text-gray-900') ?>">
                            <?= $sslDays < 0 ? abs($sslDays) : $sslDays ?>
                        </span>
                        <span class="text-sm text-gray-500">
                            <?= $sslDays < 0 ? 'dia(s) desde a expiracao' : 'dia(s) restante(s)' ?>
                        </span>
                    </div>
                    <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-2 rounded-full <?= $barClass ?>" style="width: <?= $sslDays < 0 ? 100 : $percent ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>

            <dl class="space-y-3">
                <?php
                $sslFacts = [
                    'Emissor'         => $site['ssl_issuer'] ?? null,
                    'Emitido para'    => $site['ssl_subject'] ?? null,
                    'Data de emissao' => $site['ssl_valid_from'] === null ? null : format_date($site['ssl_valid_from']),
                    'Data de expiracao' => $site['ssl_valid_until'] === null ? null : format_date($site['ssl_valid_until']),
                    'Verificado em'   => $site['ssl_checked_at'] === null ? null : format_datetime($site['ssl_checked_at']),
                ];

                foreach ($sslFacts as $label => $value) :
                    ?>
                    <div class="flex justify-between gap-4 text-sm">
                        <dt class="text-gray-500 flex-shrink-0"><?= e($label) ?></dt>
                        <dd class="text-gray-900 text-right truncate" title="<?= e($value ?? '') ?>">
                            <?= ($value === null || $value === '') ? '<span class="text-gray-400">--</span>' : e(str_limit((string) $value, 24)) ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>

            <?php if (!empty($site['ssl_error'])) : ?>
                <div class="mt-4 bg-gray-50 border border-gray-200 rounded-lg p-3">
                    <p class="text-xs text-gray-600 break-all"><?= e($site['ssl_error']) ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- ==================================================================
         MUDANCAS DE ESTADO - secao 29
         ================================================================== -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Mudancas de estado</h3>
            <p class="text-xs text-gray-500 mt-0.5">Somente as transicoes registradas, nao cada verificacao.</p>
        </div>

        <?php if ($changes === []) : ?>
            <p class="px-6 py-12 text-center text-gray-500 text-sm">Nenhuma mudanca de estado registrada.</p>
        <?php else : ?>
            <ul class="divide-y divide-gray-200">
                <?php foreach ($changes as $change) : ?>
                    <li class="flex items-center gap-3 px-6 py-3">
                        <span class="h-2 w-2 rounded-full flex-shrink-0 <?= status_dot_class((string) $change['status']) ?>"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-900">
                                Passou para <span class="font-medium"><?= e(status_label((string) $change['status'])) ?></span>
                            </p>
                            <p class="text-xs text-gray-500 mt-0.5">
                                <?= e(format_datetime($change['created_at'])) ?>
                                <?php if ($change['http_status'] !== null) : ?>
                                    &middot; HTTP <?= (int) $change['http_status'] ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- ==================================================================
         ULTIMAS VERIFICACOES
         ================================================================== -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Ultimas verificacoes</h3>
        </div>

        <?php if ($checks === []) : ?>
            <p class="px-6 py-12 text-center text-gray-500 text-sm">Nenhuma verificacao registrada.</p>
        <?php else : ?>
            <div class="max-h-80 overflow-y-auto scrollbar-thin">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-6 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quando</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">HTTP</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Resposta</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($checks as $check) : ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-2.5 whitespace-nowrap text-sm text-gray-500"><?= e(format_datetime($check['created_at'], 'd/m H:i')) ?></td>
                                <td class="px-4 py-2.5 whitespace-nowrap">
                                    <span class="px-2 py-0.5 inline-flex text-xs font-semibold rounded-full <?= status_badge_class((string) $check['status']) ?>">
                                        <?= e(status_label((string) $check['status'])) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-sm text-gray-500"><?= $check['http_status'] === null ? '--' : (int) $check['http_status'] ?></td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-sm text-gray-500">
                                    <?= $check['response_time'] === null ? '--' : number_format((int) $check['response_time'], 0, ',', '.') . ' ms' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ======================================================================
     ALERTAS DO SITE
     ====================================================================== -->
<?php if ($alerts !== []) : ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mt-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">Alertas deste site</h3>
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
                                <?= e(Alert::typeLabel((string) $alert['type'])) ?>
                                &middot; <?= e(time_ago($alert['last_seen_at'])) ?>
                                <?php if ((int) $alert['occurrences'] > 1) : ?>
                                    &middot; <?= (int) $alert['occurrences'] ?> ocorrencia(s)
                                <?php endif; ?>
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

<?php
if ($series !== []) {
    $payload = ['labels' => [], 'response' => []];
    $format  = $hours > 48 ? 'd/m H:i' : 'H:i';

    foreach ($series as $point) {
        $payload['labels'][]   = date($format, (int) strtotime((string) $point['created_at']));
        $payload['response'][] = $point['response_time'] === null ? null : (int) $point['response_time'];
    }

    $json     = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $siteId   = (int) $site['id'];
    $chartJs  = e(asset('vendor/chart.umd.min.js'));
    $chartsJs = e(asset('js/charts.js'));

    View::pushScript(<<<HTML
<script src="{$chartJs}"></script>
<script src="{$chartsJs}"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    window.ControleVPSCharts.siteResponse('chart-response', {$json});

    document.querySelectorAll('#range-buttons [data-range]').forEach((button) => {
        button.addEventListener('click', async () => {
            const hours = button.getAttribute('data-range');

            document.querySelectorAll('#range-buttons [data-range]').forEach((other) => {
                other.className = 'px-3 py-1.5 rounded-lg border text-sm transition-colors border-gray-300 text-gray-700 hover:bg-gray-50';
            });
            button.className = 'px-3 py-1.5 rounded-lg border text-sm transition-colors bg-primary text-white border-primary';

            try {
                await window.ControleVPSCharts.loadSiteSeries({$siteId}, hours, 'chart-response');
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
