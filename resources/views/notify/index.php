<?php
/**
 * Avisos ao administrador - e-mail (SMTP) e WhatsApp (RyzeAPI).
 *
 * Duas abas na mesma pagina. A aba ativa vem por querystring (?aba=), e nao
 * so por JavaScript, para que salvar um formulario devolva o operador a aba
 * onde ele estava.
 *
 * SEGREDOS: o valor gravado NUNCA volta para a tela, nem mascarado. O campo
 * comeca vazio e so mostra bolinhas quando ja existe algo gravado; salvar sem
 * digitar mantem o valor atual. O icone de olho revela apenas o que o operador
 * acabou de digitar.
 *
 * @var array<string,string>            $email
 * @var array<string,string>            $whatsapp
 * @var bool                            $emailHasSecret  ha senha SMTP gravada
 * @var bool                            $whatsHasSecret  ha token gravado
 * @var array<int,array<string,mixed>>  $log
 * @var string                          $aba
 * @var int                             $windowHours     janela por dominio
 * @var int                             $hourlyCap       teto por canal, por hora
 */

$emailAtivo = ($email['enabled'] ?? '0') === '1';
$whatsAtivo = ($whatsapp['enabled'] ?? '0') === '1';

$campo = 'mt-1 w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-primary focus:border-primary';
$rotulo = 'block text-sm font-medium text-gray-700';
$dica = 'text-xs text-gray-500 mt-1';
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-gray-900">Avisos</h2>
    <p class="text-sm text-gray-600 mt-1">
        Receba um aviso quando um site sair do ar. Um monitoramento que ninguém olha não avisa nada.
    </p>
</div>

<!-- ======================================================================
     LIMITE DE ENVIO - informado antes das abas, porque vale para as duas
     ====================================================================== -->
<div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
    <p class="text-sm text-blue-900">
        <strong>Limite de envio.</strong>
        Cada domínio gera no máximo <strong>1 aviso a cada
        <?= (int) $windowHours ?> horas</strong>,
        e cada canal envia no máximo <strong><?= (int) $hourlyCap ?> mensagens por hora</strong>.
    </p>
    <p class="text-xs text-blue-800 mt-1">
        A primeira regra evita que um site instável avise a cada coleta. A segunda protege o provedor
        quando um servidor inteiro cai e dezenas de domínios ficam offline ao mesmo tempo.
    </p>
</div>

<!-- ======================================================================
     ABAS
     ====================================================================== -->
