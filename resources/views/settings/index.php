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
    <h2 class="text-2xl font-bold text-gray-900">Configurações do sistema</h2>
    <p class="text-sm text-gray-600 mt-1">
        Limites de alerta, coleta e retenção. As alterações passam a valer na próxima avaliação,
        sem precisar reiniciar nada.
    </p>
</div>
<!-- ======================================================================
     ABAS
     ======================================================================
     A aba ativa vem por querystring (?aba=), e nao so por JavaScript, para
     que salvar um formulario devolva o operador a aba onde ele estava.
     ====================================================================== -->
<div x-data="{ aba: '<?= e($aba) ?>' }">

    <div class="border-b border-gray-200 mb-6">
        <nav class="-mb-px flex gap-6">
            <button type="button" @click="aba = 'sistema'"
                    :class="aba === 'sistema' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                Sistema
            </button>

            <button type="button" @click="aba = 'recaptcha'"
                    :class="aba === 'recaptcha' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                Recaptcha
                <?php if ($turnstileAtivo) : ?>
                    <span class="ml-1.5 px-1.5 py-0.5 bg-green-100 text-green-800 text-[10px] font-bold uppercase rounded">ativo</span>
                <?php endif; ?>
            </button>
        </nav>
    </div>

    <div x-show="aba === 'sistema'" x-cloak>
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
                    O limite <strong>crítico</strong> deve sempre ser mais severo que o de <strong>atenção</strong> &mdash;
                    maior nos percentuais, menor nos dias de SSL. O painel valida isso antes de salvar.
                </p>
                <button type="submit"
                        class="px-8 py-2.5 bg-primary text-white text-sm font-semibold rounded-lg hover:bg-red-800 transition-colors shadow-sm whitespace-nowrap">
                    Salvar configurações
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
            <h3 class="text-lg font-semibold text-gray-900 mb-1">Manutenção automática</h3>
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
                    <span class="text-gray-600">aplica a retenção configurada acima</span>
                </li>
            </ul>
        </div>
    </aside>
