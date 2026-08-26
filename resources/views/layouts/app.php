<?php
/**
 * Shell da aplicacao - DESIGN.md secao 5.
 *
 * ┌─────────────────────────────────────────────┐
 * │ [botao ☰ fixo — apenas < lg]                │
 * ├──────────┬──────────────────────────────────┤
 * │ Sidebar  │ Topbar (h-16, bg-white)          │
 * │ fixed    ├──────────────────────────────────┤
 * │ gray-900 │ <main> p-4 sm:p-6 lg:p-8         │
 * │ w-64     │   conteudo da pagina             │
 * └──────────┴──────────────────────────────────┘
 *
 * @var string                    $title
 * @var string                    $content
 * @var array<string,mixed>|null  $currentUser
 * @var array<string,array>       $flash
 * @var string                    $activeNav
 */

use App\Core\App;
use App\Core\Config;
use App\Core\View;

$appName = (string) Config::get('app.name', 'Controle VPS');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-url" content="<?= e(base_path_url()) ?>">
    <title><?= e($title ?? '') ?> &middot; <?= e($appName) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%23c8102e'/><rect x='24' y='26' width='52' height='16' rx='4' fill='white'/><rect x='24' y='50' width='52' height='16' rx='4' fill='white'/><circle cx='66' cy='34' r='3' fill='%23c8102e'/><circle cx='66' cy='58' r='3' fill='%23c8102e'/></svg>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script defer src="<?= e(asset('vendor/alpine.min.js')) ?>"></script>
</head>
<body class="bg-bglight font-sans text-gray-900 antialiased"
      x-data="{
          sidebarOpen: false,
          sidebarCollapsed: localStorage.getItem('sidebarCollapsed') === 'true',
          toggleSidebar() {
              this.sidebarCollapsed = !this.sidebarCollapsed;
              localStorage.setItem('sidebarCollapsed', this.sidebarCollapsed);
          }
      }">

    <!-- Botao de menu no mobile (abaixo de lg) -->
    <button type="button"
            @click="sidebarOpen = true"
            class="lg:hidden fixed top-3 left-3 z-50 p-2 rounded-lg bg-white shadow-sm border border-gray-200 text-gray-500 hover:text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-primary"
            aria-label="Abrir menu">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

    <!-- Overlay do mobile -->
    <div x-show="sidebarOpen"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="sidebarOpen = false"
         class="lg:hidden fixed inset-0 z-30 bg-black bg-opacity-50"></div>

    <?= View::partial('partials/sidebar', [
        'currentUser' => $currentUser ?? null,
        'activeNav'   => $activeNav ?? '',
        'appName'     => $appName,
    ]) ?>

    <div class="transition-all duration-300 min-h-screen flex flex-col"
         :class="sidebarCollapsed ? 'lg:ml-20' : 'lg:ml-64'">

        <?= View::partial('partials/topbar', [
            'pageTitle'     => $title ?? '',
            'currentUser'   => $currentUser ?? null,
            'overallStatus' => $overallStatus ?? null,
        ]) ?>

        <main class="flex-1 p-4 sm:p-6 lg:p-8">
            <?= View::partial('partials/flash', ['flash' => $flash ?? []]) ?>
            <?= $content ?>
        </main>

        <footer class="px-4 sm:px-6 lg:px-8 py-4 text-xs text-gray-400 border-t border-gray-200 bg-white">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-2">
                <span><?= e($appName) ?> v<?= e(App::VERSION) ?> &middot; Central de monitoramento CyberPanel</span>
                <span>Somente monitoramento &mdash; esta versao nao executa acoes nos servidores.</span>
            </div>
        </footer>
    </div>

    <script src="<?= e(asset('js/app.js')) ?>"></script>
    <?= View::flushScripts() ?>
</body>
</html>
