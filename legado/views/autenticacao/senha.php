<?php
/**
 * @var array<string, string> $erros
 * @var \CadGestor\Seguranca\UsuarioAutenticado|null $usuario
 */

use CadGestor\Seguranca\PoliticaSenha;

?>
<section class="cartao cartao-estreito">
    <h1>Trocar senha</h1>

    <?php if ($usuario?->deveTrocarSenha) : ?>
        <div class="alerta alerta-aviso" role="status">
            Você está usando uma senha provisória. Defina uma senha pessoal para continuar.
        </div>
    <?php endif; ?>

    <form method="post" action="/senha" class="formulario">
        <?= csrf_campo() ?>
        <label for="senha_atual">Senha atual</label>
        <input type="password" id="senha_atual" name="senha_atual" required autocomplete="current-password"
               maxlength="128"<?= invalido($erros, 'senha_atual') ?>>
        <?= erro_campo($erros, 'senha_atual') ?>

        <label for="nova_senha">Nova senha</label>
        <input type="password" id="nova_senha" name="nova_senha" required autocomplete="new-password"
               minlength="<?= PoliticaSenha::TAMANHO_MINIMO ?>" maxlength="<?= PoliticaSenha::TAMANHO_MAXIMO ?>"
               aria-describedby="dica-senha"<?= invalido($erros, 'nova_senha') ?>>
        <p class="dica" id="dica-senha">
            Mínimo de <?= PoliticaSenha::TAMANHO_MINIMO ?> caracteres. Uma frase longa é mais segura e fácil de lembrar.
        </p>
        <?= erro_campo($erros, 'nova_senha') ?>

        <label for="confirmacao">Confirme a nova senha</label>
        <input type="password" id="confirmacao" name="confirmacao" required autocomplete="new-password"
               maxlength="128"<?= invalido($erros, 'confirmacao') ?>>
        <?= erro_campo($erros, 'confirmacao') ?>

        <button type="submit" class="botao botao-primario">Salvar nova senha</button>
    </form>
</section>
