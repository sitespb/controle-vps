<?php
/**
 * Cadastro de usuario.
 *
 * @var array<string,string> $roles
 * @var array<string,string> $errors
 * @var array<string,mixed>  $old
 */

use App\Core\View;
?>

<div class="mb-6">
    <a href="<?= e(url('/usuarios')) ?>" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 mb-3">
        <svg class="h-4 w-4 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 12H5M12 19l-7-7 7-7" />
        </svg>
        Voltar para usuários
    </a>
    <h2 class="text-2xl font-bold text-gray-900">Novo usuário</h2>
</div>

<div class="max-w-3xl">
    <?= View::partial('users/_form', [
        'user'        => null,
        'action'      => url('/usuarios'),
        'submitLabel' => 'Cadastrar usuario',
        'roles'       => $roles,
        'errors'      => $errors,
        'old'         => $old,
    ]) ?>
</div>
