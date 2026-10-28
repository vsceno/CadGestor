<?php
/**
 * @var string|null $id
 * @var array<string, mixed> $dados
 * @var array<string, string> $erros
 * @var string $titulo
 */

use CadGestor\Seguranca\Papel;

$papeisMarcados = is_array($dados['papeis'] ?? null) ? $dados['papeis'] : [];
?>
<section class="cartao cartao-medio">
    <h1><?= e($titulo) ?></h1>

    <form method="post" action="<?= $id === null ? '/usuarios' : '/usuarios/' . e($id) ?>" class="formulario">
        <?= csrf_campo() ?>

        <label for="nome">Nome completo</label>
        <input type="text" id="nome" name="nome" value="<?= e($dados['nome'] ?? '') ?>" required maxlength="150"
               <?= invalido($erros, 'nome') ?>>
        <?= erro_campo($erros, 'nome') ?>

        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="<?= e($dados['email'] ?? '') ?>" required maxlength="254"
               <?= invalido($erros, 'email') ?>>
        <?= erro_campo($erros, 'email') ?>

        <fieldset>
            <legend>Papéis</legend>
            <?php foreach (Papel::daOrganizacao() as $papel) : ?>
                <label class="opcao">
                    <input type="checkbox" name="papeis[]" value="<?= e($papel->value) ?>"
                        <?= marcado(in_array($papel->value, $papeisMarcados, true)) ?>>
                    <?= e($papel->rotulo()) ?>
                </label>
            <?php endforeach; ?>
            <p class="dica">O administrador tem acesso a todos os módulos desta organização.</p>
            <?= erro_campo($erros, 'papeis') ?>
        </fieldset>

        <?php if ($id !== null) : ?>
            <label class="opcao">
                <input type="checkbox" name="ativo" value="1"<?= marcado((bool) ($dados['ativo'] ?? false)) ?>>
                Usuário ativo
            </label>
        <?php else : ?>
            <p class="dica">Uma senha provisória será gerada e exibida uma única vez após salvar.</p>
        <?php endif; ?>

        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar</button>
            <a href="/usuarios" class="botao">Cancelar</a>
        </div>
    </form>

    <?php if ($id !== null) : ?>
        <hr>
        <form method="post" action="/usuarios/<?= e($id) ?>/senha"
              data-confirmar="Gerar uma nova senha provisória para este usuário? A senha atual deixará de funcionar.">
            <?= csrf_campo() ?>
            <button type="submit" class="botao botao-perigo">Redefinir senha</button>
        </form>
    <?php endif; ?>
</section>
