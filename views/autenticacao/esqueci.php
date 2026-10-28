<?php
/**
 * @var bool $enviado
 * @var string|null $erro
 */
?>
<section class="cartao cartao-estreito cartao-acesso">
    <h1>Esqueci minha senha</h1>

    <?php if ($enviado) : ?>
        <div class="alerta alerta-sucesso" role="status">
            Se este e-mail tiver uma conta ativa, enviamos um link para criar uma nova senha. Ele vale por 1 hora.
        </div>
        <p class="dica">Não chegou? Confira a caixa de spam. Por segurança, a mensagem é a mesma exista ou não a conta.</p>
        <p><a class="botao" href="/login">Voltar para o login</a></p>
    <?php else : ?>
        <p class="subtitulo">Informe o e-mail que você usa para entrar. Enviaremos um link para criar uma nova senha.</p>

        <?php if (isset($erro)) : ?>
            <div class="alerta alerta-erro" role="alert"><?= e($erro) ?></div>
        <?php endif; ?>

        <form method="post" action="/esqueci-senha" class="formulario">
            <?= csrf_campo() ?>
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="username" maxlength="254">
            <button type="submit" class="botao botao-primario botao-bloco">Enviar link</button>
        </form>
        <p class="dica centro"><a href="/login">Lembrei a senha</a></p>
    <?php endif; ?>
</section>
