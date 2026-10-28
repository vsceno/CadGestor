<?php
/** @var list<array<string, mixed>> $planos */
?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <h1>Planos</h1>
        <a class="botao botao-primario" href="/planos/novo">Novo plano</a>
    </div>
    <div class="alerta alerta-aviso">
        Valores e limites iniciais são PROVISÓRIOS. Mudanças de preço valem para novas assinaturas e para
        a próxima troca de plano; reajuste de quem já assina segue o contrato.
    </div>
    <div class="tabela-rolagem">
        <table class="tabela">
            <thead><tr><th>Plano</th><th class="numero">Preço/mês</th><th class="numero">Pessoas</th>
                <th class="numero">Catracas</th><th>Venda</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($planos as $p) : ?>
                <tr>
                    <td><strong><?= e($p['nome']) ?></strong> <small class="subtitulo">(<?= e($p['codigo']) ?>)</small>
                        <?php if ($p['descricao']) : ?><br><small class="subtitulo"><?= e($p['descricao']) ?></small><?php endif; ?></td>
                    <td class="numero"><?= e(formatar_moeda((int) $p['preco_centavos'])) ?></td>
                    <td class="numero"><?= e($p['limite_pessoas']) ?></td>
                    <td class="numero"><?= e($p['limite_catracas']) ?></td>
                    <td><span class="selo <?= $p['disponivel_venda'] ? 'selo-ok' : 'selo-neutro' ?>">
                        <?= $p['disponivel_venda'] ? 'À venda' : 'Fora de venda' ?></span></td>
                    <td><a href="/planos/<?= e($p['id']) ?>/editar">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
