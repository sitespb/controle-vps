<?php
/**
 * Cadastro de servidor - secao 12 do PLAN.
 *
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */

use App\Core\View;
?>

<div class="mb-6">
    <a href="<?= e(url('/servidores')) ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-3">
        <svg class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7" />
        </svg>
        Voltar para servidores
    </a>
    <h2 class="text-2xl font-bold text-gray-900">Novo servidor</h2>
    <p class="text-sm text-gray-600 mt-1">
        Ao salvar, o painel gera uma identificacao unica e um token seguro, e mostra as instrucoes de instalacao do agente.
    </p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-2">
        <?= View::partial('servers/_form', [
            'server'      => null,
            'action'      => url('/servidores'),
            'submitLabel' => 'Cadastrar servidor',
            'errors'      => $errors,
            'old'         => $old,
        ]) ?>
    </div>

    <!-- Explicacao do que acontece a seguir -->
    <aside class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 h-fit">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">O que acontece ao salvar</h3>

        <ol class="space-y-4">
            <?php
            $steps = [
                ['Identificacao unica', 'O servidor recebe um ID numerico e um UID interno.'],
                ['Token seguro', 'Um token aleatorio criptografico e gerado. Ele aparece UMA UNICA VEZ na tela seguinte.'],
                ['Instalacao do agente', 'O painel monta o comando e o arquivo de configuracao prontos para colar no VPS.'],
                ['Primeira coleta', 'Assim que o agente rodar, o servidor sai de "Desconhecido" e passa a reportar metricas e sites.'],
            ];

            foreach ($steps as $index => [$stepTitle, $stepText]) :
                ?>
                <li class="flex gap-3">
                    <span class="flex-shrink-0 h-6 w-6 rounded-full bg-gray-100 text-gray-600 text-xs font-bold flex items-center justify-center">
                        <?= $index + 1 ?>
                    </span>
                    <div>
                        <p class="text-sm font-medium text-gray-900"><?= e($stepTitle) ?></p>
                        <p class="text-xs text-gray-500 mt-0.5 leading-relaxed"><?= e($stepText) ?></p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="mt-6 bg-blue-50 border-l-4 border-blue-500 p-4 rounded-md">
            <p class="text-sm text-blue-700 leading-relaxed">
                Os sites hospedados <strong>nao</strong> precisam ser cadastrados: o agente descobre os dominios do
                CyberPanel automaticamente.
            </p>
        </div>
    </aside>
</div>
