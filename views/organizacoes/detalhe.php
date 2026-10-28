<?php
/**
 * @var array<string, mixed> $organizacao
 * @var list<array<string, mixed>> $usuarios
 * @var array<string, string> $novoAdmin
 * @var array<string, string> $erros
 */

use CadGestor\Seguranca\Papel;

$id = (string) $organizacao['id'];
?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <div>
            <h1><?= e($organizacao['nome']) ?></h1>
            <p class="subtitulo">
                Cliente desde <?= e(formatar_data(substr((string) $organizacao['created_at'], 0, 10))) ?> ·
                <span class="selo <?= $organizacao['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                    <?= $organizacao['ativo'] ? 'Ativa' : 'Inativa' ?>
                </span>
            </p>
        </div>
        <div class="acoes">
            <form method="post" action="/organizacoes/<?= e($id) ?>/entrar" class="form-inline">
                <?= csrf_campo() ?>
                <button type="submit" class="botao botao-primario">Entrar (suporte)</button>
            </form>
            <a class="botao" href="/organizacoes/<?= e($id) ?>/editar">Renomear</a>
            <form method="post" action="/organizacoes/<?= e($id) ?>/situacao" class="form-inline"
                  data-confirmar="<?= $organizacao['ativo']
                      ? 'Desativar a organização? Os usuários dela não conseguirão entrar e as catracas serão recusadas.'
                      : 'Reativar a organização?' ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="ativo" value="<?= $organizacao['ativo'] ? '0' : '1' ?>">
                <button type="submit" class="botao <?= $organizacao['ativo'] ? 'botao-perigo' : '' ?>">
                    <?= $organizacao['ativo'] ? 'Desativar' : 'Reativar' ?>
                </button>
            </form>
        </div>
    </div>
</section>

<section class="cartao">
    <h2>Usuários da organização</h2>
    <div class="tabela-rolagem">
        <table class="tabela">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Papéis</th><th>Situação</th><th>Último login</th></tr></thead>
            <tbody>
            <?php if ($usuarios === []) : ?>
                <tr><td colspan="5">Nenhum usuário. Cadastre o administrador abaixo.</td></tr>
            <?php endif; ?>
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
                    </td>
                    <td><?= e(formatar_data_hora($u['ultimo_login_em']) ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="dica">Os demais usuários são gerenciados pelo administrador da própria organização.</p>
</section>

<section class="cartao cartao-medio">
    <h2>Novo administrador da organização</h2>
    <form method="post" action="/organizacoes/<?= e($id) ?>/administradores" class="formulario">
        <?= csrf_campo() ?>

        <label for="nome">Nome completo</label>
        <input type="text" id="nome" name="nome" value="<?= e($novoAdmin['nome'] ?? '') ?>" required maxlength="150"
            <?= invalido($erros, 'nome') ?>>
        <?= erro_campo($erros, 'nome') ?>

        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="<?= e($novoAdmin['email'] ?? '') ?>" required
               maxlength="254"<?= invalido($erros, 'email') ?>>
        <?= erro_campo($erros, 'email') ?>
        <?= erro_campo($erros, 'papeis') ?>

        <p class="dica">Uma senha provisória será gerada e exibida uma única vez.</p>
        <div class="acoes">
            <button type="submit" class="botao botao-primario">Criar administrador</button>
        </div>
    </form>
</section>
