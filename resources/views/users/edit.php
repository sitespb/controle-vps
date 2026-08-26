<?php
/**
 * Edicao de usuario.
 *
 * @var array<string,mixed>  $user
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
        Voltar para usuarios
    </a>
    <h2 class="text-2xl font-bold text-gray-900">Editar <?= e($user['name']) ?></h2>
    <p class="text-sm text-gray-600 mt-1">
        Cadastrado em <?= e(format_datetime($user['created_at'])) ?>
        &middot; ultimo acesso <?= $user['last_login_at'] === null ? 'nunca' : e(time_ago($user['last_login_at'])) ?>
        <?php if ($user['last_login_ip'] !== null) : ?>
            &middot; de <span class="font-mono"><?= e($user['last_login_ip']) ?></span>
        <?php endif; ?>
    </p>
</div>

<div class="max-w-3xl">
    <?= View::partial('users/_form', [
        'user'        => $user,
        'action'      => url('/usuarios/' . $user['id']),
        'submitLabel' => 'Salvar alteracoes',
        'roles'       => $roles,
        'errors'      => $errors,
        'old'         => $old,
    ]) ?>
</div>
