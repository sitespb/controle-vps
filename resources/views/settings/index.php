<?php
/**
 * Configuracoes do sistema - secao 19 do PLAN.
 *
 * Os valores editados aqui sobrepoem os padroes de config/monitoring.php e
 * valem imediatamente para o painel e para os crons.
 *
 * @var array<string,array<int,array<string,mixed>>> $groups
 * @var array<string,string> $groupLabels
 * @var array<string,string> $system
 * @var array<int,array{tabela:string,linhas:int,tamanho:string}> $tableStats
 * @var array<string,int>    $volume
 * @var array<string,string> $errors
 */
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-900">Configuracoes do sistema</h2>
    <p class="text-sm text-gray-600 mt-1">
        Limites de alerta, coleta e retencao. As alteracoes passam a valer na proxima avaliacao,
        sem precisar reiniciar nada.
    </p>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

    <!-- ==================================================================
         FORMULARIO
         ================================================================== -->
    <div class="xl:col-span-2">
        <form method="POST" action="<?= e(url('/configuracoes')) ?>" class="space-y-6">
            <?= csrf_field() ?>

            <?php foreach ($groups as $groupKey => $settings) : ?>
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">
                        <?= e($groupLabels[$groupKey] ?? ucfirst($groupKey)) ?>
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <?php foreach ($settings as $setting) : ?>
                            <?php
                            $key      = (string) $setting['key'];
                            $hasError = isset($errors[$key]);
                            $inputId  = 'setting-' . preg_replace('/[^a-z0-9]/i', '-', $key);
                            ?>
                            <div>
                                <label for="<?= e($inputId) ?>" class="block text-sm font-medium text-gray-700 mb-1">
                                    <?= e($setting['label']) ?>
                                    <?php if (!empty($setting['unit'])) : ?>
                                        <span class="text-gray-400 font-normal">(<?= e($setting['unit']) ?>)</span>
                                    <?php endif; ?>
                                </label>

                                <input type="<?= \in_array($setting['type'], ['int', 'float'], true) ? 'number' : 'text' ?>"
                                       id="<?= e($inputId) ?>"
                                       name="settings[<?= e($key) ?>]"
                                       value="<?= e($setting['value']) ?>"
                                       <?= $setting['type'] === 'float' ? 'step="0.1"' : '' ?>
                                       <?= $setting['min_value'] !== null ? 'min="' . e(rtrim(rtrim((string) $setting['min_value'], '0'), '.')) . '"' : '' ?>
                                       <?= $setting['max_value'] !== null ? 'max="' . e(rtrim(rtrim((string) $setting['max_value'], '0'), '.')) . '"' : '' ?>
                                       class="w-full rounded-lg border <?= $hasError ? 'border-red-500' : 'border-gray-300' ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary">

                                <?php if ($hasError) : ?>
                                    <p class="text-xs text-red-600 mt-1"><?= e($errors[$key]) ?></p>
                                <?php elseif (!empty($setting['description'])) : ?>
                                    <p class="text-xs text-gray-500 mt-1 leading-relaxed"><?= e($setting['description']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4 flex flex-col sm:flex-row items-center justify-between gap-3">
                <p class="text-xs text-gray-500 leading-relaxed">
                    O limite <strong>critico</strong> deve sempre ser mais severo que o de <strong>atencao</strong> &mdash;
                    maior nos percentuais, menor nos dias de SSL. O painel valida isso antes de salvar.
                </p>
                <button type="submit"
                        class="px-8 py-2.5 bg-primary text-white text-sm font-semibold rounded-lg hover:bg-red-800 transition-colors shadow-sm whitespace-nowrap">
                    Salvar configuracoes
                </button>
            </div>
        </form>
    </div>

    <!-- ==================================================================
         LATERAL: SISTEMA E VOLUME
         ================================================================== -->
    <aside class="space-y-6">

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Sistema</h3>

            <dl class="space-y-3">
                <?php foreach ($system as $label => $value) : ?>
                    <div class="flex justify-between gap-4 text-sm">
                        <dt class="text-gray-500 flex-shrink-0"><?= e($label) ?></dt>
                        <dd class="text-gray-900 text-right truncate" title="<?= e($value) ?>"><?= e(str_limit($value, 26)) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-1">Volume do banco</h3>
            <p class="text-xs text-gray-500 mb-4">Maiores tabelas do schema.</p>

            <div class="max-h-72 overflow-y-auto scrollbar-thin">
                <table class="min-w-full">
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach (array_slice($tableStats, 0, 12) as $stat) : ?>
                            <tr>
                                <td class="py-2 text-sm text-gray-700"><?= e($stat['tabela']) ?></td>
                                <td class="py-2 text-sm text-gray-500 text-right whitespace-nowrap"><?= e($stat['tamanho']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-1">Manutencao automatica</h3>
            <p class="text-xs text-gray-500 mb-4 leading-relaxed">
                Estes scripts rodam pelo cron do painel. Consulte
                <code class="px-1 py-0.5 bg-gray-100 rounded">docs/INSTALACAO-LOCAL.md</code> para o agendamento.
            </p>

            <ul class="space-y-3 text-sm">
                <li class="flex gap-2">
                    <code class="px-1.5 py-0.5 bg-gray-100 rounded text-xs flex-shrink-0">process-alerts.php</code>
                    <span class="text-gray-600">detecta servidores offline, reavalia limites e SSL</span>
                </li>
                <li class="flex gap-2">
                    <code class="px-1.5 py-0.5 bg-gray-100 rounded text-xs flex-shrink-0">cleanup.php</code>
                    <span class="text-gray-600">aplica a retencao configurada acima</span>
                </li>
            </ul>
        </div>
    </aside>
</div>
