<?php
/**
 * Mensagens flash - DESIGN.md secao 8.
 * Assinatura do alerta: border-l-4 + rounded-md.
 *
 * @var array<string,array<int,string>> $flash
 */

if (empty($flash)) {
    return;
}

$styles = [
    'success' => ['bg' => 'bg-green-50',  'border' => 'border-green-500',  'text' => 'text-green-700'],
    'error'   => ['bg' => 'bg-red-50',    'border' => 'border-red-500',    'text' => 'text-red-700'],
    'warning' => ['bg' => 'bg-yellow-50', 'border' => 'border-yellow-400', 'text' => 'text-yellow-800'],
    'info'    => ['bg' => 'bg-blue-50',   'border' => 'border-blue-500',   'text' => 'text-blue-700'],
];
?>
<div class="space-y-3 mb-6">
    <?php foreach ($flash as $type => $messages) : ?>
        <?php
        $style = $styles[$type] ?? $styles['info'];
        foreach ((array) $messages as $message) :
            ?>
            <div class="<?= $style['bg'] ?> border-l-4 <?= $style['border'] ?> p-4 rounded-md flex items-start justify-between gap-4"
                 x-data="{ show: true }" x-show="show" x-cloak>
                <p class="text-sm <?= $style['text'] ?>"><?= e($message) ?></p>
                <button type="button" @click="show = false" class="text-gray-400 hover:text-gray-600 flex-shrink-0" aria-label="Fechar aviso">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
</div>
