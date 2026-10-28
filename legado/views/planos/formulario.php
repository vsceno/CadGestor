<?php
/**
 * @var string|null $id
 * @var array<string, mixed> $dados
 * @var array<string, string> $erros
 * @var string $titulo
 */
?>
<section class="cartao cartao-medio">
    <h1><?= e($titulo) ?></h1>

    <form method="post" action="<?= $id === null ? '/planos' : '/planos/' . e($id) ?>" class="formulario">
        <?= csrf_campo() ?>

        <label for="codigo">Código (não muda depois)</label>
        <input type="text" id="codigo" name="codigo" value="<?= e($dados['codigo'] ?? '') ?>" maxlength="30"
               <?= $id === null ? 'required' : 'readonly' ?><?= invalido($erros, 'codigo') ?>>
        <?= erro_campo($erros, 'codigo') ?>

        <label for="nome">Nome</label>
        <input type="text" id="nome" name="nome" value="<?= e($dados['nome'] ?? '') ?>" required maxlength="60"
            <?= invalido($erros, 'nome') ?>>
        <?= erro_campo($erros, 'nome') ?>

        <label for="descricao">Descrição</label>
        <input type="text" id="descricao" name="descricao" value="<?= e($dados['descricao'] ?? '') ?>" maxlength="255">
        <?= erro_campo($erros, 'descricao') ?>

        <label for="preco">Preço mensal (R$)</label>
        <input type="text" id="preco" name="preco" value="<?= e($dados['preco'] ?? '') ?>" required inputmode="decimal"
               placeholder="199,90"<?= invalido($erros, 'preco') ?>>
        <?= erro_campo($erros, 'preco') ?>

        <label for="limite_pessoas">Limite de pessoas ativas</label>
        <input type="number" id="limite_pessoas" name="limite_pessoas" min="1" required
               value="<?= e($dados['limite_pessoas'] ?? '') ?>"<?= invalido($erros, 'limite_pessoas') ?>>
        <?= erro_campo($erros, 'limite_pessoas') ?>

        <label for="limite_catracas">Limite de catracas ativas</label>
        <input type="number" id="limite_catracas" name="limite_catracas" min="1" required
               value="<?= e($dados['limite_catracas'] ?? '') ?>"<?= invalido($erros, 'limite_catracas') ?>>
        <?= erro_campo($erros, 'limite_catracas') ?>

        <label for="ordem">Ordem de exibição</label>
        <input type="number" id="ordem" name="ordem" min="0" max="999" value="<?= e($dados['ordem'] ?? '0') ?>">

        <label class="opcao">
            <input type="checkbox" name="disponivel_venda" value="1"<?= marcado((bool) ($dados['disponivel_venda'] ?? false)) ?>>
            Disponível para venda no site
        </label>

        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar</button>
            <a href="/planos" class="botao">Cancelar</a>
        </div>
    </form>
</section>
