<?php
/**
 * Lista de sites - secao 14 do PLAN.
 *
 * Pesquisa por dominio, filtros por servidor / status / SSL / WordPress,
 * ordenacao por coluna e paginacao.
 *
 * @var array<int,array<string,mixed>> $sites
 * @var array<string,int>              $pagination
 * @var array<string,mixed>            $filters
 * @var array<int,array<string,mixed>> $servers
 * @var array<string,int>              $summary
 * @var array<string,int>              $sslSummary
 * @var array<int,string>              $duplicados  dominios em mais de um servidor
 */

use App\Core\View;
use App\Services\SslService;

$currentSort = $filters['sort'] ?? 'domain';
$currentDir  = strtolower($filters['direction'] ?? 'asc');

// Quantos itens por pagina estao valendo. Precisa acompanhar TODO link e
// TODO formulario da tela - basta um caminho esquecer para a escolha do
// operador se perder no clique seguinte.
$porPagina = (int) ($pagination['per_page'] ?? 0);

/** Monta o link de ordenacao preservando os filtros ativos. */
$sortLink = static function (string $column) use ($filters, $currentSort, $currentDir, $porPagina): string {
    $params = array_filter([
        'q'         => $filters['search'] ?? '',
        'servidor'  => $filters['server_id'] ?? 0,
        'status'    => $filters['status'] ?? '',
        'ssl'        => $filters['ssl'] ?? '',
        'wordpress'  => $filters['wordpress'] ?? '',
        'duplicados' => $filters['duplicados'] ?? '',
        'por_pagina' => $porPagina,
    ], static fn ($v): bool => $v !== '' && $v !== 0);

    $params['sort'] = $column;
    $params['dir']  = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';

    return url('/sites') . '?' . http_build_query($params);
};

/** Seta indicando a coluna ordenada. */
$sortArrow = static function (string $column) use ($currentSort, $currentDir): string {
    if ($currentSort !== $column) {
        return '';
    }

    return $currentDir === 'asc' ? ' &uarr;' : ' &darr;';
};

$hasFilters = !empty($filters['search'])
    || !empty($filters['server_id'])
    || !empty($filters['status'])
    || !empty($filters['ssl'])
    || !empty($filters['wordpress'])
    || !empty($filters['duplicados']);

/*
 * Busca em array e barata aqui de proposito: `$duplicados` costuma ter zero ou
 * poucos itens, e resolver linha a linha no banco custaria uma subconsulta por
 * dominio exibido.
 */
$duplicados   = $duplicados ?? [];
$ehDuplicado  = static fn (string $dominio): bool => \in_array($dominio, $duplicados, true);
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-900">Sites</h2>
    <p class="text-sm text-gray-600 mt-1">
        <?= number_format($summary['total'], 0, ',', '.') ?> dominio(s) descoberto(s) automaticamente &middot;
        <span class="text-green-700 font-medium"><?= number_format($summary['online'], 0, ',', '.') ?> online</span> &middot;
        <span class="<?= $summary['offline'] > 0 ? 'text-red-700 font-medium' : '' ?>"><?= number_format($summary['offline'], 0, ',', '.') ?> offline</span> &middot;
        <span class="<?= $summary['warning'] > 0 ? 'text-yellow-800 font-medium' : '' ?>"><?= number_format($summary['warning'], 0, ',', '.') ?> em atencao</span>
    </p>
</div>

<!-- Atalhos de SSL -->
<div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
    <?php
    $sslCards = [
        ['label' => 'SSL valido',   'value' => $sslSummary['valid'],    'filter' => 'valid',    'class' => 'text-green-700'],
        ['label' => 'A vencer',     'value' => $sslSummary['expiring'], 'filter' => 'expiring', 'class' => 'text-yellow-800'],
        ['label' => 'Expirado',     'value' => $sslSummary['expired'],  'filter' => 'expired',  'class' => 'text-red-700'],
        ['label' => 'Nao verificado', 'value' => $sslSummary['unknown'], 'filter' => 'unknown', 'class' => 'text-gray-500'],
        ['label' => 'Sem SSL',      'value' => $sslSummary['none'],     'filter' => 'none',     'class' => 'text-gray-500'],
    ];

    foreach ($sslCards as $card) :
        $active = ($filters['ssl'] ?? '') === $card['filter'];
        ?>
        <a href="<?= e(url('/sites?ssl=' . $card['filter'])) ?>"
           class="bg-white rounded-xl shadow-sm border p-4 text-center transition-colors <?= $active ? 'border-primary' : 'border-gray-200 hover:border-gray-300' ?>">
            <p class="text-xl font-bold <?= $card['class'] ?>"><?= number_format($card['value'], 0, ',', '.') ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= e($card['label']) ?></p>
        </a>
    <?php endforeach; ?>
