<?php
/**
 * @var string|null $id
 * @var array<string, mixed> $dados
 * @var array<string, string> $erros
 * @var list<array<string, mixed>> $planos
 * @var list<string> $ufs
 * @var string $titulo
 */

use CadGestor\Nucleo\Visao;

$cnpj = (string) ($dados['cnpj'] ?? '');
$voltar = $id === null ? '/organizacoes' : '/organizacoes/' . $id;
?>
<nav class="trilha" aria-label="Você está em">
    <a href="/organizacoes">Clientes</a>
    <?php if ($id !== null) : ?><span>/</span><a href="<?= e($voltar) ?>"><?= e($dados['nome'] ?? 'Cliente') ?></a><?php endif; ?>
</nav>

<section class="cartao cartao-largo">
    <div class="cabecalho-pagina">
        <div>
            <h1><?= e($titulo) ?></h1>
            <p class="subtitulo">
                <?= $id === null
                    ? 'Para contratos fechados fora do site. A assinatura nasce ativa e sem cobrança automática.'
                    : 'Dados da empresa contratante, usados no contrato, na cobrança e na nota fiscal.' ?>
            </p>
        </div>
    </div>

    <?php if ($erros !== []) : ?>
        <div class="alerta alerta-erro" role="alert">Corrija os campos destacados.</div>
    <?php endif; ?>

    <form method="post" action="<?= $id === null ? '/organizacoes' : '/organizacoes/' . e($id) ?>" class="formulario">
        <?= csrf_campo() ?>

        <fieldset>
            <legend>Identificação</legend>
            <div class="grade">
                <div class="largo-2">
                    <label for="nome">Razão social</label>
                    <input type="text" id="nome" name="nome" value="<?= e($dados['nome'] ?? '') ?>" required maxlength="150"
                           autocomplete="organization"<?= invalido($erros, 'nome') ?>>
                    <?= erro_campo($erros, 'nome') ?>
                </div>
                <div>
                    <label for="cnpj">CNPJ</label>
                    <input type="text" id="cnpj" name="cnpj" data-mascara="cnpj" required maxlength="18"
                           value="<?= e(strlen($cnpj) === 14 ? formatar_cnpj($cnpj) : $cnpj) ?>"<?= invalido($erros, 'cnpj') ?>>
                    <?= erro_campo($erros, 'cnpj') ?>
                </div>
            </div>
        </fieldset>

        <?= Visao::parcial('dados-empresa', ['dados' => $dados, 'erros' => $erros, 'ufs' => $ufs]) ?>

        <?php if ($id === null) : ?>
            <fieldset>
                <legend>Contrato</legend>
                <label for="plano_id">Plano contratado</label>
                <select id="plano_id" name="plano_id" required<?= invalido($erros, 'plano') ?>>
                    <option value="">Selecione…</option>
                    <?php foreach ($planos as $plano) : ?>
                        <option value="<?= e($plano['id']) ?>"<?= selecionado(($dados['plano_id'] ?? '') === $plano['id']) ?>>
                            <?= e($plano['nome']) ?> · <?= e(formatar_moeda((int) $plano['preco_centavos'])) ?>/mês
                            <?= $plano['disponivel_venda'] ? '' : '(fora de venda)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?= erro_campo($erros, 'plano') ?>
                <p class="dica">O cliente que se cadastra pelo site entra sozinho em teste grátis.</p>
            </fieldset>
        <?php endif; ?>

        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar</button>
            <a href="<?= e($voltar) ?>" class="botao">Cancelar</a>
        </div>
    </form>
</section>
