<?php
/**
 * @var array<string, mixed>|null $assinatura
 * @var \CadGestor\Dominio\SituacaoAssinatura $situacao
 * @var array{pessoas: int, catracas: int} $uso
 * @var list<array<string, mixed>> $faturas
 * @var list<array<string, mixed>> $planos
 * @var \CadGestor\Seguranca\UsuarioAutenticado|null $usuario
 */

use CadGestor\Seguranca\Papel;

$admin = $usuario?->temPapel(Papel::Administrador) ?? false;
$cancelada = ($assinatura['status'] ?? '') === 'cancelada';
$rotuloStatus = ['teste' => 'Teste grátis', 'ativa' => 'Ativa', 'inadimplente' => 'Pagamento em atraso', 'cancelada' => 'Cancelada'];
$rotuloFatura = ['pendente' => 'A pagar', 'paga' => 'Paga', 'vencida' => 'Vencida', 'cancelada' => 'Cancelada'];
$classeFatura = ['pendente' => 'selo-aviso', 'paga' => 'selo-ok', 'vencida' => 'selo-falha', 'cancelada' => 'selo-neutro'];
?>
<section class="cartao">
    <h1>Minha assinatura</h1>

    <?php if ($assinatura === null) : ?>
        <div class="alerta alerta-erro">Organização sem assinatura. Fale com o comercial do CadGestor.</div>
    <?php else : ?>
        <?php if ($situacao->aviso !== null) : ?>
            <div class="alerta <?= $situacao->permiteAlterar() ? 'alerta-aviso' : 'alerta-erro' ?>" role="status">
                <?= e($situacao->aviso) ?>
            </div>
        <?php endif; ?>

        <dl class="dados">
            <dt>Plano</dt>
            <dd><strong><?= e($assinatura['plano_nome']) ?></strong> · <?= e(formatar_moeda((int) $assinatura['preco_centavos'])) ?>/mês</dd>
            <dt>Situação</dt>
            <dd>
                <?= e($rotuloStatus[$assinatura['status']] ?? $assinatura['status']) ?>
                <?php if ($assinatura['teste_ate']) : ?>· até <?= e(formatar_data((string) $assinatura['teste_ate'])) ?><?php endif; ?>
            </dd>
            <dt>Pessoas ativas</dt>
            <dd><?= e($uso['pessoas']) ?> de <?= e($assinatura['limite_pessoas']) ?></dd>
            <dt>Catracas ativas</dt>
            <dd><?= e($uso['catracas']) ?> de <?= e($assinatura['limite_catracas']) ?></dd>
        </dl>
    <?php endif; ?>
</section>

<section class="cartao">
    <h2>Faturas</h2>
    <p class="dica">O pagamento (Pix, boleto ou cartão) é feito na página segura do meio de pagamento.
        Nenhum dado de cartão passa pelo CadGestor.</p>
    <div class="tabela-rolagem">
        <table class="tabela">
            <thead><tr><th>Vencimento</th><th class="numero">Valor</th><th>Situação</th><th></th></tr></thead>
            <tbody>
            <?php if ($faturas === []) : ?>
                <tr><td colspan="4">Nenhuma fatura.</td></tr>
            <?php endif; ?>
            <?php foreach ($faturas as $f) : ?>
                <tr>
                    <td><?= e(formatar_data($f['vencimento'])) ?></td>
                    <td class="numero"><?= e(formatar_moeda((int) $f['valor_centavos'])) ?></td>
                    <td><span class="selo <?= e($classeFatura[$f['status']] ?? 'selo-neutro') ?>"><?= e($rotuloFatura[$f['status']] ?? $f['status']) ?></span></td>
                    <td>
                        <?php if (in_array($f['status'], ['pendente', 'vencida'], true) && $f['link_pagamento']) : ?>
                            <a class="botao botao-primario" href="<?= e($f['link_pagamento']) ?>" rel="noopener noreferrer">Pagar</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($admin && $assinatura !== null && !$cancelada) : ?>
    <section class="cartao cartao-medio">
        <h2>Trocar de plano</h2>
        <form method="post" action="/assinatura/plano" class="formulario">
            <?= csrf_campo() ?>
            <?php foreach ($planos as $plano) : ?>
                <label class="opcao">
                    <input type="radio" name="plano_id" value="<?= e($plano['id']) ?>"
                        <?= marcado($plano['id'] === $assinatura['plano_id']) ?> required>
                    <strong><?= e($plano['nome']) ?></strong> · <?= e(formatar_moeda((int) $plano['preco_centavos'])) ?>/mês
                    · até <?= e($plano['limite_pessoas']) ?> pessoas e <?= e($plano['limite_catracas']) ?> catracas
                </label>
            <?php endforeach; ?>
            <p class="dica">Para um plano menor, o uso atual precisa caber nos limites dele.</p>
            <div class="acoes"><button type="submit" class="botao botao-primario">Trocar plano</button></div>
        </form>
    </section>
<?php endif; ?>

<?php if ($admin) : ?>
    <section class="cartao cartao-medio">
        <h2>Seus dados</h2>
        <p>Baixe os cadastros de pessoas e vínculos (CSV). Os dados são da sua organização: você pode levá-los
            a qualquer momento, inclusive depois de cancelar.</p>
        <a class="botao" href="/assinatura/exportar">Exportar cadastros (CSV)</a>

        <?php if ($assinatura !== null && !$cancelada) : ?>
            <hr>
            <h2>Cancelar assinatura</h2>
            <p>Ao cancelar, <strong>as catracas param de liberar acessos imediatamente</strong> e o sistema fica
                disponível só para exportar os dados.</p>
            <form method="post" action="/assinatura/cancelar" class="formulario"
                  data-confirmar="Cancelar a assinatura? As catracas param imediatamente.">
                <?= csrf_campo() ?>
                <label for="confirmacao">Digite CANCELAR para confirmar</label>
                <input type="text" id="confirmacao" name="confirmacao" autocomplete="off" required pattern="CANCELAR">
                <div class="acoes"><button type="submit" class="botao botao-perigo">Cancelar assinatura</button></div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