</div>

<!-- Filtros -->
<form method="GET" action="<?= e(url('/sites')) ?>" class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">

        <div class="xl:col-span-2">
            <label for="q" class="block text-sm font-medium text-gray-700 mb-1">Pesquisar dominio</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <svg class="h-4 w-4 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8" /><path d="M21 21l-4.35-4.35" />
                    </svg>
                </div>
                <input type="search" id="q" name="q" value="<?= e($filters['search'] ?? '') ?>"
                       data-auto-submit-delay="600" placeholder="exemplo.com.br"
                       class="w-full pl-10 pr-4 py-2 rounded-lg border border-gray-300 text-sm focus:ring-primary focus:border-primary">
            </div>
        </div>

        <div>
            <label for="servidor" class="block text-sm font-medium text-gray-700 mb-1">Servidor</label>
            <select id="servidor" name="servidor" data-auto-submit
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary focus:border-primary">
                <option value="">Todos</option>
                <?php foreach ($servers as $server) : ?>
                    <option value="<?= (int) $server['id'] ?>" <?= (int) ($filters['server_id'] ?? 0) === (int) $server['id'] ? 'selected' : '' ?>>
                        <?= e($server['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select id="status" name="status" data-auto-submit
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary focus:border-primary">
                <option value="">Todos</option>
                <?php foreach (['online' => 'Online', 'warning' => 'Atencao', 'offline' => 'Offline', 'unknown' => 'Desconhecido'] as $value => $label) : ?>
                    <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="ssl" class="block text-sm font-medium text-gray-700 mb-1">SSL</label>
            <select id="ssl" name="ssl" data-auto-submit
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary focus:border-primary">
                <option value="">Todos</option>
                <?php foreach (['valid' => 'Valido', 'expiring' => 'A vencer', 'expired' => 'Expirado', 'unknown' => 'Nao verificado', 'none' => 'Sem certificado'] as $value => $label) : ?>
                    <option value="<?= e($value) ?>" <?= ($filters['ssl'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="flex items-center justify-between mt-3">
        <div class="flex items-center gap-5 flex-wrap">
            <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                <input type="checkbox" name="wordpress" value="yes" data-auto-submit
                       <?= ($filters['wordpress'] ?? '') === 'yes' ? 'checked' : '' ?>
                       class="rounded border-gray-300 text-primary focus:ring-primary">
                Somente WordPress
            </label>

            <!-- So aparece quando ha o que filtrar: um filtro que nunca
                 encontra nada e ruido permanente na tela. -->
            <?php if ($duplicados !== []) : ?>
                <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                    <input type="checkbox" name="duplicados" value="yes" data-auto-submit
                           <?= ($filters['duplicados'] ?? '') === 'yes' ? 'checked' : '' ?>
                           class="rounded border-gray-300 text-primary focus:ring-primary">
                    Somente duplicados
                    <span class="px-1.5 py-0.5 bg-orange-100 text-orange-800 text-[10px] font-bold rounded"><?= \count($duplicados) ?></span>
                </label>
            <?php endif; ?>
        </div>

        <?php if ($hasFilters) : ?>
            <a href="<?= e(url('/sites')) ?>" class="text-sm text-gray-500 hover:text-gray-700">Limpar filtros</a>
        <?php endif; ?>
    </div>

    <input type="hidden" name="sort" value="<?= e($currentSort) ?>">
    <input type="hidden" name="dir" value="<?= e($currentDir) ?>">

    <!-- Preserva a escolha de itens por pagina ao aplicar um filtro. -->
    <input type="hidden" name="por_pagina" value="<?= (int) $porPagina ?>">
</form>

<?php if ($sites === []) : ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 px-6 py-16 text-center">
        <svg class="mx-auto h-12 w-12 text-gray-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18" />
        </svg>
        <p class="mt-4 text-gray-500">
            <?= $hasFilters
                ? 'Nenhum site corresponde aos filtros aplicados.'
                : 'Nenhum site descoberto ainda. Os dominios aparecem aqui apos a primeira coleta do agente.' ?>
        </p>
        <?php if ($hasFilters) : ?>
            <a href="<?= e(url('/sites')) ?>" class="inline-block mt-4 text-sm text-primary font-medium">Limpar filtros</a>
        <?php endif; ?>
    </div>

<?php else : ?>

    <!-- ==================================================================
         TABELA (lg e acima)
         ================================================================== -->
    <div class="hidden lg:block bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-4">
        <div class="overflow-x-auto scrollbar-thin">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <?php
                        $columns = [
                            'domain'        => 'Dominio',
                            'server'        => 'Servidor',
                            'status'        => 'Status',
                            'http_status'   => 'HTTP',
                            'ssl'           => 'SSL',
                            'ssl_expiry'    => 'Expiracao',
                            'php'           => 'PHP',
                            'disk'          => 'Espaco',
                        ];

                        foreach ($columns as $key => $label) :
                            ?>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:text-gray-700 first:pl-6">
                                <a href="<?= e($sortLink($key)) ?>" class="block"><?= e($label) ?><?= $sortArrow($key) ?></a>
                            </th>
                        <?php endforeach; ?>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">WordPress</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:text-gray-700">
                            <a href="<?= e($sortLink('response_time')) ?>" class="block">Resposta<?= $sortArrow('response_time') ?></a>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:text-gray-700">
                            <a href="<?= e($sortLink('last_check')) ?>" class="block">Verificacao<?= $sortArrow('last_check') ?></a>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($sites as $site) : ?>
                        <?php $sslDays = $site['ssl_days_remaining'] === null ? null : (int) $site['ssl_days_remaining']; ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3">
                                <a href="<?= e(url('/sites/' . $site['id'])) ?>" class="text-sm font-medium text-gray-900 hover:text-primary">
                                    <?= e($site['domain']) ?>
                                </a>
                                <?php if ((int) $site['is_demo'] === 1) : ?>
                                    <span class="ml-1.5 px-1.5 py-0.5 bg-purple-100 text-purple-800 text-[10px] font-bold uppercase tracking-wider rounded">Demo</span>
                                <?php endif; ?>
                                <?php if ((int) ($site['notify_muted'] ?? 0) === 1) : ?>
                                    <span title="Voce marcou ciente: avisos deste dominio estao silenciados ate ele voltar ao ar"
                                          class="ml-1.5 px-1.5 py-0.5 bg-amber-100 text-amber-800 text-[10px] font-bold uppercase tracking-wider rounded">Ciente</span>
                                <?php endif; ?>
                                <?php if ($ehDuplicado((string) $site['domain'])) : ?>
                                    <span title="Este dominio existe em mais de um servidor. Abra o site para ver qual copia esta no ar."
                                          class="ml-1.5 px-1.5 py-0.5 bg-orange-100 text-orange-800 text-[10px] font-bold uppercase tracking-wider rounded">Duplicado</span>
                                <?php endif; ?>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="<?= e(url('/servidores/' . $site['server_id'])) ?>" class="text-sm text-gray-500 hover:text-primary">
                                    <?= e($site['server_name']) ?>
                                </a>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="px-2 py-1 inline-flex items-center gap-1.5 text-xs leading-5 font-semibold rounded-full <?= status_badge_class((string) $site['status']) ?>">
                                    <span class="h-1.5 w-1.5 rounded-full <?= status_dot_class((string) $site['status']) ?>"></span>
                                    <?= e(status_label((string) $site['status'])) ?>
                                </span>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                <?= $site['http_status'] === null ? '<span class="text-gray-400">--</span>' : (int) $site['http_status'] ?>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= SslService::badgeClass($site['ssl_status'] ?? null) ?>">
                                    <?= e(SslService::label($site['ssl_status'] ?? null, $sslDays)) ?>
                                </span>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?= $site['ssl_valid_until'] === null ? '--' : e(format_date($site['ssl_valid_until'])) ?>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= e($site['php_version'] ?? '--') ?></td>

                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?= $site['disk_usage'] === null ? '<span class="text-gray-400">--</span>' : e(format_bytes((float) $site['disk_usage'])) ?>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap">
                                <?php if ((int) $site['wordpress_detected'] === 1) : ?>
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        <?= e($site['wordpress_version'] ?? 'Detectado') ?>
                                    </span>
                                <?php else : ?>
                                    <span class="text-sm text-gray-400">--</span>
                                <?php endif; ?>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?= $site['response_time'] === null ? '--' : number_format((int) $site['response_time'], 0, ',', '.') . ' ms' ?>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?= e(time_ago($site['last_check_at'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ==================================================================
         CARDS (abaixo de lg)
         ================================================================== -->
    <div class="lg:hidden space-y-3 mb-4">
        <?php foreach ($sites as $site) : ?>
            <?php $sslDays = $site['ssl_days_remaining'] === null ? null : (int) $site['ssl_days_remaining']; ?>
            <a href="<?= e(url('/sites/' . $site['id'])) ?>"
               class="block bg-white rounded-xl shadow-sm border border-gray-200 p-4 hover:border-gray-300 transition-colors">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate"><?= e($site['domain']) ?></p>
                        <p class="text-xs text-gray-500 mt-0.5 truncate"><?= e($site['server_name']) ?></p>
                    </div>
                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full flex-shrink-0 <?= status_badge_class((string) $site['status']) ?>">
                        <?= e(status_label((string) $site['status'])) ?>
                    </span>
                </div>

                <div class="flex items-center gap-2 mt-3 flex-wrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?= SslService::badgeClass($site['ssl_status'] ?? null) ?>">
                        SSL: <?= e(SslService::label($site['ssl_status'] ?? null, $sslDays)) ?>
                    </span>
                    <?php if ($site['http_status'] !== null) : ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">HTTP <?= (int) $site['http_status'] ?></span>
                    <?php endif; ?>
                    <?php if ((int) $site['wordpress_detected'] === 1) : ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">WP <?= e($site['wordpress_version'] ?? '') ?></span>
                    <?php endif; ?>
                    <?php if ($site['php_version'] !== null) : ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">PHP <?= e($site['php_version']) ?></span>
                    <?php endif; ?>
                    <?php if ($site['disk_usage'] !== null) : ?>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800"><?= e(format_bytes((float) $site['disk_usage'])) ?></span>
                    <?php endif; ?>
                </div>

                <p class="text-xs text-gray-400 mt-3">
                    <?= $site['response_time'] === null ? 'sem resposta' : number_format((int) $site['response_time'], 0, ',', '.') . ' ms' ?>
                    &middot; verificado <?= e(time_ago($site['last_check_at'])) ?>
                </p>
            </a>
        <?php endforeach; ?>
    </div>

    <?= View::partial('partials/pagination', [
        'pagination'  => $pagination,
        'basePath'    => '/sites',
        'queryParams' => [
            'q'          => $filters['search'] ?? '',
            'servidor'   => $filters['server_id'] ?? 0,
            'status'     => $filters['status'] ?? '',
            'ssl'        => $filters['ssl'] ?? '',
            'wordpress'  => $filters['wordpress'] ?? '',
            'duplicados' => $filters['duplicados'] ?? '',
            'sort'       => $currentSort,
            'dir'        => $currentDir,

            // Sem isto, clicar em "proxima pagina" voltaria ao padrao: os
            // links de pagina sao montados a partir daqui.
            'por_pagina' => $porPagina,
        ],
        'label'          => 'site(s)',
        'perPageOptions' => \App\Controllers\SiteController::PER_PAGE_OPTIONS,
    ]) ?>

<?php endif; ?>
