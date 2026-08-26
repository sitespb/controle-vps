<?php
/**
 * Layout minimo para paginas de erro.
 *
 * Nao depende de sessao, de banco nem de assets opcionais: precisa renderizar
 * mesmo quando algo essencial da aplicacao falhou.
 *
 * @var string $title
 * @var string $content
 */

use App\Core\Config;

$appName = (string) Config::get('app.name', 'Controle VPS');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'Erro') ?> &middot; <?= e($appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="bg-bglight font-sans text-gray-900 antialiased">
    <main class="min-h-screen flex items-center justify-center py-12 px-4">
        <div class="w-full max-w-lg">
            <?= $content ?>
        </div>
    </main>
</body>
</html>