<div x-data="{ aba: '<?= e($aba) ?>' }">

    <div class="border-b border-gray-200 mb-6">
        <nav class="-mb-px flex gap-6">
            <button type="button" @click="aba = 'email'"
                    :class="aba === 'email' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                E-mail
                <?php if ($emailAtivo) : ?>
                    <span class="ml-1.5 px-1.5 py-0.5 bg-green-100 text-green-800 text-[10px] font-bold uppercase rounded">ativo</span>
                <?php endif; ?>
            </button>

            <button type="button" @click="aba = 'whatsapp'"
                    :class="aba === 'whatsapp' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                WhatsApp
                <?php if ($whatsAtivo) : ?>
                    <span class="ml-1.5 px-1.5 py-0.5 bg-green-100 text-green-800 text-[10px] font-bold uppercase rounded">ativo</span>
                <?php endif; ?>
            </button>
        </nav>
    </div>

    <!-- ==================================================================
         ABA E-MAIL
         ================================================================== -->
    <div x-show="aba === 'email'" x-cloak class="space-y-6">

        <form method="POST" action="<?= e(url('/avisos/email')) ?>"
              class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-5">
            <?= csrf_field() ?>

            <label class="flex items-center justify-between gap-4 pb-4 border-b border-gray-100">
                <span>
                    <span class="text-sm font-medium text-gray-900">Enviar avisos por e-mail</span>
                    <span class="block <?= $dica ?>">Desligado, nenhum e-mail sai — nem os de teste automático.</span>
                </span>
                <input type="checkbox" name="enabled" value="1" <?= $emailAtivo ? 'checked' : '' ?>
                       class="h-5 w-9 rounded-full appearance-none bg-gray-300 checked:bg-primary transition-colors cursor-pointer relative
                              before:content-[''] before:absolute before:top-0.5 before:left-0.5 before:h-4 before:w-4
                              before:rounded-full before:bg-white before:transition-transform checked:before:translate-x-4">
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="sm:col-span-2">
                    <label class="<?= $rotulo ?>">Servidor SMTP</label>
                    <input type="text" name="smtp_host" value="<?= e($email['smtp_host'] ?? '') ?>"
                           placeholder="smtp.gmail.com" class="<?= $campo ?>">
                </div>
                <div>
                    <label class="<?= $rotulo ?>">Porta</label>
                    <input type="number" name="smtp_port" value="<?= e($email['smtp_port'] ?? '587') ?>"
                           min="1" max="65535" class="<?= $campo ?>">
                </div>
            </div>

            <div>
                <label class="<?= $rotulo ?>">Seguranca</label>
                <select name="smtp_security" class="<?= $campo ?>">
                    <?php foreach (['tls' => 'STARTTLS (porta 587) — padrao do Gmail', 'ssl' => 'SSL/TLS direto (porta 465)', 'none' => 'Sem criptografia (nao recomendado)'] as $valor => $texto) : ?>
                        <option value="<?= e($valor) ?>" <?= ($email['smtp_security'] ?? 'tls') === $valor ? 'selected' : '' ?>>
                            <?= e($texto) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="<?= $rotulo ?>">Usuário</label>
                    <input type="text" name="smtp_user" value="<?= e($email['smtp_user'] ?? '') ?>"
                           placeholder="você@gmail.com" autocomplete="off" class="<?= $campo ?>">
                </div>

                <!-- Campo sensivel: mascarado, com olho para revelar -->
                <div x-data="{ ver: false }">
                    <label class="<?= $rotulo ?>">Senha</label>
                    <div class="relative">
                        <input :type="ver ? 'text' : 'password'" name="smtp_password" value=""
                               placeholder="<?= $emailHasSecret ? "&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" : "nao configurada" ?>"
                               autocomplete="new-password" class="<?= $campo ?> pr-10">
                        <button type="button" @click="ver = !ver" tabindex="-1"
                                :aria-label="ver ? 'Ocultar senha' : 'Mostrar senha'"
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
                    <p class="<?= $dica ?>">
                        <?php if ($emailHasSecret) : ?>
                            Já configurada. Deixe em branco para manter.
                        <?php else : ?>
                            No Gmail use uma <strong>Senha de app</strong>, não a senha da conta.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="<?= $rotulo ?>">Remetente</label>
                    <input type="email" name="from_email" value="<?= e($email['from_email'] ?? '') ?>"
                           placeholder="igual ao usuário" class="<?= $campo ?>">
                    <p class="<?= $dica ?>">Em branco, usa o próprio usuário do SMTP.</p>
                </div>
                <div>
                    <label class="<?= $rotulo ?>">Nome do remetente</label>
                    <input type="text" name="from_name" value="<?= e($email['from_name'] ?? 'Controle VPS') ?>" class="<?= $campo ?>">
                </div>
            </div>

            <div>
                <label class="<?= $rotulo ?>">Destinatários</label>
                <textarea name="recipients" rows="2" placeholder="voce@empresa.com.br, plantao@empresa.com.br"
                          class="<?= $campo ?> font-mono"><?= e($email['recipients'] ?? '') ?></textarea>
                <p class="<?= $dica ?>">Separe por virgula, ponto-e-virgula ou quebra de linha. Endereços invalidos são ignorados.</p>
            </div>

            <div class="flex justify-end pt-2 border-t border-gray-100">
                <button type="submit" class="px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors">
                    Salvar configuração
                </button>
            </div>
        </form>

        <!-- Teste -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6"
             x-data="testeCanal('<?= e(url('/avisos/email/testar')) ?>')">
            <h3 class="text-lg font-semibold text-gray-900">Testar envio</h3>
            <p class="<?= $dica ?> mb-4">
                Usa a configuração <strong>já salva</strong>. Salve antes de testar uma alteração.
            </p>

            <div class="flex flex-col sm:flex-row gap-3">
                <input type="email" x-model="destino" placeholder="e-mail que vai receber o teste"
                       class="<?= $campo ?> mt-0 flex-1">
                <button type="button" @click="executar()" :disabled="carregando"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 whitespace-nowrap">
                    <span x-show="!carregando">Enviar teste</span>
                    <span x-show="carregando" x-cloak>Enviando...</span>
                </button>
            </div>

            <template x-if="resultado">
                <div class="mt-4 rounded-lg p-4 text-sm"
                     :class="resultado.ok ? 'bg-green-50 border border-green-200 text-green-900' : 'bg-red-50 border border-red-200 text-red-900'">
                    <p class="font-medium" x-text="resultado.ok ? 'E-mail enviado. Confira a caixa de entrada (e o spam).' : resultado.error"></p>

                    <template x-if="resultado.detail && resultado.detail.length">
                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs opacity-80">Ver dialogo com o servidor SMTP</summary>
                            <pre class="mt-2 text-[11px] bg-gray-900 text-gray-100 rounded p-3 overflow-x-auto" x-text="resultado.detail.join('\n')"></pre>
                        </details>
                    </template>
                </div>
            </template>
        </div>
    </div>

    <!-- ==================================================================
         ABA WHATSAPP
         ================================================================== -->
    <div x-show="aba === 'whatsapp'" x-cloak class="space-y-6">

        <form method="POST" action="<?= e(url('/avisos/whatsapp')) ?>"
              class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-5">
            <?= csrf_field() ?>

            <label class="flex items-center justify-between gap-4 pb-4 border-b border-gray-100">
                <span>
                    <span class="text-sm font-medium text-gray-900">Enviar avisos por WhatsApp</span>
                    <span class="block <?= $dica ?>">Requer uma instância conectada na RyzeAPI.</span>
                </span>
                <input type="checkbox" name="enabled" value="1" <?= $whatsAtivo ? 'checked' : '' ?>
                       class="h-5 w-9 rounded-full appearance-none bg-gray-300 checked:bg-primary transition-colors cursor-pointer relative
                              before:content-[''] before:absolute before:top-0.5 before:left-0.5 before:h-4 before:w-4
                              before:rounded-full before:bg-white before:transition-transform checked:before:translate-x-4">
            </label>

            <div>
                <label class="<?= $rotulo ?>">URL da API</label>
                <input type="url" name="base_url" value="<?= e($whatsapp['base_url'] ?? 'https://ryzeapi.cloud') ?>" class="<?= $campo ?>">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="<?= $rotulo ?>">Nome da instância</label>
                    <input type="text" name="instance" value="<?= e($whatsapp['instance'] ?? '') ?>"
                           placeholder="minha-instância" autocomplete="off" class="<?= $campo ?>">
                    <p class="<?= $dica ?>">O mesmo nome usado ao criar a instância na RyzeAPI.</p>
                </div>

                <!-- Campo sensivel -->
                <div x-data="{ ver: false }">
                    <label class="<?= $rotulo ?>">Token da instância</label>
                    <div class="relative">
                        <input :type="ver ? 'text' : 'password'" name="token" value=""
                               placeholder="<?= $whatsHasSecret ? "&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" : "nao configurado" ?>"
                               autocomplete="new-password" class="<?= $campo ?> pr-10">
                        <button type="button" @click="ver = !ver" tabindex="-1"
                                :aria-label="ver ? 'Ocultar token' : 'Mostrar token'"
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
                    <p class="<?= $dica ?>">
                        <?php if ($whatsHasSecret) : ?>
                            Já configurado. Deixe em branco para manter.
                        <?php else : ?>
                            Use o <strong>TokenInstance</strong>, não o TokenAccount.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div>
                <label class="<?= $rotulo ?>">Números de destino</label>
                <textarea name="recipients" rows="2" placeholder="5583999999999, 5511988888888"
                          class="<?= $campo ?> font-mono"><?= e($whatsapp['recipients'] ?? '') ?></textarea>
                <p class="<?= $dica ?>">Com código do pais e DDD, so números. Separe por virgula ou quebra de linha.</p>
            </div>

            <div class="flex justify-end pt-2 border-t border-gray-100">
                <button type="submit" class="px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors">
                    Salvar configuração
                </button>
            </div>
        </form>

        <!-- Teste -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6"
             x-data="testeCanal('<?= e(url('/avisos/whatsapp/testar')) ?>')">
            <h3 class="text-lg font-semibold text-gray-900">Testar configuração</h3>
            <p class="<?= $dica ?> mb-4">
                Sem número, apenas confere se a instância está conectada — útil para validar token sem gastar mensagem.
            </p>

            <div class="flex flex-col sm:flex-row gap-3">
                <input type="text" x-model="destino" placeholder="5583999999999 (opcional)"
                       class="<?= $campo ?> mt-0 flex-1 font-mono">
                <button type="button" @click="executar()" :disabled="carregando"
                        class="px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 whitespace-nowrap">
                    <span x-show="!carregando">Testar</span>
                    <span x-show="carregando" x-cloak>Testando...</span>
                </button>
            </div>

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
</div>

