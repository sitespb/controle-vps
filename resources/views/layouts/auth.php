<?php
/**
 * Layout de autenticacao - DESIGN.md secao 5 (modo autenticacao).
 * Sem sidebar nem topbar: tela cheia centrada.
 *
 * @var string $title
 * @var string $content
 * @var array<string,array<int,string>> $flash
 */

use App\Core\Config;
use App\Core\View;

$appName = (string) Config::get('app.name', 'Controle VPS');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? '') ?> &middot; <?= e($appName) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%23c8102e'/><rect x='24' y='26' width='52' height='16' rx='4' fill='white'/><rect x='24' y='50' width='52' height='16' rx='4' fill='white'/><circle cx='66' cy='34' r='3' fill='%23c8102e'/><circle cx='66' cy='58' r='3' fill='%23c8102e'/></svg>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script defer src="<?= e(asset('vendor/alpine.min.js')) ?>"></script>
</head>
<body class="bg-bglight font-sans text-gray-900 antialiased">
    <main class="min-h-screen flex items-center justify-center py-12 px-4">
        <div class="w-full max-w-md">
            <?= View::partial('partials/flash', ['flash' => $flash ?? []]) ?>
            <?= $content ?>

            <p class="mt-6 text-center text-xs text-gray-400">
                <?= e($appName) ?> v<?= e(\App\Core\App::VERSION) ?> &middot; Central de monitoramento CyberPanel
            </p>
        </div>
    </main>
</body>
</html>
