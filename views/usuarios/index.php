<?php
/** @var list<array<string, mixed>> $usuarios */

use CadGestor\Seguranca\Papel;

?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <h1>Usuários do sistema</h1>
        <a class="botao botao-primario" href="/usuarios/novo">Novo usuário</a>
    </div>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr><th>Nome</th><th>E-mail</th><th>Papéis</th><th>Situação</th><th>Último login</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u) : ?>
                <?php
                $papeis = array_filter(array_map(
                    static fn (string $p): ?string => Papel::tryFrom($p)?->rotulo(),
                    $u['papeis'] === '' ? [] : explode(',', (string) $u['papeis']),
                ));
                ?>
                <tr>
                    <td><?= e($u['nome']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e(implode(', ', $papeis)) ?></td>
                    <td>
                        <span class="selo <?= $u['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                            <?= $u['ativo'] ? 'Ativo' : 'Inativo' ?>
                        </span>
                        <?php if ($u['deve_trocar_senha']) : ?>
                            <span class="selo selo-aviso">Senha provisória</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(formatar_data_hora($u['ultimo_login_em']) ?: '—') ?></td>
                    <td><a href="/usuarios/<?= e($u['id']) ?>/editar">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
