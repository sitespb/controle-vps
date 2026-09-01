<?php
/**
 * Lista de servidores - secao 11 do PLAN.
 *
 * Colunas: nome, provedor, hostname, IP, status, CPU, RAM, disco, sites,
 * ultima comunicacao e acoes.
 *
 * As acoes atuam somente sobre o REGISTRO no painel. Nenhuma acao
 * administrativa sobre o VPS existe na V1 (secao 41).
 *
 * @var array<int,array<string,mixed>> $servers
 * @var array<string,string>           $filters
 * @var array<string,int>              $summary
 * @var array<string,mixed>|null       $currentUser
 */

$isAdmin = ($currentUser['role'] ?? '') === 'admin';

/** Celula de metrica com barra proporcional. */
$metricCell = static function (?float $value, string $metric): string {
    if ($value === null) {
        return '<span class="text-sm text-gray-400">--</span>';
    }

    $level = threshold_level($value, $metric);

    return sprintf(
        '<div class="w-20">
            <div class="flex items-center justify-between mb-1">
                <span class="text-xs font-semibold %s">%s</span>
            </div>
            <div class="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-1.5 rounded-full %s" style="width: %s%%"></div>
            </div>
        </div>',
        level_text_class($level),
        format_percent($value),
        level_bar_class($level),
        min(100, $value)
    );
};
?>

<!-- Cabecalho da pagina -->
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-900">Servidores</h2>
        <p class="text-sm text-gray-600 mt-1">
            <?= (int) $summary['total'] ?> cadastrado(s) &middot;
            <span class="text-green-700 font-medium"><?= (int) $summary['online'] ?> online</span> &middot;
            <span class="<?= $summary['offline'] > 0 ? 'text-red-700 font-medium' : '' ?>"><?= (int) $summary['offline'] ?> offline</span>
        </p>
    </div>

    <?php if ($isAdmin) : ?>
        <a href="<?= e(url('/servidores/novo')) ?>"
           class="inline-flex items-center px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
            <svg class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 5v14M5 12h14" />
            </svg>
            Novo servidor
        </a>
    <?php endif; ?>
</div>

<!-- Filtros -->
<form method="GET" action="<?= e(url('/servidores')) ?>"
      class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="sm:col-span-2">
            <label for="q" class="block text-sm font-medium text-gray-700 mb-1">Pesquisar</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" /><path d="M21 21l-4.35-4.35" />
                    </svg>
                </div>
                <input type="search" id="q" name="q"
                       value="<?= e($filters['search'] ?? '') ?>"
                       data-auto-submit-delay="600"
                       placeholder="Nome, hostname, IP ou provedor"
                       class="w-full pl-10 pr-4 py-2 rounded-lg border border-gray-300 text-sm focus:ring-primary focus:border-primary">
            </div>
        </div>

        <div>
            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select id="status" name="status" data-auto-submit
                    class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:ring-primary focus:border-primary">
                <option value="">Todos</option>
                <?php foreach (['online' => 'Online', 'offline' => 'Offline', 'warning' => 'Atenção', 'unknown' => 'Desconhecido'] as $value => $label) : ?>
                    <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (!empty($filters['search']) || !empty($filters['status'])) : ?>
        <div class="mt-3">
            <a href="<?= e(url('/servidores')) ?>" class="text-sm text-gray-500 hover:text-gray-700">Limpar filtros</a>
        </div>
    <?php endif; ?>
</form>

<?php if ($servers === []) : ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 px-6 py-16 text-center">
        <svg class="mx-auto h-12 w-12 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2" y="4" width="20" height="7" rx="2" />
            <rect x="2" y="13" width="20" height="7" rx="2" />
            <line x1="6" y1="7.5" x2="6.01" y2="7.5" />
            <line x1="6" y1="16.5" x2="6.01" y2="16.5" />
        </svg>
        <p class="mt-4 text-gray-500">
            <?= (!empty($filters['search']) || !empty($filters['status']))
                ? 'Nenhum servidor corresponde aos filtros aplicados.'
                : 'Nenhum servidor cadastrado ainda.' ?>
        </p>
        <?php if ($isAdmin && empty($filters['search']) && empty($filters['status'])) : ?>
            <a href="<?= e(url('/servidores/novo')) ?>"
               class="inline-flex items-center mt-5 px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
                Cadastrar primeiro servidor
            </a>
        <?php endif; ?>
    </div>