<!-- ======================================================================
     HISTORICO
     ======================================================================
     Responde a pergunta mais comum depois de configurar: "por que nao
     recebi?". Um `skipped` com o motivo vale mais que qualquer suporte.
     ====================================================================== -->
<div class="bg-white rounded-xl shadow-sm border border-gray-200 mt-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Últimos envios</h3>
    </div>

    <?php if ($log === []) : ?>
        <p class="px-6 py-8 text-sm text-gray-500 text-center">Nenhum aviso enviado ainda.</p>
    <?php else : ?>
        <div class="overflow-x-auto scrollbar-thin">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <?php foreach (['Quando', 'Canal', 'Evento', 'Destino', 'Resultado'] as $th) : ?>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider first:pl-6"><?= e($th) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($log as $linha) : ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 pl-6 whitespace-nowrap text-sm text-gray-500"><?= e(time_ago($linha['created_at'])) ?></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?= $linha['channel'] === 'email' ? 'E-mail' : 'WhatsApp' ?></td>
                            <td class="px-4 py-3 text-sm text-gray-700">
                                <?= e($linha['domain'] ?? $linha['event']) ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500 font-mono text-xs break-all"><?= e($linha['recipient']) ?></td>
                            <td class="px-4 py-3 text-sm">
                                <?php
                                $cor = match ($linha['status']) {
                                    'sent'    => 'bg-green-100 text-green-800',
                                    'failed'  => 'bg-red-100 text-red-800',
                                    default   => 'bg-gray-100 text-gray-700',
                                };
                                $texto = match ($linha['status']) {
                                    'sent'    => 'enviado',
                                    'failed'  => 'falhou',
                                    default   => 'suprimido',
                                };
                                ?>
                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?= $cor ?>"><?= $texto ?></span>
                                <?php if ($linha['error'] !== null) : ?>
                                    <span class="block text-xs text-gray-500 mt-0.5"><?= e($linha['error']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
/**
 * Componente compartilhado pelos dois botoes de teste.
 *
 * O resultado aparece na própria tela porque o dialogo com o SMTP e a
 * informação mais útil quando algo da errado - e ela não caberia num flash.
 */
function testeCanal(endpoint) {
    return {
        destino: '',
        carregando: false,
        resultado: null,

        async executar() {
            this.carregando = true;
            this.resultado  = null;

            try {
                this.resultado = await window.ControleVPS.api(endpoint, {
                    method: 'POST',
                    body: { to: this.destino },
                });
            } catch (e) {
                // O helper lanca em erro de HTTP; a mensagem já vem tratada.
                this.resultado = { ok: false, error: e.message, detail: [] };
            } finally {
                this.carregando = false;
            }
        },
    };
}
</script>
