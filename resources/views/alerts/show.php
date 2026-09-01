<?php
/**
 * Detalhe do alerta com a linha do tempo (secao 18 do PLAN).
 *
 * @var array<string,mixed>            $alert
 * @var array<int,array<string,mixed>> $events
 */

use App\Models\Alert;
use App\Models\AlertEvent;

$isResolved = $alert['status'] === 'resolved';

[$badgeClass, $accent] = match ((string) $alert['severity']) {
    'critical' => ['bg-red-100 text-red-800', 'border-red-500'],
    'warning'  => ['bg-yellow-100 text-yellow-800', 'border-yellow-400'],
    default    => ['bg-blue-100 text-blue-800', 'border-blue-500'],
};
?>

<div class="mb-6">
    <a href="<?= e(url('/alertas')) ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-3">
        <svg class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7" />
        </svg>
        Voltar para alertas
    </a>

    <div class="flex items-center gap-3 flex-wrap">
        <h2 class="text-2xl font-bold text-gray-900"><?= e($alert['title']) ?></h2>
        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $badgeClass ?>">
            <?= e(status_label((string) $alert['severity'])) ?>
        </span>
        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= status_badge_class((string) $alert['status']) ?>">
            <?= e(status_label((string) $alert['status'])) ?>
        </span>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-2 space-y-6">

        <!-- Mensagem -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="bg-gray-50 border-l-4 <?= $accent ?> p-4 rounded-md">
                <p class="text-sm text-gray-800 leading-relaxed"><?= nl2br(e($alert['message'])) ?></p>
            </div>

            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-6 mt-6">
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Tipo</dt>
                    <dd class="text-sm font-medium text-gray-900 mt-1"><?= e(Alert::typeLabel((string) $alert['type'])) ?></dd>
                </div>
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Ocorrências</dt>
                    <dd class="text-sm font-medium text-gray-900 mt-1"><?= (int) $alert['occurrences'] ?></dd>
                </div>
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Primeira vez</dt>
                    <dd class="text-sm font-medium text-gray-900 mt-1"><?= e(format_datetime($alert['first_seen_at'])) ?></dd>
                </div>
                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Última vez</dt>
                    <dd class="text-sm font-medium text-gray-900 mt-1"><?= e(format_datetime($alert['last_seen_at'])) ?></dd>
                </div>
            </dl>

            <?php if ($alert['metric_value'] !== null) : ?>
                <div class="mt-6 pt-5 border-t border-gray-200">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Valor que disparou o alerta</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">
                        <?= e(number_format((float) $alert['metric_value'], 2, ',', '.')) ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Linha do tempo -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Linha do tempo</h3>

            <?php if ($events === []) : ?>
                <p class="text-sm text-gray-500 py-6 text-center">Nenhum evento registrado.</p>
            <?php else : ?>
                <ol class="space-y-5">
                    <?php foreach ($events as $index => $event) : ?>
                        <?php
                        $eventDot = match ((string) $event['event']) {
                            AlertEvent::RESOLVED     => 'bg-green-500',
                            AlertEvent::ACKNOWLEDGED => 'bg-yellow-400',
                            AlertEvent::REOPENED     => 'bg-red-500',
                            AlertEvent::RECURRED     => 'bg-gray-400',
                            default                  => 'bg-red-500',
                        };
                        ?>
                        <li class="flex gap-4">
                            <div class="flex flex-col items-center flex-shrink-0">
                                <span class="h-2.5 w-2.5 rounded-full <?= $eventDot ?> mt-1.5"></span>
                                <?php if ($index < \count($events) - 1) : ?>
                                    <span class="w-px flex-1 bg-gray-200 mt-1"></span>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0 pb-1">
                                <p class="text-sm font-medium text-gray-900"><?= e(AlertEvent::eventLabel((string) $event['event'])) ?></p>
                                <?php if (!empty($event['message'])) : ?>
                                    <p class="text-sm text-gray-600 mt-0.5"><?= e($event['message']) ?></p>
                                <?php endif; ?>
                                <p class="text-xs text-gray-400 mt-1">
                                    <?= e(format_datetime($event['created_at'])) ?>
                                    &middot; <?= e($event['user_name'] ?? 'sistema') ?>
                                </p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lateral -->
    <aside class="space-y-6">

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Origem</h3>

            <dl class="space-y-4">
                <?php if (!empty($alert['server_name'])) : ?>
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Servidor</dt>
                        <dd class="mt-1">
                            <a href="<?= e(url('/servidores/' . $alert['server_id'])) ?>" class="text-sm font-medium text-gray-900 hover:text-primary">
                                <?= e($alert['server_name']) ?>
                            </a>
                        </dd>
                    </div>
                <?php endif; ?>

                <?php if (!empty($alert['site_domain'])) : ?>
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Site</dt>
                        <dd class="mt-1">
                            <a href="<?= e(url('/sites/' . $alert['site_id'])) ?>" class="text-sm font-medium text-gray-900 hover:text-primary break-all">
                                <?= e($alert['site_domain']) ?>
                            </a>
                        </dd>
                    </div>
                <?php endif; ?>

                <div>
                    <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Criado em</dt>
                    <dd class="text-sm text-gray-900 mt-1"><?= e(format_datetime($alert['created_at'])) ?></dd>
                </div>

                <?php if ($alert['acknowledged_at'] !== null) : ?>
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Reconhecido</dt>
                        <dd class="text-sm text-gray-900 mt-1">
                            <?= e(format_datetime($alert['acknowledged_at'])) ?>
                            <?php if (!empty($alert['acknowledged_by_name'])) : ?>
                                <span class="text-gray-500">por <?= e($alert['acknowledged_by_name']) ?></span>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>

                <?php if ($alert['resolved_at'] !== null) : ?>
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Resolvido em</dt>
                        <dd class="text-sm text-green-700 font-medium mt-1"><?= e(format_datetime($alert['resolved_at'])) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <?php if (!$isResolved) : ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Ações</h3>
                <p class="text-xs text-gray-500 mb-4 leading-relaxed">
                    Resolver manualmente apenas fecha o registro atual. Se a condição continuar,
                    a próxima coleta abre o alerta de novo &mdash; o motor não depende desta ação.
                </p>

                <div class="space-y-2">
                    <?php if ($alert['status'] === 'open') : ?>
                        <form method="POST" action="<?= e(url('/alertas/' . $alert['id'] . '/reconhecer')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit"
                                    class="w-full px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors">
                                Reconhecer
                            </button>
                        </form>
                    <?php endif; ?>

                    <form method="POST" action="<?= e(url('/alertas/' . $alert['id'] . '/resolver')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit"
                                class="w-full px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
                            Resolver manualmente
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </aside>
</div>
