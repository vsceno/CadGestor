<?php
/** @var bool $confirmado */
?>
<section class="cartao cartao-estreito">
    <?php if ($confirmado) : ?>
        <h1>E-mail confirmado</h1>
        <p>Pronto! Entre com o seu e-mail e a senha escolhida no cadastro para configurar o seu ambiente:
            empresas, catracas, pessoas e usuários.</p>
        <a class="botao botao-primario" href="/login">Entrar</a>
    <?php else : ?>
        <h1>Link inválido ou expirado</h1>
        <p>O link de confirmação não é válido, já foi usado ou expirou. Se você já confirmou, basta entrar.
            Se o prazo expirou, fale com o comercial do CadGestor.</p>
        <a class="botao" href="/login">Ir para o login</a>
    <?php endif; ?>
</section>
