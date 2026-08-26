<?php
/**
 * Logs de auditoria - secao 31 do PLAN.
 *
 * Somente leitura. Senhas, tokens completos e assinaturas nunca chegam aqui:
 * o Logger::redact() mascara essas chaves antes da gravacao.
 *
 * @var array<int,array<string,mixed>> $logs
 * @var array<string,int>              $pagination
 * @var array<string,mixed>            $filters
 * @var array<int,string>              $actions
 * @var array<int,array<string,mixed>> $users
 */

use App\Core\View;

$levelClass = static fn (string $level): string => match ($level) {
    'error'   => 'bg-red-100 text-red-800',
    'warning' => 'bg-yellow-100 text-yellow-800',
    default   => 'bg-blue-100 text-blue-800',
};

$hasFilters = array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0) !== [];
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-900">Logs do sistema</h2>
    <p class="text-sm text-gray-600 mt-1">
        Login, alteracoes de cadastro, regeneracao de token, comunicacao dos agentes e erros de API.
    </p>
</div>

<!-- Filtros -->
<form method="GET" action="<?= e(url('/logs')) ?>" class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 mb-6">
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-4">

        <div class="xl:col-span-2">
            <label for="q" class="block text-sm font-medium text-gray-700 mb-1">Pesquisar</label>
            <input type="search" id="q" name="q" value="<?= e($filters['search'] ?? '') ?>"
                   data-auto-submit-delay="600" placeholder="Descricao ou responsavel"
                   class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:ring-primary focus:border-primary">
        </div>

        <div>
            <label for="acao" class="block text-sm font-medium text-gray-700 mb-1">Acao</label>
            <select id="acao" name="acao" data-auto-submit
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary focus:border-primary">
                <option value="">Todas</option>
                <?php foreach ($actions as $action) : ?>
                    <option value="<?= e($action) ?>" <?= ($filters['action'] ?? '') === $action ? 'selected' : '' ?>><?= e($action) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="nivel" class="block text-sm font-medium text-gray-700 mb-1">Nivel</label>
            <select id="nivel" name="nivel" data-auto-submit
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary focus:border-primary">
                <option value="">Todos</option>
                <?php foreach (['info' => 'Informativo', 'warning' => 'Atencao', 'error' => 'Erro'] as $value => $label) : ?>
                    <option value="<?= e($value) ?>" <?= ($filters['level'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="de" class="block text-sm font-medium text-gray-700 mb-1">De</label>
            <input type="date" id="de" name="de" value="<?= e($filters['from'] ?? '') ?>" data-auto-submit
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary focus:border-primary">
        </div>

        <div>
            <label for="ate" class="block text-sm font-medium text-gray-700 mb-1">Ate</label>
            <input type="date" id="ate" name="ate" value="<?= e($filters['to'] ?? '') ?>" data-auto-submit
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-primary focus:border-primary">
        </div>
    </div>

    <?php if ($hasFilters) : ?>
        <div class="mt-3">
            <a href="<?= e(url('/logs')) ?>" class="text-sm text-gray-500 hover:text-gray-700">Limpar filtros</a>
        </div>
    <?php endif; ?>
</form>

<?php if ($logs === []) : ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 px-6 py-16 text-center">
        <p class="text-gray-500">Nenhum registro encontrado.</p>
    </div>

<?php else : ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-4">
        <div class="overflow-x-auto scrollbar-thin">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nivel</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acao</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descricao</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Responsavel</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($logs as $log) : ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?= e(format_datetime($log['created_at'], 'd/m/Y H:i:s')) ?>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $levelClass((string) $log['level']) ?>">
                                    <?= e(status_label((string) $log['level'])) ?>
                                </span>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap">
                                <code class="text-xs text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded"><?= e($log['action']) ?></code>
                            </td>

                            <td class="px-4 py-3 text-sm text-gray-700">
                                <?= e($log['description']) ?>
                                <?php if (!empty($log['entity_type']) && !empty($log['entity_id'])) : ?>
                                    <?php if ($log['entity_type'] === 'server') : ?>
                                        <a href="<?= e(url('/servidores/' . $log['entity_id'])) ?>" class="text-xs text-gray-400 hover:text-primary ml-1">#<?= (int) $log['entity_id'] ?></a>
                                    <?php else : ?>
                                        <span class="text-xs text-gray-400 ml-1"><?= e($log['entity_type']) ?> #<?= (int) $log['entity_id'] ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?= e($log['user_name'] ?? $log['actor'] ?? 'sistema') ?>
                            </td>

                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-400 font-mono">
                                <?= e($log['ip'] ?? '--') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?= View::partial('partials/pagination', [
        'pagination'  => $pagination,
        'basePath'    => '/logs',
        'queryParams' => [
            'q'     => $filters['search'] ?? '',
            'acao'  => $filters['action'] ?? '',
            'nivel' => $filters['level'] ?? '',
            'de'    => $filters['from'] ?? '',
            'ate'   => $filters['to'] ?? '',
        ],
        'label'       => 'registro(s)',
    ]) ?>

<?php endif; ?>
