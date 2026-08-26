<?php
/**
 * Sidebar - unico bloco escuro da interface (DESIGN.md secao 9).
 *
 * Estrutura de navegacao definida na secao 24 do PLAN.
 * Itens administrativos so aparecem para o perfil admin.
 *
 * @var array<string,mixed>|null $currentUser
 * @var string                   $activeNav
 * @var string                   $appName
 */

$isAdmin = ($currentUser['role'] ?? '') === 'admin';

/** Classe do item de navegacao conforme o estado ativo. */
$navClass = static function (bool $active): string {
    return $active
        ? 'bg-primary text-white'
        : 'text-gray-300 hover:bg-gray-800 hover:text-white';
};
?>
<aside id="sidebar" class="fixed top-0 left-0 h-full bg-gray-900 text-white z-40 flex flex-col transition-all duration-300"
       :class="[
           sidebarCollapsed ? 'w-20' : 'w-64',
           sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
       ]">

    <!-- Cabecalho -->
    <div class="h-16 flex items-center px-6 border-b border-gray-800 justify-between overflow-hidden">
        <a href="<?= e(url('/')) ?>" class="flex items-center gap-3 min-w-0">
            <div class="h-8 w-8 bg-primary rounded-lg flex items-center justify-center flex-shrink-0">
                <svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="4" width="20" height="7" rx="2" />
                    <rect x="2" y="13" width="20" height="7" rx="2" />
                    <line x1="6" y1="7.5" x2="6.01" y2="7.5" />
                    <line x1="6" y1="16.5" x2="6.01" y2="16.5" />
                </svg>
            </div>
            <span x-show="!sidebarCollapsed" x-cloak class="font-bold text-sm truncate">Controle VPS</span>
        </a>

        <!-- Fechar no mobile -->
        <button type="button" @click="sidebarOpen = false" class="lg:hidden text-gray-400 hover:text-white" aria-label="Fechar menu">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <!-- Navegacao -->
    <nav id="sidebar-nav" class="flex-1 overflow-y-auto scrollbar-thin px-3 py-4 space-y-1">

        <a href="<?= e(url('/')) ?>" data-tooltip="Dashboard"
           class="group relative flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors duration-150 <?= $navClass($activeNav === 'dashboard') ?>">
            <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="7" height="9" rx="1" />
                <rect x="14" y="3" width="7" height="5" rx="1" />
                <rect x="14" y="12" width="7" height="9" rx="1" />
                <rect x="3" y="16" width="7" height="5" rx="1" />
            </svg>
            <span x-show="!sidebarCollapsed" x-cloak class="nav-label ml-3 truncate">Dashboard</span>
        </a>

        <!-- Infraestrutura -->
        <p x-show="!sidebarCollapsed" x-cloak class="px-3 pt-5 pb-1 text-[10px] font-bold uppercase tracking-wider text-gray-500">
            Infraestrutura
        </p>
        <div x-show="sidebarCollapsed" x-cloak class="pt-4 pb-1"><hr class="border-gray-800"></div>

        <a href="<?= e(url('/servidores')) ?>" data-tooltip="Servidores"
           class="group relative flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors duration-150 <?= $navClass($activeNav === 'servers') ?>">
            <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="4" width="20" height="7" rx="2" />
                <rect x="2" y="13" width="20" height="7" rx="2" />
                <line x1="6" y1="7.5" x2="6.01" y2="7.5" />
                <line x1="6" y1="16.5" x2="6.01" y2="16.5" />
            </svg>
            <span x-show="!sidebarCollapsed" x-cloak class="nav-label ml-3 truncate">Servidores</span>
        </a>

        <a href="<?= e(url('/sites')) ?>" data-tooltip="Sites"
           class="group relative flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors duration-150 <?= $navClass($activeNav === 'sites') ?>">
            <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9" />
                <path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18" />
            </svg>
            <span x-show="!sidebarCollapsed" x-cloak class="nav-label ml-3 truncate">Sites</span>
        </a>

        <!-- Monitoramento -->
        <p x-show="!sidebarCollapsed" x-cloak class="px-3 pt-5 pb-1 text-[10px] font-bold uppercase tracking-wider text-gray-500">
            Monitoramento
        </p>
        <div x-show="sidebarCollapsed" x-cloak class="pt-4 pb-1"><hr class="border-gray-800"></div>

        <a href="<?= e(url('/metricas')) ?>" data-tooltip="Metricas"
           class="group relative flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors duration-150 <?= $navClass($activeNav === 'metrics') ?>">
            <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 17l6-6 4 4 8-8" />
                <path d="M21 7v5h-5" />
            </svg>
            <span x-show="!sidebarCollapsed" x-cloak class="nav-label ml-3 truncate">Metricas</span>
        </a>

        <a href="<?= e(url('/alertas')) ?>" data-tooltip="Alertas"
           class="group relative flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors duration-150 <?= $navClass($activeNav === 'alerts') ?>">
            <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" />
                <path d="M13.73 21a2 2 0 0 1-3.46 0" />
            </svg>
            <span x-show="!sidebarCollapsed" x-cloak class="nav-label ml-3 truncate">Alertas</span>
            <span data-alert-badge
                  class="hidden ml-auto px-2 py-0.5 rounded-full bg-red-100 text-red-800 text-xs font-semibold"
                  x-show="!sidebarCollapsed" x-cloak>0</span>
        </a>

        <?php if ($isAdmin) : ?>
            <!-- Configuracoes -->
            <p x-show="!sidebarCollapsed" x-cloak class="px-3 pt-5 pb-1 text-[10px] font-bold uppercase tracking-wider text-gray-500">
                Configuracoes
            </p>
            <div x-show="sidebarCollapsed" x-cloak class="pt-4 pb-1"><hr class="border-gray-800"></div>

            <a href="<?= e(url('/usuarios')) ?>" data-tooltip="Usuarios"
               class="group relative flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors duration-150 <?= $navClass($activeNav === 'users') ?>">
                <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                    <circle cx="9" cy="7" r="4" />
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
                </svg>
                <span x-show="!sidebarCollapsed" x-cloak class="nav-label ml-3 truncate">Usuarios</span>
            </a>

            <a href="<?= e(url('/configuracoes')) ?>" data-tooltip="Sistema"
               class="group relative flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors duration-150 <?= $navClass($activeNav === 'settings') ?>">
                <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3" />
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                </svg>
                <span x-show="!sidebarCollapsed" x-cloak class="nav-label ml-3 truncate">Sistema</span>
            </a>

            <a href="<?= e(url('/logs')) ?>" data-tooltip="Logs"
               class="group relative flex items-center px-3 py-2.5 text-sm font-medium rounded-lg transition-colors duration-150 <?= $navClass($activeNav === 'logs') ?>">
                <svg class="h-5 w-5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    <path d="M14 2v6h6M8 13h8M8 17h5" />
                </svg>
                <span x-show="!sidebarCollapsed" x-cloak class="nav-label ml-3 truncate">Logs</span>
            </a>
        <?php endif; ?>
    </nav>

    <!-- Rodape: usuario + colapsar -->
    <div class="border-t border-gray-800 p-3">
        <div class="flex items-center gap-3 px-1 py-2">
            <div class="h-9 w-9 rounded-full bg-primary flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                <?= e(mb_strtoupper(mb_substr((string) ($currentUser['name'] ?? '?'), 0, 1))) ?>
            </div>
            <div x-show="!sidebarCollapsed" x-cloak class="min-w-0 flex-1">
                <p class="text-sm font-medium text-white truncate"><?= e($currentUser['name'] ?? '') ?></p>
                <p class="text-xs text-gray-400 truncate">
                    <?= e(\App\Models\User::roleLabel((string) ($currentUser['role'] ?? ''))) ?>
                </p>
            </div>
        </div>

        <button type="button"
                @click="toggleSidebar()"
                :data-tooltip="sidebarCollapsed ? 'Expandir menu' : 'Recolher menu'"
                class="hidden lg:flex w-full items-center justify-center mt-2 px-3 py-2 text-xs font-medium text-gray-400 hover:text-white hover:bg-gray-800 rounded-lg transition-colors">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                 :class="sidebarCollapsed ? 'rotate-180' : ''">
                <path d="M15 18l-6-6 6-6" />
            </svg>
            <span x-show="!sidebarCollapsed" x-cloak class="nav-label ml-2">Recolher menu</span>
        </button>
    </div>
</aside>
