<?php
/**
 * Lista de alertas - secao 18 do PLAN.
 *
 * Os alertas nascem e morrem sozinhos: o motor abre quando a regra dispara e
 * resolve quando a condicao normaliza. As acoes daqui sao complementares -
 * reconhecer (silenciar sem fechar) e resolver manualmente.
 *
 * @var array<int,array<string,mixed>> $alerts
 * @var array<string,int>              $pagination
 * @var array<string,mixed>            $filters
 * @var array<int,array<string,mixed>> $servers
 * @var array<string,string>           $types
 * @var array<string,int>              $counts
 */

use App\Core\View;
use App\Models\Alert;

$status = $filters['status'] ?? 'active';
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-900">Alertas</h2>
    <p class="text-sm text-gray-600 mt-1">
        Gerados automaticamente pelas regras de limite, disponibilidade e SSL &mdash;
        e resolvidos sozinhos quando a condicao normaliza.
    </p>
</div>

<!-- Contadores por severidade -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <?php
    $severityCards = [
        ['label' => 'Criticos',    'value' => $counts['critical'], 'filter' => 'critical', 'class' => 'text-red-700',    'dot' => 'bg-red-500'],
        ['label' => 'Atencao',     'value' => $counts['warning'],  'filter' => 'warning',  'class' => 'text-yellow-800', 'dot' => 'bg-yellow-400'],
        ['label' => 'Informativos', 'value' => $counts['info'],    'filter' => 'info',     'class' => 'text-blue-700',   'dot' => 'bg-blue-500'],
    ];

    foreach ($severityCards as $card) :
        $active = ($filters['severity'] ?? '') === $card['filter'];
        ?>
        <a href="<?= e(url('/alertas?severidade=' . $card['filter'])) ?>"
           class="bg-white rounded-xl shadow-sm border p-6 transition-colors <?= $active ? 'border-primary' : 'border-gray-200 hover:border-gray-300' ?>">
            <div class="flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full <?= $card['dot'] ?>"></span>
                <span class="text-xs font-medium text-gray-500 uppercase tracking-wider"><?= e($card['label']) ?></span>
            </div>
            <p class="mt-3 text-2xl font-bold <?= $card['value'] > 0 ? $card['class'] : 'text-gray-900' ?>">
                <?= number_format($card['value'], 0, ',', '.') ?>
            </p>
        </a>
    <?php endforeach; ?>
</div>

