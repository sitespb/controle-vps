<?php
/**
 * Administracao de usuarios - secao 23 do PLAN.
 *
 * @var array<int,array<string,mixed>> $users
 * @var array<string,string>           $roles
 * @var array<string,mixed>|null       $currentUser
 */

$currentId = (int) ($currentUser['id'] ?? 0);
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-900">Usuários</h2>
        <p class="text-sm text-gray-600 mt-1">
            <strong>Administrador</strong> tem acesso completo.
            <strong>Operador</strong> visualiza servidores, sites e alertas, sem alterar cadastros.
        </p>
    </div>

    <a href="<?= e(url('/usuarios/novo')) ?>"
       class="inline-flex items-center px-4 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:bg-red-800 transition-colors">
        <svg class="h-4 w-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 5v14M5 12h14" />
        </svg>
        Novo usuário
    </a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto scrollbar-thin">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">E-mail</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Perfil</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Último acesso</th>
                    <th class="px-4 py-3 whitespace-nowrap text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($users as $user) : ?>
                    <?php $isSelf = (int) $user['id'] === $currentId; ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="h-9 w-9 rounded-full bg-primary flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                    <?= e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate"><?= e($user['name']) ?></p>
                                    <?php if ($isSelf) : ?>
                                        <p class="text-xs text-gray-500">você</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500"><?= e($user['email']) ?></td>

                        <td class="px-4 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $user['role'] === 'admin'
                                ? 'bg-purple-100 text-purple-800'
                                : 'bg-blue-100 text-blue-800' ?>">
                                <?= e($roles[$user['role']] ?? $user['role']) ?>
                            </span>
                        </td>

                        <td class="px-4 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $user['status'] === 'active'
                                ? 'bg-green-100 text-green-800'
                                : 'bg-red-100 text-red-800' ?>">
                                <?= $user['status'] === 'active' ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>

                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?= $user['last_login_at'] === null ? 'nunca' : e(time_ago($user['last_login_at'])) ?>
                        </td>

                        <td class="px-4 py-4 whitespace-nowrap text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="<?= e(url('/usuarios/' . $user['id'] . '/editar')) ?>"
                                   class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors" title="Editar">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                        <path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                    </svg>
                                </a>

                                <?php if (!$isSelf) : ?>
                                    <form method="POST" action="<?= e(url('/usuarios/' . $user['id'] . '/excluir')) ?>"
                                          class="inline"
                                          data-confirm="Excluir o usuário <?= e($user['name']) ?>?">
                                        <?= csrf_field() ?>
                                        <button type="submit"
                                                class="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Excluir">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                            </svg>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6 bg-blue-50 border-l-4 border-blue-500 p-4 rounded-md">
    <p class="text-sm text-blue-700 leading-relaxed">
        As senhas são gravadas apenas como hash (<code class="px-1 py-0.5 bg-blue-100 rounded text-xs">password_hash()</code>)
        e nunca podem ser lidas de volta. Para redefinir sem acesso ao painel, use
        <code class="px-1 py-0.5 bg-blue-100 rounded text-xs">php bin/console.php user:password</code>.
    </p>
</div>
