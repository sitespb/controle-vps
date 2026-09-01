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
        Instale o agente no VPS para que ele passe a enviar métricas, serviços e a lista de domínios.
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
                        <strong>Copie o token agora.</strong> Ele e exibido uma única vez.
                        O painel guarda apenas o hash &mdash; não ha como recuperá-lo depois.
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
                            O token deste servidor já foi exibido e não pode ser recuperado &mdash; o painel armazena
                            apenas o hash dele.
                            <br>
                            <span class="text-xs text-gray-500">
                                Token ativo: <code class="font-mono"><?= e($tokenInfo['token_prefix']) ?>&hellip;</code>
                                &middot; criado em <?= e(format_datetime($tokenInfo['created_at'])) ?>
                                &middot; último uso <?= e($tokenInfo['last_used_at'] === null ? 'nunca' : time_ago($tokenInfo['last_used_at'])) ?>
                            </span>
                        </p>
                    </div>
                <?php else : ?>
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded-md">
                        <p class="text-sm text-yellow-800 leading-relaxed">
                            <?php if ((int) $server['is_demo'] === 1) : ?>
                                Este é um servidor de <strong>demonstração</strong> e não possui agente instalado &mdash;
                                os dados dele foram gerados pelo seeder. Gere um token apenas se for reaproveitar este
                                registro para um VPS real.
                            <?php else : ?>
                                Este servidor ainda <strong>não possui um token ativo</strong>. Gere um novo token para
                                que o agente consiga se comunicar com o painel.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>

        <!-- ==============================================================
             INSTALACAO - UM COMANDO
             ==============================================================
             A tela antiga tinha cinco passos, sendo o primeiro um scp que
             exigia ter o projeto na maquina local. Agora o instalador busca o
             proprio agente no repositorio publico, entao sobra um comando so.
             Tudo que era passo 2 a 5 virou o bloco recolhido no fim: continua
             disponivel para quem precisa, sem competir com o caminho normal.
             ============================================================== -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900">Instalação no VPS</h3>
            <p class="text-sm text-gray-600 mt-1 mb-4">
                Conecte no servidor por SSH como <code class="px-1 py-0.5 bg-gray-100 rounded font-mono">root</code>
                e rode o comando abaixo. E o único passo.
            </p>

            <div class="relative">
                <pre id="cmd-install" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 pr-24 overflow-x-auto scrollbar-thin font-mono"><?= e($instructions['install_command']) ?></pre>
                <button type="button" data-copy="#cmd-install"
                        class="absolute top-2 right-2 px-3 py-1.5 rounded bg-gray-800 text-gray-200 text-xs hover:bg-gray-700 transition-colors">
                    <span data-copy-label>Copiar</span>
                </button>
            </div>

            <?php if (!$hasToken) : ?>
                <p class="text-xs text-yellow-800 mt-2">
                    O token real não aparece mais nesta tela. Gere um novo token para obter o comando pronto.
                </p>
            <?php endif; ?>

            <p class="text-xs text-gray-500 mt-3 leading-relaxed">
                O instalador escolhe o PHP 8.1+ do servidor &mdash; em CyberPanel e aaPanel o
                <code class="font-mono">php</code> do sistema costuma ser antigo demais &mdash;, baixa o agente
                <code class="font-mono"><?= e($instructions['agent_ref']) ?></code>, cria o
                <code class="font-mono">config.php</code> com permissão 600, registra o cron a cada
                <?= (int) round($instructions['interval'] / 60) ?> minuto(s) e testa a conexão.
                Se já houver um agente de <em>outro</em> servidor no destino, ele para e avisa em vez de
                assumir a identidade.
            </p>

            <p class="text-xs text-gray-500 mt-2">
                Antes de executar, o script pode ser lido na integra em
                <a href="<?= e($instructions['script_url']) ?>" target="_blank" rel="noopener noreferrer"
                   class="text-primary hover:underline break-all"><?= e($instructions['script_url']) ?></a>.
            </p>
        </div>

        <!-- ==============================================================
             ESTADO DA INSTALACAO - ao vivo
             ==============================================================
             Substitui o antigo passo "rode a mao para conferir". A pergunta
             que o operador realmente tem e "deu certo?", e a tela passa a
             responder sozinha: guarda o last_seen_at do carregamento e avisa
             quando ele muda. Comparar com o valor inicial evita que um
             servidor que ja reportava ontem apareca como recem-conectado.
             ============================================================== -->
        <div id="agent-watch"
             class="bg-white rounded-xl shadow-sm border border-gray-200 p-6"
             data-server="<?= e((string) $server['id']) ?>"
             data-last-seen="<?= e((string) ($server['last_seen_at'] ?? '')) ?>">

            <h3 class="text-lg font-semibold text-gray-900 mb-4">Estado da instalação</h3>

            <div class="flex items-start gap-3">
                <span id="agent-watch-dot"
                      class="flex-shrink-0 mt-1 h-3 w-3 rounded-full bg-gray-300"></span>
                <div class="min-w-0 flex-1">
                    <p id="agent-watch-title" class="text-sm font-medium text-gray-900">
                        <?php if ($server['last_seen_at'] === null) : ?>
                            Aguardando o primeiro contato do agente&hellip;
                        <?php else : ?>
                            Último contato <?= e(time_ago($server['last_seen_at'])) ?>
                        <?php endif; ?>
                    </p>
                    <p id="agent-watch-detail" class="text-xs text-gray-500 mt-1">
                        Esta tela verifica sozinha a cada 5 segundos. Pode deixar aberta enquanto roda o comando.
                    </p>
                </div>
            </div>
        </div>

        <!-- ==============================================================
             INSTALACAO MANUAL - recolhida
             ==============================================================
             Cobre dois casos reais: servidor sem saida para a internet, e
             quem prefere conferir o que sera gravado antes de gravar.
             ============================================================== -->
        <details class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 group">
            <summary class="cursor-pointer list-none flex items-center justify-between gap-4">
                <span>
                    <span class="text-sm font-semibold text-gray-900">Instalação manual ou sem internet no servidor</span>
                    <span class="block text-xs text-gray-500 mt-0.5">
                        Envio por scp, conteúdo do config.php, linha de cron e execução manual.
                    </span>
                </span>
                <svg class="h-4 w-4 flex-shrink-0 text-gray-400 transition-transform group-open:rotate-180"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 9l6 6 6-6" />
                </svg>
            </summary>

            <div class="mt-5 space-y-5 border-t border-gray-100 pt-5">

                <div>
                    <p class="text-sm font-medium text-gray-900">1. Envie a pasta do agente</p>
                    <p class="text-xs text-gray-500 mt-1 mb-2">
                        Necessário apenas quando o VPS não alcanca o github.com.
                    </p>
                    <div class="relative">
                        <pre id="cmd-upload" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 pr-20 overflow-x-auto scrollbar-thin font-mono">scp -r agent/ root@<?= e($server['ip'] ?? 'IP_DO_SERVIDOR') ?>:<?= e($instructions['path']) ?></pre>
                        <button type="button" data-copy="#cmd-upload"
                                class="absolute top-2 right-2 px-2 py-1 rounded bg-gray-800 text-gray-300 text-xs hover:bg-gray-700 transition-colors">
                            <span data-copy-label>Copiar</span>
                        </button>
                    </div>
                </div>

                <div>
                    <p class="text-sm font-medium text-gray-900">2. Rode o instalador local</p>
                    <div class="relative mt-2">
                        <pre id="cmd-manual" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 pr-20 overflow-x-auto scrollbar-thin font-mono"><?= e($instructions['manual_command']) ?></pre>
                        <button type="button" data-copy="#cmd-manual"
                                class="absolute top-2 right-2 px-2 py-1 rounded bg-gray-800 text-gray-300 text-xs hover:bg-gray-700 transition-colors">
                            <span data-copy-label>Copiar</span>
                        </button>
                    </div>
                </div>

                <div>
                    <p class="text-sm font-medium text-gray-900">Conteúdo do config.php</p>
                    <p class="text-xs text-gray-500 mt-1 mb-2">
                        O instalador gera este arquivo em
                        <code class="font-mono"><?= e($instructions['path']) ?>/config.php</code>, com permissão 600.
                    </p>
                    <div class="relative">
                        <pre id="cfg-block" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 pr-20 overflow-x-auto scrollbar-thin font-mono"><?= e($instructions['config_block']) ?></pre>
                        <button type="button" data-copy="#cfg-block"
                                class="absolute top-2 right-2 px-2 py-1 rounded bg-gray-800 text-gray-300 text-xs hover:bg-gray-700 transition-colors">
                            <span data-copy-label>Copiar</span>
                        </button>
                    </div>
                </div>

                <div>
                    <p class="text-sm font-medium text-gray-900">Linha de cron</p>
                    <p class="text-xs text-gray-500 mt-1 mb-2">
                        O instalador já registra esta linha, substituindo
                        <code class="font-mono">CAMINHO_DO_PHP</code> pelo binário que encontrou &mdash; o painel
                        não tem como saber esse caminho. Para conferir depois:
                        <code class="font-mono">crontab -l | grep agent.php</code>.
                    </p>
                    <div class="relative">
                        <pre id="cron-line" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 pr-20 overflow-x-auto scrollbar-thin font-mono"><?= e($instructions['cron_line']) ?></pre>
                        <button type="button" data-copy="#cron-line"
                                class="absolute top-2 right-2 px-2 py-1 rounded bg-gray-800 text-gray-300 text-xs hover:bg-gray-700 transition-colors">
                            <span data-copy-label>Copiar</span>
                        </button>
                    </div>
                </div>

                <div>
                    <p class="text-sm font-medium text-gray-900">Executar uma coleta na mao</p>
                    <p class="text-xs text-gray-500 mt-1 mb-2">
                        Use o caminho completo do PHP que o instalador mostrou no fim &mdash;
                        <code class="font-mono">php</code> puro pode ser a versão antiga do sistema.
                    </p>
                    <div class="relative">
                        <pre id="cmd-run" class="bg-gray-900 text-gray-100 text-xs rounded-lg p-4 pr-20 overflow-x-auto scrollbar-thin font-mono">CAMINHO_DO_PHP <?= e($instructions['path']) ?>/agent.php --verbose</pre>
                        <button type="button" data-copy="#cmd-run"
                                class="absolute top-2 right-2 px-2 py-1 rounded bg-gray-800 text-gray-300 text-xs hover:bg-gray-700 transition-colors">
                            <span data-copy-label>Copiar</span>
                        </button>
                    </div>
                </div>

                <p class="text-xs text-gray-500">
                    Deu problema? O guia de diagnóstico está em
                    <code class="font-mono">docs/TROUBLESHOOTING.md</code>, na seção <em>Agente</em>.
                </p>
            </div>
        </details>
    </div>

    <!-- ==================================================================
         LATERAL
         ================================================================== -->
    <aside class="space-y-6">

        <!-- Estado da comunicacao -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Comunicação</h3>

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
                    <dt class="text-gray-500">Último heartbeat</dt>
                    <dd class="text-gray-900"><?= e(time_ago($server['last_seen_at'])) ?></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Última métrica</dt>
                    <dd class="text-gray-900"><?= e(time_ago($server['last_metric_at'])) ?></dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Versão do agente</dt>
                    <dd class="text-gray-900"><?= $server['agent_version'] === null ? '<span class="text-gray-400">--</span>' : 'v' . e($server['agent_version']) ?></dd>
                </div>
                <?php if ($tokenInfo !== null) : ?>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Token em uso</dt>
                        <dd class="text-gray-900 font-mono text-xs"><?= e($tokenInfo['token_prefix']) ?>&hellip;</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">Último uso do token</dt>
                        <dd class="text-gray-900"><?= e($tokenInfo['last_used_at'] === null ? 'nunca' : time_ago($tokenInfo['last_used_at'])) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>

        <!-- Como a autenticacao funciona -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Como a conexão e protegida</h3>

            <ul class="space-y-3 text-sm text-gray-600 leading-relaxed">
                <li class="flex gap-2">
                    <span class="text-green-600 flex-shrink-0">&check;</span>
                    <span>O token <strong>nunca trafega</strong> na rede. O agente assina cada requisição com HMAC-SHA256.</span>
                </li>
                <li class="flex gap-2">
                    <span class="text-green-600 flex-shrink-0">&check;</span>
                    <span>Cada envio carrega timestamp e nonce &mdash; uma requisição capturada não pode ser reenviada.</span>
                </li>
                <li class="flex gap-2">
                    <span class="text-green-600 flex-shrink-0">&check;</span>
                    <span>A assinatura cobre o corpo inteiro: alterar um byte invalida o envio.</span>
                </li>
                <li class="flex gap-2">
                    <span class="text-green-600 flex-shrink-0">&check;</span>
                    <span>O fluxo e so de entrada. O painel <strong>não envia comandos</strong>, e o agente não executa nada vindo da API.</span>
                </li>
            </ul>
        </div>

        <!-- Regenerar token -->
        <div class="bg-white rounded-xl shadow-sm border border-yellow-200 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Gerar novo token</h3>
            <p class="text-sm text-gray-600 mb-4 leading-relaxed">
                Use quando o token atual for perdido ou comprometido. O anterior e
                <strong>invalidado na hora</strong> e o agente para de reportar até ser reconfigurado.
            </p>

            <form method="POST" action="<?= e(url('/servidores/' . $server['id'] . '/token')) ?>"
                  data-confirm="Gerar um novo token? O token atual será invalidado imediatamente e o agente parara de reportar até ser reconfigurado.">
                <?= csrf_field() ?>
                <button type="submit"
                        class="w-full px-4 py-2 border-2 border-primary text-primary text-sm font-medium rounded-lg hover:bg-red-50 transition-colors">
                    Regenerar token
                </button>
            </form>
        </div>
    </aside>
