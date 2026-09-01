<?php
/**
 * Formulario de usuario, compartilhado por criar e editar.
 *
 * @var array<string,mixed>|null $user
 * @var string                   $action
 * @var string                   $submitLabel
 * @var array<string,string>     $roles
 * @var array<string,string>     $errors
 * @var array<string,mixed>      $old
 */

$isEdit = $user !== null;

$value = static function (string $field, ?array $user, array $old, string $default = ''): string {
    if (isset($old[$field])) {
        return (string) $old[$field];
    }

    return (string) ($user[$field] ?? $default);
};

$errorClass = static fn (string $field, array $errors): string =>
    isset($errors[$field]) ? 'border-red-500' : 'border-gray-300';
?>
<form method="POST" action="<?= e($action) ?>" class="space-y-6">
    <?= csrf_field() ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Dados do usuário</h3>

        <div class="space-y-4">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                    Nome completo <span class="text-red-600">*</span>
                </label>
                <input type="text" id="name" name="name" required maxlength="120"
                       value="<?= e($value('name', $user, $old)) ?>"
                       class="w-full rounded-lg border <?= $errorClass('name', $errors) ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary">
                <?php if (isset($errors['name'])) : ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['name']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                    E-mail <span class="text-red-600">*</span>
                </label>
                <input type="email" id="email" name="email" required maxlength="190"
                       value="<?= e($value('email', $user, $old)) ?>"
                       class="w-full rounded-lg border <?= $errorClass('email', $errors) ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary">
                <?php if (isset($errors['email'])) : ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['email']) ?></p>
                <?php else : ?>
                    <p class="text-xs text-gray-500 mt-1">Usado para entrar no painel.</p>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="role" class="block text-sm font-medium text-gray-700 mb-1">
                        Perfil <span class="text-red-600">*</span>
                    </label>
                    <select id="role" name="role" required
                            class="w-full rounded-lg border <?= $errorClass('role', $errors) ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary">
                        <?php foreach ($roles as $roleValue => $roleLabel) : ?>
                            <option value="<?= e($roleValue) ?>" <?= $value('role', $user, $old, 'operator') === $roleValue ? 'selected' : '' ?>>
                                <?= e($roleLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['role'])) : ?>
                        <p class="text-xs text-red-600 mt-1"><?= e($errors['role']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1">
                        Situação <span class="text-red-600">*</span>
                    </label>
                    <select id="status" name="status" required
                            class="w-full rounded-lg border <?= $errorClass('status', $errors) ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary">
                        <option value="active" <?= $value('status', $user, $old, 'active') === 'active' ? 'selected' : '' ?>>Ativo</option>
                        <option value="inactive" <?= $value('status', $user, $old, 'active') === 'inactive' ? 'selected' : '' ?>>Inativo</option>
                    </select>
                    <?php if (isset($errors['status'])) : ?>
                        <p class="text-xs text-red-600 mt-1"><?= e($errors['status']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">
            <?= $isEdit ? 'Alterar senha' : 'Senha de acesso' ?>
        </h3>

        <?php if ($isEdit) : ?>
            <p class="text-sm text-gray-600 mb-4">
                Deixe em branco para manter a senha atual.
            </p>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                    Senha <?php if (!$isEdit) : ?><span class="text-red-600">*</span><?php endif; ?>
                </label>
                <input type="password" id="password" name="password" <?= $isEdit ? '' : 'required' ?>
                       minlength="8" autocomplete="new-password"
                       class="w-full rounded-lg border <?= $errorClass('password', $errors) ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary">
                <?php if (isset($errors['password'])) : ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['password']) ?></p>
                <?php else : ?>
                    <p class="text-xs text-gray-500 mt-1">Mínimo de 8 caracteres.</p>
                <?php endif; ?>
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-700 mb-1">
                    Confirmar senha <?php if (!$isEdit) : ?><span class="text-red-600">*</span><?php endif; ?>
                </label>
                <input type="password" id="password_confirmation" name="password_confirmation" <?= $isEdit ? '' : 'required' ?>
                       minlength="8" autocomplete="new-password"
                       class="w-full rounded-lg border border-gray-300 px-4 py-2 text-sm focus:ring-primary focus:border-primary">
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <a href="<?= e(url('/usuarios')) ?>"
           class="px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors">
            Cancelar
        </a>
        <button type="submit"
                class="px-6 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
            <?= e($submitLabel) ?>
        </button>
    </div>
</form>
