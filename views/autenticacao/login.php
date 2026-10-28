<?php
/**
 * @var string      $email
 * @var string|null $erroLogin
 */
?>
<section class="cartao cartao-estreito">
    <h1>Entrar no CadGestor</h1>

    <?php if (isset($erroLogin)) : ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erroLogin) ?></div>
    <?php endif; ?>

    <form method="post" action="/login" class="formulario" autocomplete="on">
        <?= csrf_campo() ?>
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus
               autocomplete="username" maxlength="254">

        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required autocomplete="current-password" maxlength="128">

        <button type="submit" class="botao botao-primario">Entrar</button>
    </form>
    <p class="dica">Ainda não é cliente? <a href="/cadastro">Contrate o CadGestor</a> com teste grátis.</p>
</section>
