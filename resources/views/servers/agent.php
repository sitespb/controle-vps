<?php
/**
 * Instrucoes de instalacao do agente - secao 12 do PLAN.
 *
 * O token completo so aparece aqui, e apenas quando acabou de ser gerado
 * (cadastro ou regeneracao). Recarregar a pagina ja nao o mostra mais: ele
 * viaja pela sessao, com validade de 15 minutos, e e consumido na leitura.
 *
 * @var array<string,mixed>      $server
 * @var string|null              $token       Texto puro, so na primeira exibicao
 * @var array<string,mixed>|null $tokenInfo   Metadados do token ativo
 * @var array<string,string>     $instructions
 */

$hasToken = $token !== null && $token !== '';
?>

<div class="mb-6">
    <a href="<?= e(url('/servidores/' . $server['id'])) ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-3">
        <svg class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7" />
        </svg>
        Voltar para <?= e($server['name']) ?>
    </a>
    <h2 class="text-2xl font-bold text-gray-900">Agente de monitoramento</h2>
    <p class="text-sm text-gray-600 mt-1">
        Instale o agente no VPS para que ele passe a enviar metricas, servicos e a lista de dominios.
    </p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-2 space-y-6">

        <!-- ==============================================================
             CREDENCIAIS
             ============================================================== -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Credenciais do servidor</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Server ID</label>
                    <input readonly value="<?= e((string) $server['id']) ?>"
                           class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 text-gray-500 font-mono">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">URL da API central</label>
                    <input readonly value="<?= e($instructions['api_url']) ?>"
                           class="mt-1 w-full px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 text-gray-500 font-mono">
                </div>
            </div>

            <?php if ($hasToken) : ?>

                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md mb-4">
                    <p class="text-sm text-yellow-800 leading-relaxed">
                        <strong>Copie o token agora.</strong> Ele e exibido uma unica vez.
                        O painel guarda apenas o hash &mdash; nao ha como recupera-lo depois.
                        Se perder, gere um novo (o anterior deixa de funcionar).
                    </p>
                </div>

                <label class="block text-sm font-medium text-gray-700 mb-1">Token do agente</label>
                <div class="flex">
                    <input readonly id="token-value" value="<?= e($token) ?>"
                           class="w-full rounded-l-lg border border-gray-300 px-4 py-2 text-sm font-mono bg-gray-50 focus:ring-primary focus:border-primary">
                    <button type="button" data-copy="#token-value"
                            class="inline-flex items-center px-4 py-2 border border-l-0 border-gray-300 rounded-r-lg text-sm text-gray-700 hover:bg-gray-50 transition-colors whitespace-nowrap">
                        <svg class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="9" y="9" width="13" height="13" rx="2" />
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                        </svg>
                        <span data-copy-label>Copiar</span>
                    </button>
                </div>

            <?php else : ?>

                <?php if ($tokenInfo !== null) : ?>
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                        <p class="text-sm text-gray-600 leading-relaxed">
                            O token deste servidor ja foi exibido e nao pode ser recuperado &mdash; o painel armazena
                            apenas o hash dele.
                            <br>
                            <span class="text-xs text-gray-500">
                                Token ativo: <code class="font-mono"><?= e($tokenInfo['token_prefix']) ?>&hellip;</code>
                                &middot; criado em <?= e(format_datetime($tokenInfo['created_at'])) ?>
                                &middot; ultimo uso <?= e($tokenInfo['last_used_at'] === null ? 'nunca' : time_ago($tokenInfo['last_used_at'])) ?>
                            </span>
                        </p>
                    </div>
                <?php else : ?>
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
                        <p class="text-sm text-yellow-800 leading-relaxed">
                            <?php if ((int) $server['is_demo'] === 1) : ?>
                                Este e um servidor de <strong>demonstracao</strong> e nao possui agente instalado &mdash;
                                os dados dele foram gerados pelo seeder. Gere um token apenas se for reaproveitar este
                                registro para um VPS real.
                            <?php else : ?>
                                Este servidor ainda <strong>nao possui um token ativo</strong>. Gere um novo token para
                                que o agente consiga se comunicar com o painel.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>

        <!-- ==============================================================
             PASSO A PASSO
             ============================================================== -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Instalacao no VPS</h3>

            <ol class="space-y-6">

                <li>
                    <div class="flex items-start gap-3">
                        <span class="flex-shrink-0 h-6 w-6 rounded-full bg-gray-900 text-white text-xs font-bold flex items-center justify-center">1</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900">Rode este comando no VPS, como root</p>
                            <p class="text-xs text-gray-500 mt-1 mb-2">
                                E o unico passo. O instalador baixa o agente
                                (<?= e($instructions['agent_ref']) ?>), escolhe o PHP 8.1+ do servidor,
                                cria o <code class="px-1 py-0.5 bg-gray-100 rounded">config.php</code>,
                                registra o cron e testa a conexao.
                            </p>
                            <div class="relative">
                                <pre id="cmd-install" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto scrollbar-thin font-mono"><?= e($instructions['install_command']) ?></pre>
                                <button type="button" data-copy="#cmd-install"
                                        class="absolute top-2 right-2 px-2 py-1 rounded bg-gray-800 text-gray-300 text-xs hover:bg-gray-700 transition-colors">
                                    <span data-copy-label>Copiar</span>
                                </button>
                            </div>
                            <?php if (!$hasToken) : ?>
                                <p class="text-xs text-yellow-800 mt-2">
                                    Substitua <code class="px-1 py-0.5 bg-yellow-50 rounded">SEU_TOKEN_AQUI</code> pelo token real.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>

                <li>
                    <div class="flex items-start gap-3">
                        <span class="flex-shrink-0 h-6 w-6 rounded-full bg-gray-900 text-white text-xs font-bold flex items-center justify-center">2</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900">Conferindo manualmente (opcional)</p>
                            <p class="text-xs text-gray-500 mt-1 mb-2">
                                Se preferir configurar na mao, o conteudo do
                                <code class="px-1 py-0.5 bg-gray-100 rounded"><?= e($instructions['path']) ?>/config.php</code> equivale a:
                            </p>
                            <div class="relative">
                                <pre id="cfg-block" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto scrollbar-thin font-mono"><?= e($instructions['config_block']) ?></pre>
                                <button type="button" data-copy="#cfg-block"
                                        class="absolute top-2 right-2 px-2 py-1 rounded bg-gray-800 text-gray-300 text-xs hover:bg-gray-700 transition-colors">
                                    <span data-copy-label>Copiar</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </li>

                <li>
                    <div class="flex items-start gap-3">
                        <span class="flex-shrink-0 h-6 w-6 rounded-full bg-gray-900 text-white text-xs font-bold flex items-center justify-center">3</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900">Cron do agente</p>
                            <p class="text-xs text-gray-500 mt-1 mb-2">
                                O instalador ja registra esta linha. Coleta a cada
                                <?= (int) round($instructions['interval'] / 60) ?> minuto(s).
                                Ele substitui <code class="font-mono">CAMINHO_DO_PHP</code> pelo
                                binario PHP 8.1+ que encontrar no servidor — em CyberPanel e
                                aaPanel o <code class="font-mono">php</code> do sistema costuma
                                ser antigo demais. Para conferir depois:
                                <code class="font-mono">crontab -l | grep agent.php</code>.
                            </p>
                            <div class="relative">
                                <pre id="cron-line" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto scrollbar-thin font-mono"><?= e($instructions['cron_line']) ?></pre>
                                <button type="button" data-copy="#cron-line"
                                        class="absolute top-2 right-2 px-2 py-1 rounded bg-gray-800 text-gray-300 text-xs hover:bg-gray-700 transition-colors">
                                    <span data-copy-label>Copiar</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </li>

                <li>
                    <div class="flex items-start gap-3">
                        <span class="flex-shrink-0 h-6 w-6 rounded-full bg-gray-900 text-white text-xs font-bold flex items-center justify-center">4</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-900">Primeira execucao</p>
                            <p class="text-xs text-gray-500 mt-1 mb-2">
                                Rode uma vez a mao para conferir a saida antes de confiar no cron.
                            </p>
                            <div class="relative">
                                <pre id="cmd-run" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 overflow-x-auto scrollbar-thin font-mono">php <?= e($instructions['path']) ?>/agent.php --verbose</pre>
                                <button type="button" data-copy="#cmd-run"
                                        class="absolute top-2 right-2 px-2 py-1 rounded bg-gray-800 text-gray-300 text-xs hover:bg-gray-700 transition-colors">
                                    <span data-copy-label>Copiar</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </li>
            </ol>
        </div>
    </div>

    <!-- ==================================================================
         LATERAL
         ================================================================== -->
    <aside class="space-y-6">

        <!-- Estado da comunicacao -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Comunicacao</h3>

            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Status</dt>
                    <dd>
                        <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= status_badge_class((string) $server['status']) ?>">
                            <?= e(status_label((string) $server['status'])) ?>
                        </span>
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Ultimo heartbeat</dt>
                    <dd class="text-gray-900"><?= e(time_ago($server['last_seen_at'])) ?></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Ultima metrica</dt>
                    <dd class="text-gray-900"><?= e(time_ago($server['last_metric_at'])) ?></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Versao do agente</dt>
                    <dd class="text-gray-900"><?= $server['agent_version'] === null ? '<span class="text-gray-400">--</span>' : 'v' . e($server['agent_version']) ?></dd>
                </div>
                <?php if ($tokenInfo !== null) : ?>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Token em uso</dt>
                        <dd class="text-gray-900 font-mono text-xs"><?= e($tokenInfo['token_prefix']) ?>&hellip;</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Ultimo uso do token</dt>
                        <dd class="text-gray-900"><?= e($tokenInfo['last_used_at'] === null ? 'nunca' : time_ago($tokenInfo['last_used_at'])) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <!-- Como a autenticacao funciona -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Como a conexao e protegida</h3>

            <ul class="space-y-3 text-sm text-gray-600 leading-relaxed">
                <li class="flex gap-2">
                    <span class="text-green-600 flex-shrink-0">&check;</span>
                    <span>O token <strong>nunca trafega</strong> na rede. O agente assina cada requisicao com HMAC-SHA256.</span>
                </li>
                <li class="flex gap-2">
                    <span class="text-green-600 flex-shrink-0">&check;</span>
                    <span>Cada envio carrega timestamp e nonce &mdash; uma requisicao capturada nao pode ser reenviada.</span>
                </li>
                <li class="flex gap-2">
                    <span class="text-green-600 flex-shrink-0">&check;</span>
                    <span>A assinatura cobre o corpo inteiro: alterar um byte invalida o envio.</span>
                </li>
                <li class="flex gap-2">
                    <span class="text-green-600 flex-shrink-0">&check;</span>
                    <span>O fluxo e so de entrada. O painel <strong>nao envia comandos</strong>, e o agente nao executa nada vindo da API.</span>
                </li>
            </ul>
        </div>

        <!-- Regenerar token -->
        <div class="bg-white rounded-xl shadow-sm border border-yellow-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Gerar novo token</h3>
            <p class="text-sm text-gray-600 mb-4 leading-relaxed">
                Use quando o token atual for perdido ou comprometido. O anterior e
                <strong>invalidado na hora</strong> e o agente para de reportar ate ser reconfigurado.
            </p>

            <form method="POST" action="<?= e(url('/servidores/' . $server['id'] . '/token')) ?>"
                  data-confirm="Gerar um novo token? O token atual sera invalidado imediatamente e o agente parara de reportar ate ser reconfigurado.">
                <?= csrf_field() ?>
                <button type="submit"
                        class="w-full px-4 py-2 border-2 border-primary text-primary text-sm font-medium rounded-lg hover:bg-red-50 transition-colors">
                    Regenerar token
                </button>
            </form>
        </div>
    </aside>
</div>
