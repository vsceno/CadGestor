<?php
/**
 * @var string $titulo
 * @var string $senha
 * @var string $emailUsuario
 * @var string|null $voltarPara
 */
?>
<section class="cartao cartao-estreito">
    <h1><?= e($titulo) ?></h1>
    <p>Repasse a senha provisória ao usuário por um canal seguro. Ela <strong>não será exibida novamente</strong>
        e precisará ser trocada no primeiro acesso.</p>

    <dl class="dados">
        <dt>E-mail</dt>
        <dd><?= e($emailUsuario) ?></dd>
        <dt>Senha provisória</dt>
        <dd><code class="segredo" id="senha-provisoria"><?= e($senha) ?></code></dd>
    </dl>

    <div class="acoes">
        <button type="button" class="botao" data-copiar="senha-provisoria">Copiar senha</button>
        <a href="<?= e($voltarPara ?? '/usuarios') ?>" class="botao botao-primario">Concluir</a>
    </div>
</section>
