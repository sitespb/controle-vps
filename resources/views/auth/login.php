<?php
/**
 * Tela de login - DESIGN.md secao 9 (card de autenticacao).
 *
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 * @var string               $turnstileKey  vazio quando o captcha esta desligado
 */

$turnstileKey = $turnstileKey ?? '';
?>
<div class="w-full max-w-md bg-white p-8 rounded-xl shadow-lg">

    <div class="text-center mb-6">
        <div class="mx-auto h-12 w-12 bg-primary rounded-xl flex items-center justify-center text-white shadow-lg border border-red-800">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="4" width="20" height="7" rx="2" />
                <rect x="2" y="13" width="20" height="7" rx="2" />
                <line x1="6" y1="7.5" x2="6.01" y2="7.5" />
                <line x1="6" y1="16.5" x2="6.01" y2="16.5" />
            </svg>
        </div>
        <h1 class="mt-4 text-2xl font-bold text-gray-900">Controle VPS</h1>
        <p class="mt-1 text-sm text-gray-600">Central de monitoramento de servidores</p>
    </div>

    <form method="POST" action="<?= e(url('/login')) ?>" class="space-y-4">
        <?= csrf_field() ?>

        <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
            <input type="email"
                   id="email"
                   name="email"
                   value="<?= e(old($old, 'email')) ?>"
                   required
                   autofocus
                   autocomplete="username"
                   class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:ring-primary focus:border-primary"
                   placeholder="voce@empresa.com.br">
            <?php if (isset($errors['email'])) : ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['email']) ?></p>
            <?php endif; ?>
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Senha</label>
            <div class="relative" x-data="{ show: false }">
                <input :type="show ? 'text' : 'password'"
                       type="password"
                       id="password"
                       name="password"
                       required
                       autocomplete="current-password"
                       class="w-full rounded-lg border border-gray-300 pl-4 pr-10 py-2 text-sm focus:ring-primary focus:border-primary"
                       placeholder="Sua senha">
                <button type="button"
                        @click="show = !show"
                        class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
                        aria-label="Mostrar ou ocultar a senha">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path x-show="!show" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                        <circle x-show="!show" cx="12" cy="12" r="3" />
                        <path x-show="show" x-cloak d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22" />
                    </svg>
                </button>
            </div>
            <?php if (isset($errors['password'])) : ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['password']) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($turnstileKey !== '') : ?>
            <!-- Cloudflare Turnstile.
                 Renderizado so quando o captcha esta ativo E com as duas
                 chaves preenchidas (ver TurnstileService::isEnabled): um
                 widget que nunca valida impediria o login sem proteger nada. -->
            <div class="flex justify-center">
                <div class="cf-turnstile"
                     data-sitekey="<?= e($turnstileKey) ?>"
                     data-language="pt-br"
                     data-theme="light"></div>
            </div>
        <?php endif; ?>

        <button type="submit"
                class="w-full py-2.5 px-4 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
            Entrar
        </button>
    </form>

    <?php if ($turnstileKey !== '') : ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>

    <div class="mt-6 pt-5 border-t border-gray-200">
        <p class="text-xs text-gray-500 leading-relaxed">
            Esqueceu a senha? Um administrador pode redefini-la em
            <span class="font-medium text-gray-700">Configuracoes &rsaquo; Usuarios</span>,
            ou pelo console:
            <code class="px-1 py-0.5 bg-gray-100 rounded text-[11px]">php bin/console.php user:password</code>
        </p>
    </div>
</div>
