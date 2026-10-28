<?php
/**
 * @var string      $referencia código para localizar o erro no log
 * @var string|null $detalhe    stack trace (somente com APP_DEBUG fora de produção)
 */
?>
<section class="cartao">
    <h1>Erro interno</h1>
    <p>Não foi possível concluir a operação. Informe ao suporte o código
        <code><?= e($referencia) ?></code>.</p>
    <?php if ($detalhe !== null) : ?>
        <details open>
            <summary>Detalhes técnicos (modo debug)</summary>
            <pre class="trace"><?= e($detalhe) ?></pre>
        </details>
    <?php endif; ?>
</section>
