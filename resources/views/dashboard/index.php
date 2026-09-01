<?php
/**
 * Dashboard - secao 10 do PLAN.
 *
 * @var array<string,mixed>          $summary
 * @var array<int,array<string,mixed>> $servers
 * @var array<int,array<string,mixed>> $openAlerts
 * @var array<int,array<string,mixed>> $sitesOffline
 * @var array<int,array<string,mixed>> $sslExpiring
 * @var int                          $wordpressCount
 */

use App\Core\View;
use App\Models\Alert;

$srv   = $summary['servers'];
$sit   = $summary['sites'];
$alr   = $summary['alerts'];
$usage = $summary['usage'];
$ssl   = $summary['ssl'];
?>

<!-- ======================================================================
     CARDS DE VISAO GERAL
     ====================================================================== -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7 gap-4 mb-6">

    <?php
    $cards = [
        [
            'key' => 'servers.total', 'label' => 'Servidores',
            'value' => $srv['total'],
            'tone'  => 'gray',
            'href'  => '/servidores',
            'icon'  => '<rect x="2" y="4" width="20" height="7" rx="2"/><rect x="2" y="13" width="20" height="7" rx="2"/><line x1="6" y1="7.5" x2="6.01" y2="7.5"/><line x1="6" y1="16.5" x2="6.01" y2="16.5"/>',
        ],
        [
            'key' => 'servers.online', 'label' => 'Online',
            'value' => $srv['online'],
            'tone'  => 'green',
            'href'  => '/servidores?status=online',
            'icon'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>',
        ],
        [
            'key' => 'servers.offline', 'label' => 'Offline',
            'value' => $srv['offline'],
            'tone'  => $srv['offline'] > 0 ? 'red' : 'gray',
            'href'  => '/servidores?status=offline',
            'icon'  => '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
        ],
        [
            'key' => 'sites.total', 'label' => 'Sites',
            'value' => $sit['total'],
            'tone'  => 'gray',
            'href'  => '/sites',
            'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18"/>',
        ],
        [
            'key' => 'sites.online', 'label' => 'Sites online',
            'value' => $sit['online'],
            'tone'  => 'green',
            'href'  => '/sites?status=online',
            'icon'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/>',
        ],
        [
            'key' => 'sites.offline', 'label' => 'Sites offline',
            'value' => $sit['offline'],
            'tone'  => $sit['offline'] > 0 ? 'red' : 'gray',
            'href'  => '/sites?status=offline',
            'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
        ],
        [
            'key' => 'alerts.total', 'label' => 'Alertas',
            'value' => $alr['total'],
            'tone'  => $alr['critical'] > 0 ? 'red' : ($alr['total'] > 0 ? 'yellow' : 'gray'),
            'href'  => '/alertas',
            'icon'  => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        ],
    ];

    $tones = [
        'gray'   => ['bg' => 'bg-gray-100',  'text' => 'text-gray-600',   'value' => 'text-gray-900'],
        'green'  => ['bg' => 'bg-green-50',  'text' => 'text-green-600',  'value' => 'text-gray-900'],
        'red'    => ['bg' => 'bg-red-50',    'text' => 'text-red-600',    'value' => 'text-red-700'],
        'yellow' => ['bg' => 'bg-yellow-50', 'text' => 'text-yellow-600', 'value' => 'text-yellow-800'],
    ];

    foreach ($cards as $card) :
        $tone = $tones[$card['tone']];
        ?>
        <a href="<?= e(url($card['href'])) ?>"
           class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 sm:p-6 flex flex-col justify-between hover:border-gray-300 transition-colors">
            <div class="flex items-start justify-between">
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wider"><?= e($card['label']) ?></span>
                <div class="p-2 <?= $tone['bg'] ?> rounded-md">
                    <svg class="h-4 w-4 <?= $tone['text'] ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <?= $card['icon'] ?>
                    </svg>
                </div>
            </div>
            <p class="mt-4 text-2xl font-bold <?= $tone['value'] ?>" data-summary="<?= e($card['key']) ?>">
                <?= number_format((int) $card['value'], 0, ',', '.') ?>
            </p>
        </a>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">

    <!-- ==================================================================
         UTILIZACAO MEDIA DA INFRAESTRUTURA
         ================================================================== -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div class="flex items-start justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Utilização da infraestrutura</h3>
        </div>

        <p class="text-xs text-gray-500 mb-5">
            Média da última coleta de
            <span class="font-medium text-gray-700"><?= (int) $usage['samples'] ?></span>
            servidor(es) ativo(s).
        </p>

        <div class="space-y-5">
            <?php
            $meters = [
                ['label' => 'CPU',   'data' => $usage['cpu']],
                ['label' => 'RAM',   'data' => $usage['ram']],
                ['label' => 'Disco', 'data' => $usage['disk']],
            ];

            foreach ($meters as $meter) :
                $value = $meter['data']['value'];
                $level = $meter['data']['level'];
                ?>
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-sm font-medium text-gray-700"><?= e($meter['label']) ?></span>
                        <span class="text-sm font-semibold <?= level_text_class($level) ?>">
                            <?= $value === null ? '--' : format_percent((float) $value, 1) ?>
                        </span>
                    </div>
                    <div class="w-full h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-2 rounded-full <?= level_bar_class($level) ?> transition-all duration-300"
                             style="width: <?= $value === null ? 0 : min(100, (float) $value) ?>%"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 pt-5 border-t border-gray-200 grid grid-cols-3 gap-3 text-center">
            <div>
                <p class="text-lg font-bold text-gray-900"><?= number_format($wordpressCount, 0, ',', '.') ?></p>
                <p class="text-xs text-gray-500 mt-0.5">WordPress</p>
            </div>
            <div>
                <p class="text-lg font-bold <?= $ssl['expiring'] > 0 ? 'text-yellow-800' : 'text-gray-900' ?>">
                    <?= number_format($ssl['expiring'], 0, ',', '.') ?>
                </p>
                <p class="text-xs text-gray-500 mt-0.5">SSL vencendo</p>
            </div>
            <div>
                <p class="text-lg font-bold <?= $ssl['expired'] > 0 ? 'text-red-700' : 'text-gray-900' ?>">
                    <?= number_format($ssl['expired'], 0, ',', '.') ?>
                </p>
                <p class="text-xs text-gray-500 mt-0.5">SSL expirado</p>
            </div>
        </div>
    </div>

    <!-- ==================================================================
         SERVIDORES
         ================================================================== -->
    <div class="xl:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">Servidores</h3>
            <a href="<?= e(url('/servidores')) ?>" class="text-sm text-gray-500 hover:text-gray-700">Ver todos</a>
        </div>

        <?php if ($servers === []) : ?>
            <div class="px-6 py-12 text-center">
                <p class="text-gray-500 text-sm">Nenhum servidor cadastrado ainda.</p>
                <a href="<?= e(url('/servidores/novo')) ?>"
                   class="inline-flex items-center mt-4 px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
                    Cadastrar primeiro servidor
                </a>
            </div>
        <?php else : ?>
            <div class="divide-y divide-gray-200 max-h-[420px] overflow-y-auto scrollbar-thin">
                <?php foreach ($servers as $server) : ?>
                    <a href="<?= e(url('/servidores/' . $server['id'])) ?>"
                       class="flex items-center gap-4 px-6 py-3.5 hover:bg-gray-50 transition-colors">

                        <span class="h-2.5 w-2.5 rounded-full flex-shrink-0 <?= status_dot_class((string) $server['status']) ?>"></span>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-gray-900 truncate"><?= e($server['name']) ?></p>
                                <?php if ((int) $server['is_demo'] === 1) : ?>
                                    <span class="px-1.5 py-0.5 bg-purple-100 text-purple-800 text-[10px] font-bold uppercase tracking-wider rounded">Demo</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-xs text-gray-500 truncate">
                                <?= e($server['provider'] ?? 'Sem provedor') ?> &middot; <?= e($server['ip'] ?? '--') ?>
                                &middot; <?= (int) $server['sites_count'] ?> site(s)
                            </p>
                        </div>

                        <div class="hidden sm:flex items-center gap-4 flex-shrink-0">
                            <?php
                            $mini = [
                                ['CPU', $server['cpu_usage'], 'cpu'],
                                ['RAM', $server['ram_percent'], 'ram'],
                                ['Disco', $server['disk_percent'], 'disk'],
                            ];
                            foreach ($mini as [$miniLabel, $miniValue, $miniMetric]) :
                                $miniLevel = threshold_level($miniValue === null ? null : (float) $miniValue, $miniMetric);
                                ?>
                                <div class="text-center w-14">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= e($miniLabel) ?></p>
                                    <p class="text-sm font-semibold <?= level_text_class($miniLevel) ?>">
                                        <?= $miniValue === null ? '--' : format_percent((float) $miniValue) ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="text-right flex-shrink-0 w-24">
                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= status_badge_class((string) $server['status']) ?>">
                                <?= e(status_label((string) $server['status'])) ?>
                            </span>
                            <p class="text-xs text-gray-400 mt-1"><?= e(time_ago($server['last_seen_at'])) ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ======================================================================
     LISTAS DE ATENCAO
     ====================================================================== -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- Alertas abertos -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">Alertas em aberto</h3>
            <a href="<?= e(url('/alertas')) ?>" class="text-sm text-gray-500 hover:text-gray-700">Ver todos</a>
        </div>

        <?php if ($openAlerts === []) : ?>
            <p class="px-6 py-12 text-center text-gray-500 text-sm">Nenhum alerta em aberto.</p>
        <?php else : ?>
            <ul class="divide-y divide-gray-200">
                <?php foreach ($openAlerts as $alert) : ?>
                    <li>
                        <a href="<?= e(url('/alertas/' . $alert['id'])) ?>" class="flex gap-3 px-6 py-3.5 hover:bg-gray-50 transition-colors">
                            <span class="mt-1.5 h-2 w-2 rounded-full flex-shrink-0 <?= $alert['severity'] === 'critical' ? 'bg-red-500' : ($alert['severity'] === 'warning' ? 'bg-yellow-400' : 'bg-blue-500') ?>"></span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate"><?= e($alert['title']) ?></p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    <?= e(Alert::typeLabel((string) $alert['type'])) ?>
                                    &middot; <?= e(time_ago($alert['last_seen_at'])) ?>
                                    <?php if ((int) $alert['occurrences'] > 1) : ?>
                                        &middot; <?= (int) $alert['occurrences'] ?>x
                                    <?php endif; ?>
                                </p>
                            </div>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Sites offline -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">Sites offline</h3>
            <a href="<?= e(url('/sites?status=offline')) ?>" class="text-sm text-gray-500 hover:text-gray-700">Ver todos</a>
        </div>

        <?php if ($sitesOffline === []) : ?>
            <p class="px-6 py-12 text-center text-gray-500 text-sm">Todos os sites estao respondendo.</p>
        <?php else : ?>
            <ul class="divide-y divide-gray-200">
                <?php foreach ($sitesOffline as $site) : ?>
                    <li>
                        <a href="<?= e(url('/sites/' . $site['id'])) ?>" class="flex items-center gap-3 px-6 py-3.5 hover:bg-gray-50 transition-colors">
                            <span class="h-2 w-2 rounded-full bg-red-500 flex-shrink-0"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 truncate"><?= e($site['domain']) ?></p>
                                <p class="text-xs text-gray-500 truncate"><?= e($site['server_name']) ?></p>
                            </div>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800 flex-shrink-0">
                                <?= $site['http_status'] === null ? 'sem resposta' : 'HTTP ' . (int) $site['http_status'] ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- SSL vencendo -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900">SSL a vencer</h3>
            <a href="<?= e(url('/sites?ssl=expiring')) ?>" class="text-sm text-gray-500 hover:text-gray-700">Ver todos</a>
        </div>

        <?php if ($sslExpiring === []) : ?>
            <p class="px-6 py-12 text-center text-gray-500 text-sm">Nenhum certificado próximo do vencimento.</p>
        <?php else : ?>
            <ul class="divide-y divide-gray-200">
                <?php foreach ($sslExpiring as $cert) : ?>
                    <?php $days = (int) $cert['days_remaining']; ?>
                    <li>
                        <a href="<?= e(url('/sites/' . $cert['id'])) ?>" class="flex items-center gap-3 px-6 py-3.5 hover:bg-gray-50 transition-colors">
                            <span class="h-2 w-2 rounded-full flex-shrink-0 <?= $days < 0 ? 'bg-red-500' : 'bg-yellow-400' ?>"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-gray-900 truncate"><?= e($cert['domain']) ?></p>
                                <p class="text-xs text-gray-500 truncate">
                                    <?= e($cert['server_name']) ?> &middot; <?= e(format_date($cert['valid_until'])) ?>
                                </p>
                            </div>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full flex-shrink-0 <?= $days < 0 ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                <?= $days < 0 ? 'expirado' : $days . ' d' ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php
// Atualizacao periodica dos cards sem recarregar a pagina (secao 39: sem
// polling excessivo - um fetch leve a cada 60 s).
View::pushScript(<<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (window.ControleVPS) {
        window.ControleVPS.startSummaryRefresh(60000);
    }
});
</script>
HTML);
?>
