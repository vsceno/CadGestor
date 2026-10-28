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

    <form method="post" action="<?= $id === null ? '/organizacoes' : '/organizacoes/' . e($id) ?>" class="formulario">
        <?= csrf_campo() ?>

        <label for="nome">Nome da organização (cliente)</label>
        <input type="text" id="nome" name="nome" value="<?= e($dados['nome'] ?? '') ?>" required maxlength="150"
            <?= invalido($erros, 'nome') ?>>
        <?= erro_campo($erros, 'nome') ?>

        <?php if ($id === null) : ?>
            <p class="dica">
                A organização nasce com os perfis padrão (Funcionário, Aluno, Faxineiro, Pedreiro) e o
                termo de consentimento MODELO 1.0, que deve ser revisado pelo jurídico do cliente.
            </p>
        <?php endif; ?>

        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar</button>
            <a href="<?= $id === null ? '/organizacoes' : '/organizacoes/' . e($id) ?>" class="botao">Cancelar</a>
        </div>
    </form>
</section>
