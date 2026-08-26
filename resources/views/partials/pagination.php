<?php
/**
 * Barra de paginacao - DESIGN.md secao 9.
 *
 * @var array{total:int,page:int,pages:int,per_page:int} $pagination
 * @var string                                           $basePath   Caminho da pagina (ex.: /sites)
 * @var array<string,mixed>                              $queryParams Filtros ativos, sem a pagina
 * @var string                                           $label      Nome do que esta sendo listado
 */

$pages   = max(1, (int) $pagination['pages']);
$current = max(1, (int) $pagination['page']);
$total   = (int) $pagination['total'];
$perPage = (int) $pagination['per_page'];
$label   = $label ?? 'registros';

$linkFor = static function (int $page) use ($basePath, $queryParams): string {
    $params = array_filter(
        $queryParams,
        static fn ($v): bool => $v !== '' && $v !== null && $v !== 0
    );
    $params['pagina'] = $page;

    return url($basePath) . '?' . http_build_query($params);
};

// Janela de no maximo 7 numeros em torno da pagina atual.
$from = max(1, $current - 3);
$to   = min($pages, $from + 6);
$from = max(1, $to - 6);

$firstRow = $total === 0 ? 0 : (($current - 1) * $perPage) + 1;
$lastRow  = min($total, $current * $perPage);
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-200 px-4 py-3 flex flex-col sm:flex-row items-center justify-between gap-4">

    <p class="text-sm text-gray-600">
        <?php if ($total === 0) : ?>
            Nenhum registro encontrado.
        <?php else : ?>
            Exibindo
            <span class="font-medium text-gray-900"><?= number_format($firstRow, 0, ',', '.') ?></span>
            a
            <span class="font-medium text-gray-900"><?= number_format($lastRow, 0, ',', '.') ?></span>
            de
            <span class="font-medium text-gray-900"><?= number_format($total, 0, ',', '.') ?></span>
            <?= e($label) ?>
        <?php endif; ?>
    </p>

    <?php if ($pages > 1) : ?>
        <nav class="flex items-center gap-1" aria-label="Paginacao">

            <?php if ($current > 1) : ?>
                <a href="<?= e($linkFor($current - 1)) ?>"
                   class="px-3 py-1.5 rounded-lg border text-sm border-gray-300 text-gray-700 hover:bg-gray-50">&lsaquo;</a>
            <?php else : ?>
                <span class="px-3 py-1.5 rounded-lg border text-sm border-gray-200 text-gray-300 cursor-not-allowed">&lsaquo;</span>
            <?php endif; ?>

            <?php if ($from > 1) : ?>
                <a href="<?= e($linkFor(1)) ?>" class="px-3 py-1.5 rounded-lg border text-sm border-gray-300 text-gray-700 hover:bg-gray-50">1</a>
                <?php if ($from > 2) : ?>
                    <span class="px-2 text-sm text-gray-400">&hellip;</span>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($p = $from; $p <= $to; $p++) : ?>
                <?php if ($p === $current) : ?>
                    <span class="px-3 py-1.5 rounded-lg border text-sm bg-primary text-white border-primary"><?= $p ?></span>
                <?php else : ?>
                    <a href="<?= e($linkFor($p)) ?>" class="px-3 py-1.5 rounded-lg border text-sm border-gray-300 text-gray-700 hover:bg-gray-50"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($to < $pages) : ?>
                <?php if ($to < $pages - 1) : ?>
                    <span class="px-2 text-sm text-gray-400">&hellip;</span>
                <?php endif; ?>
                <a href="<?= e($linkFor($pages)) ?>" class="px-3 py-1.5 rounded-lg border text-sm border-gray-300 text-gray-700 hover:bg-gray-50"><?= $pages ?></a>
            <?php endif; ?>

            <?php if ($current < $pages) : ?>
                <a href="<?= e($linkFor($current + 1)) ?>"
                   class="px-3 py-1.5 rounded-lg border text-sm border-gray-300 text-gray-700 hover:bg-gray-50">&rsaquo;</a>
            <?php else : ?>
                <span class="px-3 py-1.5 rounded-lg border text-sm border-gray-200 text-gray-300 cursor-not-allowed">&rsaquo;</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
