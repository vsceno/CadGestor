<?php
/**
 * @var string      $email
 * @var string|null $erroLogin
 * @var bool|null   $podeReenviar
 */
?>
<section class="cartao cartao-estreito cartao-acesso">
    <div class="acesso-marca" aria-hidden="true">CG</div>
    <h1>Entrar no CadGestor</h1>
    <p class="subtitulo">Controle de acesso com LGPD.</p>

    <?php if (isset($erroLogin)) : ?>
        <div class="alerta alerta-erro" role="alert">
            <?= e($erroLogin) ?>
            <?php if ($podeReenviar ?? false) : ?>
                <form method="post" action="/confirmar-email/reenviar" class="form-inline">
                    <?= csrf_campo() ?>
                    <button type="submit" class="botao-link">Reenviar o link de confirmação</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/login" class="formulario" autocomplete="on">
        <?= csrf_campo() ?>
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus
               autocomplete="username" maxlength="254">

        <div class="rotulo-com-link">
            <label for="senha">Senha</label>
            <a href="/esqueci-senha">Esqueci minha senha</a>
        </div>
        <input type="password" id="senha" name="senha" required autocomplete="current-password" maxlength="128">

        <button type="submit" class="botao botao-primario botao-bloco">Entrar</button>
    </form>
    <p class="dica centro">Ainda não é cliente? <a href="/cadastro">Contrate o CadGestor</a> com teste grátis.</p>
</section>
