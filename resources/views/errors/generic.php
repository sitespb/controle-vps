<?php
/**
 * Pagina de erro. Renderizada dentro de layouts/blank.
 *
 * @var int              $status
 * @var string           $message
 * @var \Throwable|null  $exception  Somente quando APP_DEBUG=true
 */

$icons = [
    403 => 'M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zM8 11V7a4 4 0 0 1 8 0v4',
    404 => 'M9.172 16.172a4 4 0 0 1 5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
    419 => 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
    429 => 'M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
];

$icon = $icons[$status] ?? 'M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 0 0 1.71-3.03l-6.93-11a2 2 0 0 0-3.42 0l-6.93 11A2 2 0 0 0 5.07 19z';

$tone = $status >= 500 ? 'red' : ($status === 404 ? 'gray' : 'yellow');

$badgeClass = match ($tone) {
    'red'    => 'bg-red-50 text-red-600',
    'yellow' => 'bg-yellow-50 text-yellow-600',
    default  => 'bg-gray-100 text-gray-500',
};
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">

    <div class="mx-auto h-12 w-12 rounded-xl flex items-center justify-center <?= $badgeClass ?>">
        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="<?= e($icon) ?>" />
        </svg>
    </div>

    <p class="mt-4 text-[10px] font-bold uppercase tracking-wider text-gray-400">Erro <?= e((string) $status) ?></p>
    <h1 class="mt-1 text-2xl font-bold text-gray-900">
        <?= e(match ($status) {
            403     => 'Acesso negado',
            404     => 'Pagina nao encontrada',
            405     => 'Metodo nao permitido',
            419     => 'Sessao expirada',
            429     => 'Muitas requisicoes',
            default => 'Algo deu errado',
        }) ?>
    </h1>

    <p class="mt-3 text-sm text-gray-600 leading-relaxed"><?= e($message) ?></p>

    <?php if (isset($exception) && $exception instanceof \Throwable) : ?>
        <div class="mt-6 text-left bg-gray-50 border border-gray-200 rounded-lg p-4 overflow-x-auto scrollbar-thin">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">
                Detalhes tecnicos (APP_DEBUG=true)
            </p>
            <p class="text-xs font-mono text-gray-700 break-all">
                <?= e($exception::class) ?><br>
                <?= e($exception->getFile()) ?>:<?= e((string) $exception->getLine()) ?>
            </p>
        </div>
    <?php endif; ?>

    <div class="mt-6 flex items-center justify-center gap-3">
        <a href="<?= e(url('/')) ?>"
           class="px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
            Voltar ao dashboard
        </a>
        <button type="button" onclick="history.back()"
                class="px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors">
            Página anterior
        </button>
    </div>
</div>
