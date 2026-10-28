<?php
/**
 * @var string|null $id
 * @var array<string, mixed> $dados
 * @var array<string, string> $erros
 * @var list<array<string, mixed>> $planos
 * @var string $titulo
 */
?>
<section class="cartao cartao-medio">
    <h1><?= e($titulo) ?></h1>

    <form method="post" action="<?= $id === null ? '/organizacoes' : '/organizacoes/' . e($id) ?>" class="formulario">
        <?= csrf_campo() ?>

        <label for="nome">Nome do cliente</label>
        <input type="text" id="nome" name="nome" value="<?= e($dados['nome'] ?? '') ?>" required maxlength="150"
            <?= invalido($erros, 'nome') ?>>
        <?= erro_campo($erros, 'nome') ?>

        <?php if ($id === null) : ?>
            <label for="plano_id">Plano contratado</label>
            <select id="plano_id" name="plano_id" required<?= invalido($erros, 'plano') ?>>
                <option value="">Selecione…</option>
                <?php foreach ($planos as $plano) : ?>
                    <option value="<?= e($plano['id']) ?>"<?= selecionado(($dados['plano_id'] ?? '') === $plano['id']) ?>>
                        <?= e($plano['nome']) ?> · <?= e(formatar_moeda((int) $plano['preco_centavos'])) ?>/mês
                    </option>
                <?php endforeach; ?>
            </select>
            <?= erro_campo($erros, 'plano') ?>
            <p class="dica">
                Use para vendas fechadas fora do site (contrato próprio): a assinatura nasce ativa e sem
                cobrança automática. O cliente que se cadastra pelo site entra sozinho em teste grátis.
            </p>
        <?php endif; ?>

        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar</button>
            <a href="<?= $id === null ? '/organizacoes' : '/organizacoes/' . e($id) ?>" class="botao">Cancelar</a>
        </div>
    </form>
</section>
