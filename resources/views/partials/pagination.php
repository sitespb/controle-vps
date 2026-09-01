<?php
/**
 * Barra de paginacao - DESIGN.md secao 9.
 *
 * @var array{total:int,page:int,pages:int,per_page:int} $pagination
 * @var string                                           $basePath   Caminho da pagina (ex.: /sites)
 * @var array<string,mixed>                              $queryParams Filtros ativos, sem a pagina
 * @var string                                           $label      Nome do que esta sendo listado
 * @var array<int,int>|null                              $perPageOptions
 *
 * O seletor de "itens por pagina" e OPCIONAL: so aparece quando quem chama
 * passa `perPageOptions`. Nem toda listagem aceita `por_pagina` na
 * querystring, e um seletor que nao muda nada seria pior do que nenhum.
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

$perPageOptions = $perPageOptions ?? [];
?>
<div class="bg-white rounded-xl shadow-sm border border-gray-200 px-4 py-3 flex flex-col sm:flex-row items-center justify-between gap-4">

    <div class="flex items-center gap-4 flex-wrap justify-center sm:justify-start">
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

        <?php if ($perPageOptions !== []) : ?>
            <!-- Formulario proprio: a barra de paginacao fica FORA do
                 formulario de filtros, entao o seletor carrega os filtros
                 ativos em campos ocultos para nao perde-los ao mudar.

                 `pagina` fica de fora de proposito: trocar 10 por 100 na
                 pagina 7 deve levar ao inicio da lista, nao a uma pagina que
                 talvez nem exista mais. -->
            <form method="GET" action="<?= e(url($basePath)) ?>" class="flex items-center gap-2">
                <?php foreach ($queryParams as $chave => $valor) : ?>
                    <?php if ($valor !== '' && $valor !== null && $valor !== 0 && $chave !== 'por_pagina') : ?>
                        <input type="hidden" name="<?= e((string) $chave) ?>" value="<?= e((string) $valor) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>

                <label for="por_pagina" class="text-sm text-gray-600 whitespace-nowrap">Exibir</label>
                <select id="por_pagina" name="por_pagina" data-auto-submit
                        class="rounded-lg border border-gray-300 px-2 py-1 text-sm focus:ring-primary focus:border-primary">
                    <?php foreach ($perPageOptions as $opcao) : ?>
                        <option value="<?= (int) $opcao ?>" <?= $perPage === (int) $opcao ? 'selected' : '' ?>>
                            <?= (int) $opcao ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="text-sm text-gray-600 whitespace-nowrap">por página</span>

                <!-- Sem JavaScript o select nao se envia sozinho; o botao
                     garante que a opcao continue utilizavel. -->
                <noscript>
                    <button type="submit" class="px-2 py-1 rounded-lg border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">Aplicar</button>
                </noscript>
            </form>
        <?php endif; ?>
    </div>

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
