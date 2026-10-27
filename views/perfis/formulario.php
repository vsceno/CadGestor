<?php
/**
 * @var string|null $id
 * @var array<string, mixed> $dados
 * @var array<string, string> $erros
 * @var array<string, string> $basesLegais
 * @var string $titulo
 */
?>
<section class="cartao cartao-medio">
    <h1><?= e($titulo) ?></h1>

    <form method="post" action="<?= $id === null ? '/perfis' : '/perfis/' . e($id) ?>" class="formulario">
        <?= csrf_campo() ?>

        <label for="nome">Nome do perfil</label>
        <input type="text" id="nome" name="nome" value="<?= e($dados['nome'] ?? '') ?>" required maxlength="60"
            <?= invalido($erros, 'nome') ?>>
        <?= erro_campo($erros, 'nome') ?>

        <label for="descricao">Descrição</label>
        <input type="text" id="descricao" name="descricao" value="<?= e($dados['descricao'] ?? '') ?>" maxlength="255">
        <?= erro_campo($erros, 'descricao') ?>

        <label for="base_legal">Base legal para biometria (LGPD, art. 11)</label>
        <select id="base_legal" name="base_legal" required<?= invalido($erros, 'base_legal') ?>>
            <?php foreach ($basesLegais as $valor => $rotulo) : ?>
                <option value="<?= e($valor) ?>"<?= selecionado(($dados['base_legal'] ?? '') === $valor) ?>>
                    <?= e($rotulo) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="dica">
            Atenção: o sistema exige consentimento registrado para QUALQUER cadastro biométrico.
            Outras bases legais ficam registradas para documentação, mas não dispensam o termo
            até que o jurídico valide e a regra seja ajustada.
        </p>
        <?= erro_campo($erros, 'base_legal') ?>

        <label class="opcao">
            <input type="checkbox" name="base_legal_validada" value="1"
                <?= marcado((bool) ($dados['base_legal_validada'] ?? false)) ?>>
            Base legal validada pelo jurídico do cliente
        </label>

        <label class="opcao">
            <input type="checkbox" name="ativo" value="1"<?= marcado((bool) ($dados['ativo'] ?? false)) ?>>
            Perfil ativo (perfis inativos negam acesso nas catracas)
        </label>

        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar</button>
            <a href="/perfis" class="botao">Cancelar</a>
        </div>
    </form>
</section>