<!-- Filtros -->
<form method="GET" action="<?= e(url('/alertas')) ?>" class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4">

        <div class="xl:col-span-2">
            <label for="q" class="block text-sm font-medium text-gray-700 mb-1">Pesquisar</label>
            <input type="search" id="q" name="q" value="<?= e($filters['search'] ?? '') ?>"
                   data-auto-submit-delay="600" placeholder="Titulo ou mensagem do alerta"
                   class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:ring-primary focus:border-primary">
        </div>

        <div>
            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Situacao</label>
            <select id="status" name="status" data-auto-submit
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary focus:border-primary">
                <?php foreach ([
                    'active'       => 'Em aberto',
                    'open'         => 'Somente abertos',
                    'acknowledged' => 'Reconhecidos',
                    'resolved'     => 'Resolvidos',
                    'all'          => 'Todos',
                ] as $value => $label) : ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="tipo" class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
            <select id="tipo" name="tipo" data-auto-submit
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary focus:border-primary">
                <option value="">Todos</option>
                <?php foreach ($types as $value => $label) : ?>
                    <option value="<?= e($value) ?>" <?= ($filters['type'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
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
    </div>

    <?php if (!empty($filters['severity']) || !empty($filters['type']) || !empty($filters['server_id']) || !empty($filters['search']) || $status !== 'active') : ?>
        <div class="mt-3">
            <a href="<?= e(url('/alertas')) ?>" class="text-sm text-gray-500 hover:text-gray-700">Limpar filtros</a>
        </div>
    <?php endif; ?>

    <input type="hidden" name="severidade" value="<?= e($filters['severity'] ?? '') ?>">

    <!-- Preserva a escolha de itens por pagina ao aplicar um filtro. -->
    <input type="hidden" name="por_pagina" value="<?= (int) ($pagination['per_page'] ?? 0) ?>">
</form>

<?php if ($alerts === []) : ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 px-6 py-16 text-center">
        <svg class="mx-auto h-12 w-12 text-green-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" /><path d="M22 4L12 14.01l-3-3" />
        </svg>
        <p class="mt-4 text-gray-500">
            <?= $status === 'active'
                ? 'Nenhum alerta em aberto. Tudo operacional.'
                : 'Nenhum alerta corresponde aos filtros aplicados.' ?>
        </p>
    </div>

<?php else : ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-4">
        <ul class="divide-y divide-gray-200">
            <?php foreach ($alerts as $alert) : ?>
                <?php
                $isResolved = $alert['status'] === 'resolved';

                $accent = $isResolved
                    ? 'bg-gray-300'
                    : match ((string) $alert['severity']) {
                        'critical' => 'bg-red-500',
                        'warning'  => 'bg-yellow-400',
                        default    => 'bg-blue-500',
                    };
                ?>
                <li data-alert-row class="flex flex-col sm:flex-row sm:items-center gap-4 px-6 py-4 hover:bg-gray-50 transition-colors <?= $isResolved ? 'opacity-70' : '' ?>">

                    <span class="h-2.5 w-2.5 rounded-full flex-shrink-0 <?= $accent ?>"></span>

                    <div class="min-w-0 flex-1">
                        <a href="<?= e(url('/alertas/' . $alert['id'])) ?>" class="block">
                            <p class="text-sm font-medium text-gray-900"><?= e($alert['title']) ?></p>
                            <p class="text-sm text-gray-600 mt-0.5"><?= e(str_limit((string) $alert['message'], 140)) ?></p>
                            <div class="flex items-center gap-2 mt-2 flex-wrap text-xs text-gray-500">
                                <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-800 font-medium">
                                    <?= e(Alert::typeLabel((string) $alert['type'])) ?>
                                </span>
                                <?php if (!empty($alert['server_name'])) : ?>
                                    <span><?= e($alert['server_name']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($alert['site_domain'])) : ?>
                                    <span>&middot; <?= e($alert['site_domain']) ?></span>
                                <?php endif; ?>
                                <span>&middot; desde <?= e(format_datetime($alert['first_seen_at'])) ?></span>
                                <?php if ((int) $alert['occurrences'] > 1) : ?>
                                    <span>&middot; <?= (int) $alert['occurrences'] ?> ocorrencia(s)</span>
                                <?php endif; ?>
                                <?php if ($isResolved && $alert['resolved_at'] !== null) : ?>
                                    <span class="text-green-700">&middot; resolvido em <?= e(format_datetime($alert['resolved_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    </div>

                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= status_badge_class((string) $alert['status']) ?>">
                            <?= e(status_label((string) $alert['status'])) ?>
                        </span>

                        <?php if ($alert['status'] === 'open') : ?>
                            <button type="button" data-alert-action="acknowledge" data-alert-id="<?= (int) $alert['id'] ?>"
                                    class="px-3 py-1.5 border border-gray-300 rounded-lg text-xs text-gray-700 hover:bg-gray-50 transition-colors whitespace-nowrap"
                                    title="Marcar como reconhecido, sem fechar">
                                Reconhecer
                            </button>
                        <?php endif; ?>

                        <?php if (!$isResolved) : ?>
                            <button type="button" data-alert-action="resolve" data-alert-id="<?= (int) $alert['id'] ?>"
                                    class="px-3 py-1.5 border border-gray-300 rounded-lg text-xs text-gray-700 hover:bg-gray-50 transition-colors whitespace-nowrap"
                                    title="Fechar manualmente. Reabre sozinho se o problema persistir.">
                                Resolver
                            </button>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?= View::partial('partials/pagination', [
        'pagination'  => $pagination,
        'basePath'    => '/alertas',
        'queryParams' => [
            'q'          => $filters['search'] ?? '',
            'status'     => $status,
            'severidade' => $filters['severity'] ?? '',
            'tipo'       => $filters['type'] ?? '',
            'servidor'   => $filters['server_id'] ?? 0,

            // Sem isto, mudar de pagina descartaria a escolha do seletor.
            'por_pagina' => (int) ($pagination['per_page'] ?? 0),
        ],
        'label'          => 'alerta(s)',
        'perPageOptions' => \App\Core\Controller::PER_PAGE_OPTIONS,
    ]) ?>

<?php endif; ?>