<?php else : ?>

    <!-- ==================================================================
         TABELA (lg e acima)
         ================================================================== -->
    <div class="hidden lg:block bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto scrollbar-thin">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Servidor</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hostname / IP</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CPU</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">RAM</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Disco</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sites</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Última comunicação</th>
                        <th class="px-4 py-3 whitespace-nowrap text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($servers as $server) : ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <a href="<?= e(url('/servidores/' . $server['id'])) ?>" class="text-sm font-medium text-gray-900 hover:text-primary">
                                        <?= e($server['name']) ?>
                                    </a>
                                    <?php if ((int) $server['is_demo'] === 1) : ?>
                                        <span class="px-1.5 py-0.5 bg-purple-100 text-purple-800 text-[10px] font-bold uppercase tracking-wider rounded">Demo</span>
                                    <?php endif; ?>
                                    <?php if ((int) $server['alerts_count'] > 0) : ?>
                                        <span class="px-1.5 py-0.5 bg-red-100 text-red-800 text-[10px] font-bold rounded-full"><?= (int) $server['alerts_count'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-xs text-gray-500 mt-0.5"><?= e($server['provider'] ?? 'Sem provedor') ?></p>
                            </td>

                            <td class="px-4 py-4">
                                <p class="text-sm text-gray-700"><?= e($server['hostname'] ?? '--') ?></p>
                                <p class="text-xs text-gray-500 font-mono mt-0.5"><?= e($server['ip'] ?? '--') ?></p>
                            </td>

                            <td class="px-4 py-4 whitespace-nowrap">
                                <span class="px-2 py-1 inline-flex items-center gap-1.5 text-xs leading-5 font-semibold rounded-full <?= status_badge_class((string) $server['status']) ?>">
                                    <span class="h-1.5 w-1.5 rounded-full <?= status_dot_class((string) $server['status']) ?>"></span>
                                    <?= e(status_label((string) $server['status'])) ?>
                                </span>
                            </td>

                            <td class="px-4 py-4"><?= $metricCell($server['cpu_usage'], 'cpu') ?></td>
                            <td class="px-4 py-4"><?= $metricCell($server['ram_percent'], 'ram') ?></td>
                            <td class="px-4 py-4"><?= $metricCell($server['disk_percent'], 'disk') ?></td>

                            <td class="px-4 py-4 whitespace-nowrap">
                                <a href="<?= e(url('/sites?servidor=' . $server['id'])) ?>"
                                   class="text-sm text-gray-700 hover:text-primary">
                                    <?= (int) $server['sites_count'] ?> site(s)
                                </a>
                            </td>

                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= e(time_ago($server['last_seen_at'])) ?>
                            </td>

                            <td class="px-4 py-4 whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-1">

                                    <a href="<?= e(url('/servidores/' . $server['id'])) ?>"
                                       class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                                       title="Visualizar">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" />
                                        </svg>
                                    </a>

                                    <a href="<?= e(url('/sites?servidor=' . $server['id'])) ?>"
                                       class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                                       title="Visualizar sites">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18" />
                                        </svg>
                                    </a>

                                    <?php if ($isAdmin) : ?>
                                        <a href="<?= e(url('/servidores/' . $server['id'] . '/editar')) ?>"
                                           class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                                           title="Editar">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                                <path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                            </svg>
                                        </a>

                                        <a href="<?= e(url('/servidores/' . $server['id'] . '/agente')) ?>"
                                           class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                                           title="Agente e token">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4" />
                                            </svg>
                                        </a>

                                        <form method="POST" action="<?= e(url('/servidores/' . $server['id'] . '/excluir')) ?>" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="confirm_name" value="">
                                            <button type="button"
                                                    data-delete-server
                                                    data-server-name="<?= e($server['name']) ?>"
                                                    class="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                                                    title="Excluir servidor">
                                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                                </svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================================================================
         CARDS (abaixo de lg) - secao 26 do PLAN
         ================================================================== -->
    <div class="lg:hidden space-y-4">
        <?php foreach ($servers as $server) : ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <a href="<?= e(url('/servidores/' . $server['id'])) ?>" class="text-sm font-medium text-gray-900 truncate">
                                <?= e($server['name']) ?>
                            </a>
                            <?php if ((int) $server['is_demo'] === 1) : ?>
                                <span class="px-1.5 py-0.5 bg-purple-100 text-purple-800 text-[10px] font-bold uppercase tracking-wider rounded">Demo</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5">
                            <?= e($server['provider'] ?? 'Sem provedor') ?> &middot; <?= e($server['ip'] ?? '--') ?>
                        </p>
                    </div>
                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full flex-shrink-0 <?= status_badge_class((string) $server['status']) ?>">
                        <?= e(status_label((string) $server['status'])) ?>
                    </span>
                </div>

                <div class="grid grid-cols-3 gap-3 mt-4">
                    <?php foreach ([['CPU', $server['cpu_usage'], 'cpu'], ['RAM', $server['ram_percent'], 'ram'], ['Disco', $server['disk_percent'], 'disk']] as [$label, $value, $metric]) : ?>
                        <?php $level = threshold_level($value === null ? null : (float) $value, $metric); ?>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= e($label) ?></p>
                            <p class="text-sm font-semibold <?= level_text_class($level) ?>">
                                <?= $value === null ? '--' : format_percent((float) $value) ?>
                            </p>
                            <div class="w-full h-1.5 bg-gray-100 rounded-full overflow-hidden mt-1">
                                <div class="h-1.5 rounded-full <?= level_bar_class($level) ?>" style="width: <?= $value === null ? 0 : min(100, (float) $value) ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-200">
                    <span class="text-xs text-gray-500">
                        <?= (int) $server['sites_count'] ?> site(s) &middot; <?= e(time_ago($server['last_seen_at'])) ?>
                    </span>

                    <div class="flex items-center gap-1">
                        <a href="<?= e(url('/servidores/' . $server['id'])) ?>"
                           class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                           title="Visualizar">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" />
                            </svg>
                        </a>

                        <?php if ($isAdmin) : ?>
                            <a href="<?= e(url('/servidores/' . $server['id'] . '/editar')) ?>"
                               class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                               title="Editar">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                    <path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                </svg>
                            </a>

                            <a href="<?= e(url('/servidores/' . $server['id'] . '/agente')) ?>"
                               class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                               title="Agente e token">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4" />
                                </svg>
                            </a>

                            <form method="POST" action="<?= e(url('/servidores/' . $server['id'] . '/excluir')) ?>" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="confirm_name" value="">
                                <button type="button"
                                        data-delete-server
                                        data-server-name="<?= e($server['name']) ?>"
                                        class="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                                        title="Excluir servidor">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                    </svg>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>
