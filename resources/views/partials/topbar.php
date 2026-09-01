<?php
/**
 * Topbar - DESIGN.md secao 9.
 *
 * Conforme a secao 24 do PLAN, o topo mostra: usuario logado, status geral e
 * botao de logout.
 *
 * @var string                    $pageTitle
 * @var array<string,mixed>|null  $currentUser
 * @var array<string,mixed>|null  $overallStatus
 */

$status = $overallStatus ?? null;

$dotClass = match ($status['level'] ?? 'unknown') {
    'critical' => 'bg-red-500',
    'warning'  => 'bg-yellow-400',
    'normal'   => 'bg-green-500',
    default    => 'bg-gray-300',
};

$statusTextClass = match ($status['level'] ?? 'unknown') {
    'critical' => 'text-red-700',
    'warning'  => 'text-yellow-800',
    'normal'   => 'text-gray-600',
    default    => 'text-gray-400',
};
?>
<header class="bg-white shadow-sm border-b border-gray-200 h-16 flex items-center justify-between px-4 sm:px-6 lg:px-8 sticky top-0 z-20">

    <h1 class="text-[17px] font-bold text-gray-900 border-none truncate pl-10 lg:pl-0">
        <?= e($pageTitle ?? '') ?>
    </h1>

    <div class="flex items-center gap-3 sm:gap-4">

        <!-- Status geral da infraestrutura -->
        <?php if ($status !== null) : ?>
            <a href="<?= e(url('/alertas')) ?>"
               class="hidden sm:inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-gray-200 hover:bg-gray-50 transition-colors"
               title="<?= e(sprintf(
                   '%d servidor(es) offline, %d site(s) offline, %d alerta(s) crítico(s)',
                   $status['servers_offline'],
                   $status['sites_offline'],
                   $status['critical_alerts']
               )) ?>">
                <span class="h-2.5 w-2.5 rounded-full <?= $dotClass ?>" data-status-dot></span>
                <span class="text-sm font-medium <?= $statusTextClass ?>" data-status-label><?= e($status['label']) ?></span>
            </a>
        <?php endif; ?>

        <!-- Usuario + logout -->
        <div class="flex items-center gap-3">
            <div class="hidden md:block text-right leading-tight">
                <p class="text-sm font-medium text-gray-900"><?= e($currentUser['name'] ?? '') ?></p>
                <p class="text-xs text-gray-500"><?= e($currentUser['email'] ?? '') ?></p>
            </div>

            <form method="POST" action="<?= e(url('/logout')) ?>" class="inline">
                <?= csrf_field() ?>
                <button type="submit"
                        class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                        title="Sair do painel">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                        <path d="M16 17l5-5-5-5M21 12H9" />
                    </svg>
                    <span class="hidden sm:inline ml-2">Sair</span>
                </button>
            </form>
        </div>
    </div>
</header>
