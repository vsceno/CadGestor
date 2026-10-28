<?php
/** @var array<string, mixed> $fatura */

$id = (string) $fatura['gateway_cobranca_id'];
?>
<section class="cartao cartao-estreito">
    <div class="alerta alerta-aviso">
        <strong>Ambiente de testes.</strong> Esta página imita o meio de pagamento; nenhum valor é cobrado.
    </div>
    <h1>Pagamento simulado</h1>
    <dl class="dados">
        <dt>Valor</dt><dd><?= e(formatar_moeda((int) $fatura['valor_centavos'])) ?></dd>
        <dt>Vencimento</dt><dd><?= e(formatar_data((string) $fatura['vencimento'])) ?></dd>
        <dt>Situação</dt><dd><?= e($fatura['status']) ?></dd>
    </dl>
    <div class="acoes">
        <?php foreach (['pagar' => 'Pagar agora', 'vencer' => 'Deixar vencer', 'proxima' => 'Gerar fatura do mês seguinte'] as $acao => $rotulo) : ?>
            <form method="post" action="/pagamento-simulado/<?= e($id) ?>" class="form-inline">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="<?= e($acao) ?>">
                <button type="submit" class="botao<?= $acao === 'pagar' ? ' botao-primario' : '' ?>"><?= e($rotulo) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</section>
