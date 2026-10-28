<?php
/**
 * @var string|null $id
 * @var array<string, mixed> $dados
 * @var array<string, string> $erros
 * @var list<string> $ufs
 * @var string $titulo
 */

$v = static fn (string $campo): string => e($dados[$campo] ?? '');
$cnpj = (string) ($dados['cnpj'] ?? '');
$cep = (string) ($dados['cep'] ?? '');
$telefone = (string) ($dados['responsavel_telefone'] ?? '');
?>
<section class="cartao cartao-medio">
    <h1><?= e($titulo) ?></h1>

    <?php if ($erros !== []) : ?>
        <div class="alerta alerta-erro" role="alert">Corrija os campos destacados.</div>
    <?php endif; ?>

    <form method="post" action="<?= $id === null ? '/empresas' : '/empresas/' . e($id) ?>" class="formulario">
        <?= csrf_campo() ?>

        <fieldset>
            <legend>Identificação</legend>
            <div class="grade">
                <div class="largo">
                    <label for="razao_social">Razão social</label>
                    <input type="text" id="razao_social" name="razao_social" value="<?= $v('razao_social') ?>"
                           required maxlength="200"<?= invalido($erros, 'razao_social') ?>>
                    <?= erro_campo($erros, 'razao_social') ?>
                </div>
                <div>
                    <label for="nome_fantasia">Nome fantasia</label>
                    <input type="text" id="nome_fantasia" name="nome_fantasia" value="<?= $v('nome_fantasia') ?>"
                           maxlength="200"<?= invalido($erros, 'nome_fantasia') ?>>
                    <?= erro_campo($erros, 'nome_fantasia') ?>
                </div>
                <div>
                    <label for="cnpj">CNPJ</label>
                    <input type="text" id="cnpj" name="cnpj" data-mascara="cnpj" required maxlength="18"
                           value="<?= e(strlen($cnpj) === 14 ? formatar_cnpj($cnpj) : $cnpj) ?>"
                           aria-describedby="dica-cnpj"<?= invalido($erros, 'cnpj') ?>>
                    <p class="dica" id="dica-cnpj">Aceita o formato alfanumérico (ex.: 12.ABC.345/01DE-35).</p>
                    <?= erro_campo($erros, 'cnpj') ?>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Endereço</legend>
            <div class="grade">
                <div>
                    <label for="cep">CEP</label>
                    <input type="text" id="cep" name="cep" data-mascara="cep" required maxlength="9"
                           value="<?= e(strlen($cep) === 8 ? formatar_cep($cep) : $cep) ?>"<?= invalido($erros, 'cep') ?>>
                    <?= erro_campo($erros, 'cep') ?>
                </div>
                <div class="largo">
                    <label for="logradouro">Logradouro</label>
                    <input type="text" id="logradouro" name="logradouro" value="<?= $v('logradouro') ?>" required
                           maxlength="200"<?= invalido($erros, 'logradouro') ?>>
                    <?= erro_campo($erros, 'logradouro') ?>
                </div>
                <div>
                    <label for="numero">Número</label>
                    <input type="text" id="numero" name="numero" value="<?= $v('numero') ?>" required maxlength="20"
                        <?= invalido($erros, 'numero') ?>>
                    <?= erro_campo($erros, 'numero') ?>
                </div>
                <div>
                    <label for="complemento">Complemento</label>
                    <input type="text" id="complemento" name="complemento" value="<?= $v('complemento') ?>"
                           maxlength="100">
                    <?= erro_campo($erros, 'complemento') ?>
                </div>
                <div>
                    <label for="bairro">Bairro</label>
                    <input type="text" id="bairro" name="bairro" value="<?= $v('bairro') ?>" required maxlength="100"
                        <?= invalido($erros, 'bairro') ?>>
                    <?= erro_campo($erros, 'bairro') ?>
                </div>
                <div>
                    <label for="municipio">Município</label>
                    <input type="text" id="municipio" name="municipio" value="<?= $v('municipio') ?>" required
                           maxlength="100"<?= invalido($erros, 'municipio') ?>>
                    <?= erro_campo($erros, 'municipio') ?>
                </div>
                <div>
                    <label for="uf">UF</label>
                    <select id="uf" name="uf" required<?= invalido($erros, 'uf') ?>>
                        <option value="">Selecione</option>
                        <?php foreach ($ufs as $uf) : ?>
                            <option value="<?= e($uf) ?>"<?= selecionado(($dados['uf'] ?? '') === $uf) ?>><?= e($uf) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= erro_campo($erros, 'uf') ?>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Responsável legal</legend>
            <div class="grade">
                <div>
                    <label for="responsavel_nome">Nome</label>
                    <input type="text" id="responsavel_nome" name="responsavel_nome" required maxlength="150"
                           value="<?= $v('responsavel_nome') ?>"<?= invalido($erros, 'responsavel_nome') ?>>
                    <?= erro_campo($erros, 'responsavel_nome') ?>
                </div>
                <div>
                    <label for="responsavel_cargo">Cargo</label>
                    <input type="text" id="responsavel_cargo" name="responsavel_cargo" maxlength="100"
                           value="<?= $v('responsavel_cargo') ?>">
                </div>
                <div>
                    <label for="responsavel_email">E-mail</label>
                    <input type="email" id="responsavel_email" name="responsavel_email" required maxlength="254"
                           value="<?= $v('responsavel_email') ?>"<?= invalido($erros, 'responsavel_email') ?>>
                    <?= erro_campo($erros, 'responsavel_email') ?>
                </div>
                <div>
                    <label for="responsavel_telefone">Telefone</label>
                    <input type="text" id="responsavel_telefone" name="responsavel_telefone" data-mascara="telefone"
                           required maxlength="15"
                           value="<?= e(preg_match('/^\d{10,11}$/', $telefone) ? formatar_telefone($telefone) : $telefone) ?>"
                        <?= invalido($erros, 'responsavel_telefone') ?>>
                    <?= erro_campo($erros, 'responsavel_telefone') ?>
                </div>
            </div>
        </fieldset>

        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar</button>
            <a href="<?= $id === null ? '/empresas' : '/empresas/' . e($id) ?>" class="botao">Cancelar</a>
        </div>
    </form>
</section>