</div>

<script>
/**
 * Acompanha o primeiro contato do agente enquanto a pagina fica aberta.
 *
 * Compara last_seen_at com o valor que veio renderizado: assim um servidor
 * que ja reportava antes nao aparece como "recem-conectado" so por abrir a
 * tela. Quando muda, a instalacao deu certo - e a pergunta "deu certo?" se
 * responde sozinha, sem mandar ninguem rodar comando de conferencia.
 */
document.addEventListener('DOMContentLoaded', () => {
    const box = document.getElementById('agent-watch');

    if (!box || !window.ControleVPS) {
        return;
    }

    const serverId = box.dataset.server;
    const inicial  = box.dataset.lastSeen || '';

    const dot    = document.getElementById('agent-watch-dot');
    const titulo = document.getElementById('agent-watch-title');
    const detalhe = document.getElementById('agent-watch-detail');

    let timer = null;
    let falhas = 0;

    function pintar(cor) {
        dot.className = 'flex-shrink-0 mt-1 h-3 w-3 rounded-full ' + cor;
    }

    async function verificar() {
        try {
            const dados = await window.ControleVPS.api(
                '/api/v1/servers/' + serverId + '/agent-status'
            );

            falhas = 0;

            const visto = dados.last_seen_at || '';

            if (visto !== '' && visto !== inicial) {
                // Instalar pela primeira vez e ver um servidor que ja rodava
                // ha meses reportar de novo sao situacoes diferentes: chamar
                // as duas de "primeiro contato" seria mentira na segunda.
                const primeiraVez = inicial === '';
                const versao = dados.agent_version ? ' - agente v' + dados.agent_version : '';

                pintar('bg-green-500');

                titulo.textContent = primeiraVez
                    ? 'Agente conectado.'
                    : 'Agente reportando normalmente.';

                detalhe.textContent = primeiraVez
                    ? 'Primeiro contato recebido' + versao + '. A coleta segue pelo cron a partir de agora.'
                    : 'Contato recebido agora' + versao + '.';

                clearInterval(timer);
                return;
            }

            pintar('bg-yellow-400 animate-pulse');

            titulo.textContent = inicial === ''
                ? 'Aguardando o primeiro contato do agente...'
                : 'Aguardando um novo contato do agente...';

            detalhe.textContent = 'Ultimo contato: ' + (dados.last_seen_ago || 'nunca')
                + '. Verificando a cada 5 segundos.';
        } catch (e) {
            falhas++;

            // Erro isolado nao interessa: a rede oscila. Persistindo, paramos
            // de insistir em vez de bater no painel para sempre.
            if (falhas >= 5) {
                clearInterval(timer);
                pintar('bg-gray-300');
                detalhe.textContent = 'Nao foi possivel consultar o estado. Recarregue a pagina.';
            }
        }
    }

    pintar('bg-yellow-400 animate-pulse');
    verificar();
    timer = setInterval(verificar, 5000);

    // Sair da pagina com um setInterval vivo deixa requisicao pendente.
    window.addEventListener('beforeunload', () => clearInterval(timer));
});
</script>
