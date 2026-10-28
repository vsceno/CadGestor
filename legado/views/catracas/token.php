<?php
/**
 * @var string $titulo
 * @var string $catracaId
 * @var string $token
 */
?>
<section class="cartao cartao-medio">
    <h1><?= e($titulo) ?></h1>
    <div class="alerta alerta-aviso">
        Copie o token agora e configure-o na catraca. Ele <strong>não será exibido novamente</strong>:
        o sistema guarda apenas o hash. Se perder, gere um novo.
    </div>

    <dl class="dados">
        <dt>Token do dispositivo</dt>
        <dd><code class="segredo" id="token-catraca"><?= e($token) ?></code></dd>
        <dt>Uso na API</dt>
        <dd><code>Authorization: Bearer &lt;token&gt;</code></dd>
    </dl>

    <div class="acoes">
        <button type="button" class="botao" data-copiar="token-catraca">Copiar token</button>
        <a href="/catracas/<?= e($catracaId) ?>/editar" class="botao botao-primario">Concluir</a>
    </div>
</section>
