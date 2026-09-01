<?php
/**
 * Formulario de servidor, compartilhado por criar e editar (secao 12 do PLAN).
 *
 * Campos: nome, provedor, hostname, IP, descricao.
 *
 * @var array<string,mixed>|null $server
 * @var string                   $action
 * @var string                   $submitLabel
 * @var array<string,string>     $errors
 * @var array<string,mixed>      $old
 */

$value = static function (string $field, ?array $server, array $old): string {
    if (isset($old[$field])) {
        return (string) $old[$field];
    }

    return (string) ($server[$field] ?? '');
};

$errorClass = static fn (string $field, array $errors): string =>
    isset($errors[$field]) ? 'border-red-500' : 'border-gray-300';
?>
<form method="POST" action="<?= e($action) ?>" class="space-y-6">
    <?= csrf_field() ?>

    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Identificação</h3>

        <div class="space-y-4">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                    Nome do servidor <span class="text-red-600">*</span>
                </label>
                <input type="text" id="name" name="name" required maxlength="120"
                       value="<?= e($value('name', $server, $old)) ?>"
                       placeholder="Ex.: VPS Joao Pessoa"
                       class="w-full rounded-lg border <?= $errorClass('name', $errors) ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary">
                <?php if (isset($errors['name'])) : ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['name']) ?></p>
                <?php else : ?>
                    <p class="text-xs text-gray-500 mt-1">Como este servidor aparecera no painel e nos alertas.</p>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="provider" class="block text-sm font-medium text-gray-700 mb-1">Provedor</label>
                    <input type="text" id="provider" name="provider" maxlength="120"
                           value="<?= e($value('provider', $server, $old)) ?>"
                           placeholder="Ex.: Hostinger, Contabo, Hetzner"
                           class="w-full rounded-lg border <?= $errorClass('provider', $errors) ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary">
                    <?php if (isset($errors['provider'])) : ?>
                        <p class="text-xs text-red-600 mt-1"><?= e($errors['provider']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="ip" class="block text-sm font-medium text-gray-700 mb-1">Endereço IP</label>
                    <input type="text" id="ip" name="ip" maxlength="45"
                           value="<?= e($value('ip', $server, $old)) ?>"
                           placeholder="Ex.: 45.132.74.18"
                           class="w-full rounded-lg border <?= $errorClass('ip', $errors) ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary">
                    <?php if (isset($errors['ip'])) : ?>
                        <p class="text-xs text-red-600 mt-1"><?= e($errors['ip']) ?></p>
                    <?php else : ?>
                        <p class="text-xs text-gray-500 mt-1">IPv4 ou IPv6. O agente confirma este dado na primeira coleta.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <label for="hostname" class="block text-sm font-medium text-gray-700 mb-1">Hostname</label>
                <input type="text" id="hostname" name="hostname" maxlength="190"
                       value="<?= e($value('hostname', $server, $old)) ?>"
                       placeholder="Ex.: jp01.seudominio.com.br"
                       class="w-full rounded-lg border <?= $errorClass('hostname', $errors) ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary">
                <?php if (isset($errors['hostname'])) : ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['hostname']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Descrição</label>
                <textarea id="description" name="description" rows="3" maxlength="2000"
                          placeholder="Anotacoes internas: finalidade do servidor, contrato, contato do suporte..."
                          class="w-full rounded-lg border <?= $errorClass('description', $errors) ?> px-4 py-2 text-sm focus:ring-primary focus:border-primary"><?= e($value('description', $server, $old)) ?></textarea>
                <?php if (isset($errors['description'])) : ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['description']) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="flex justify-end gap-3">
        <a href="<?= e(url($server === null ? '/servidores' : '/servidores/' . $server['id'])) ?>"
           class="px-4 py-2 border border-gray-300 text-gray-700 text-sm rounded-lg hover:bg-gray-50 transition-colors">
            Cancelar
        </a>
        <button type="submit"
                class="px-6 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
            <?= e($submitLabel) ?>
        </button>
    </div>
</form>