</div>
    </div>

    <!-- ==================================================================
         ABA RECAPTCHA - Cloudflare Turnstile
         ================================================================== -->
    <div x-show="aba === 'recaptcha'" x-cloak class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <div class="xl:col-span-2 space-y-6">

            <form method="POST" action="<?= e(url('/configuracoes/turnstile')) ?>"
                  class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-5">
                <?= csrf_field() ?>

                <label class="flex items-center justify-between gap-4 pb-4 border-b border-gray-100">
                    <span>
                        <span class="text-sm font-medium text-gray-900">Exigir verificação no login</span>
                        <span class="block text-xs text-gray-500 mt-1">
                            O widget so aparece com as duas chaves preenchidas &mdash; um captcha que nunca
                            valida impediria o login sem proteger nada.
                        </span>
                    </span>
                    <input type="checkbox" name="enabled" value="1" <?= $turnstileAtivo ? 'checked' : '' ?>
                           class="h-5 w-9 rounded-full appearance-none bg-gray-300 checked:bg-primary transition-colors cursor-pointer relative
                                  before:content-[''] before:absolute before:top-0.5 before:left-0.5 before:h-4 before:w-4
                                  before:rounded-full before:bg-white before:transition-transform checked:before:translate-x-4">
                </label>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Chave do site (site key)</label>
                    <input type="text" name="site_key" value="<?= e($turnstile['site_key'] ?? '') ?>"
                           placeholder="0x4AAAAAAA..." autocomplete="off"
                           class="mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:ring-primary focus:border-primary">
                    <p class="text-xs text-gray-500 mt-1">
                        Pública: vai no HTML da tela de login. Pode ser vista por qualquer visitante.
                    </p>
                </div>

                <div x-data="{ ver: false }">
                    <label class="block text-sm font-medium text-gray-700">Chave secreta (secret key)</label>
                    <div class="relative">
                        <input :type="ver ? 'text' : 'password'" name="secret_key" value=""
                               placeholder="<?= $turnstileHasSecret ? '&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;' : 'não configurada' ?>"
                               autocomplete="new-password"
                               class="mt-1 w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg text-sm font-mono focus:ring-primary focus:border-primary">
                        <button type="button" @click="ver = !ver" tabindex="-1"
                                :aria-label="ver ? 'Ocultar chave' : 'Mostrar chave'"
                                class="absolute inset-y-0 right-0 px-3 flex items-center text-gray-400 hover:text-gray-600">
                            <svg x-show="!ver" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" />
                            </svg>
                            <svg x-show="ver" x-cloak class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" />
                                <path d="M1 1l22 22" />
                            </svg>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        <?php if ($turnstileHasSecret) : ?>
                            Já configurada e cifrada no banco. Deixe em branco para manter.
                        <?php else : ?>
                            Fica somente no servidor, cifrada. Nunca aparece no HTML.
                        <?php endif; ?>
                    </p>
                </div>

                <div class="flex justify-end pt-2 border-t border-gray-100">
                    <button type="submit" class="px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors">
                        Salvar
                    </button>
                </div>
            </form>

            <!-- Teste -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6"
                 x-data="testeTurnstile('<?= e(url('/configuracoes/turnstile/testar')) ?>')">
                <h3 class="text-lg font-semibold text-gray-900">Testar as chaves</h3>
                <p class="text-xs text-gray-500 mt-1 mb-4">
                    Confere as chaves <strong>já salvas</strong> junto a Cloudflare, sem precisar resolver
                    um captcha nem sair desta tela.
                </p>

                <button type="button" @click="executar()" :disabled="carregando"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                    <span x-show="!carregando">Testar configuração</span>
                    <span x-show="carregando" x-cloak>Testando...</span>
                </button>

                <template x-if="resultado">
                    <div class="mt-4 rounded-lg p-4 text-sm"
                         :class="resultado.ok ? 'bg-green-50 border border-green-200 text-green-900' : 'bg-red-50 border border-red-200 text-red-900'">
                        <p class="font-medium" x-text="resultado.ok ? 'Configuracao valida.' : resultado.error"></p>
                        <template x-if="resultado.detail && resultado.detail.length">
                            <ul class="mt-2 text-xs opacity-90 list-disc list-inside">
                                <template x-for="linha in resultado.detail" :key="linha">
                                    <li x-text="linha"></li>
                                </template>
                            </ul>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <aside class="space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-3">Onde obter as chaves</h3>
                <ol class="text-sm text-gray-600 space-y-2 list-decimal list-inside leading-relaxed">
                    <li>Acesse <span class="font-medium text-gray-900">dash.cloudflare.com</span> &rsaquo; Turnstile</li>
                    <li>Crie um widget e informe o domínio <code class="px-1 py-0.5 bg-gray-100 rounded text-xs"><?= e($hostname) ?></code></li>
                    <li>Escolha o modo <span class="font-medium">Managed</span> (o recomendado)</li>
                    <li>Copie a <span class="font-medium">Site Key</span> e a <span class="font-medium">Secret Key</span></li>
                </ol>
                <p class="text-xs text-gray-500 mt-3">
                    O Turnstile e gratuito e não exige que o visitante clique em imagens.
                </p>
            </div>

            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                <h3 class="text-sm font-semibold text-amber-900 mb-1">Se a Cloudflare sair do ar</h3>
                <p class="text-xs text-amber-800 leading-relaxed">
                    O login <strong>continua funcionando</strong>. Uma falha de rede ao consultar a
                    Cloudflare não tranca o painel &mdash; a proteção contra forca bruta já e feita pelo
                    limite por IP e pela contagem de tentativas por usuário. Um token invalido continua
                    sendo recusado normalmente.
                </p>
            </div>
        </aside>
    </div>
</div>

<script>
function testeTurnstile(endpoint) {
    return {
        carregando: false,
        resultado: null,

        async executar() {
            this.carregando = true;
            this.resultado  = null;

            try {
                this.resultado = await window.ControleVPS.api(endpoint, { method: 'POST', body: {} });
            } catch (e) {
                this.resultado = { ok: false, error: e.message, detail: [] };
            } finally {
                this.carregando = false;
            }
        },
    };
}
</script>
