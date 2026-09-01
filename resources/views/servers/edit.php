<?php
/**
 * Edicao de servidor.
 *
 * Somente os dados de cadastro sao editaveis. Sistema operacional, kernel,
 * uptime e demais campos vem do agente e sao sobrescritos a cada coleta.
 *
 * @var array<string,mixed> $server
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */

use App\Core\View;
?>

<div class="mb-6">
    <a href="<?= e(url('/servidores/' . $server['id'])) ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-3">
        <svg class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7" />
        </svg>
        Voltar para o servidor
    </a>
    <h2 class="text-2xl font-bold text-gray-900">Editar <?= e($server['name']) ?></h2>
    <p class="text-sm text-gray-600 mt-1">
        Alterar estes dados não afeta o agente instalado nem invalida o token atual.
    </p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-2">
        <?= View::partial('servers/_form', [
            'server'      => $server,
            'action'      => url('/servidores/' . $server['id']),
            'submitLabel' => 'Salvar alteracoes',
            'errors'      => $errors,
            'old'         => $old,
        ]) ?>
    </div>

    <aside class="space-y-6">

        <!-- Dados vindos do agente (somente leitura) -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Coletado pelo agente</h3>
            <p class="text-xs text-gray-500 mb-4">
                Estes campos são preenchidos automaticamente e não podem ser editados aqui.
            </p>

            <dl class="space-y-3">
                <?php
                $readOnly = [
                    'Sistema operacional' => trim(($server['os_name'] ?? '') . ' ' . ($server['os_version'] ?? '')),
                    'Kernel'              => $server['kernel'] ?? null,
                    'Arquitetura'         => $server['arch'] ?? null,
                    'vCPUs'               => $server['cpu_cores'] ?? null,
                    'IP publico'          => $server['public_ip'] ?? null,
                    'CyberPanel'          => $server['cyberpanel_version'] ?? null,
                    'Versao do agente'    => $server['agent_version'] ?? null,
                    'Uptime'              => $server['uptime'] === null ? null : format_uptime((int) $server['uptime']),
                ];

                foreach ($readOnly as $label => $value) :
                    ?>
                    <div class="flex justify-between gap-4 text-sm">
                        <dt class="text-gray-500 flex-shrink-0"><?= e($label) ?></dt>
                        <dd class="text-gray-900 text-right truncate">
                            <?= ($value === null || $value === '') ? '<span class="text-gray-400">--</span>' : e($value) ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>

        <!-- Zona de risco -->
        <div class="bg-white rounded-xl shadow-sm border border-red-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Excluir servidor</h3>
            <p class="text-sm text-gray-600 mb-4 leading-relaxed">
                A exclusão remove também <strong>todas as métricas, sites, checagens, certificados e alertas</strong>
                deste servidor. Esta ação não pode ser desfeita.
            </p>

            <form method="POST" action="<?= e(url('/servidores/' . $server['id'] . '/excluir')) ?>"
                  data-confirm="Excluir permanentemente o servidor e todo o seu histórico?">
                <?= csrf_field() ?>

                <label for="confirm_name" class="block text-sm font-medium text-gray-700 mb-1">
                    Digite <span class="font-mono text-red-700"><?= e($server['name']) ?></span> para confirmar
                </label>
                <input type="text" id="confirm_name" name="confirm_name" autocomplete="off"
                       class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:ring-primary focus:border-primary mb-3">

                <button type="submit"
                        class="w-full px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors">
                    Excluir servidor
                </button>
            </form>
        </div>
    </aside>
</div>
