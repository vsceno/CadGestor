<?php
/**
 * Campos de cadastro de um cliente (pessoa jurídica): identificação
 * complementar, endereço e contatos. Usado pela plataforma e pelo próprio cliente.
 *
 * @var array<string, mixed> $dados
 * @var array<string, string> $erros
 * @var list<string> $ufs
 */

$v = static fn (string $campo): string => e($dados[$campo] ?? '');
$cep = (string) ($dados['cep'] ?? '');
$telefone = static function (string $campo) use ($dados): string {
    $valor = (string) ($dados[$campo] ?? '');

    return e(preg_match('/^\d{10,11}$/', $valor) === 1 ? formatar_telefone($valor) : $valor);
};
?>
<fieldset>
    <legend>Dados fiscais</legend>
    <div class="grade">
        <div class="largo">
            <label for="nome_fantasia">Nome fantasia <span class="opcional">(opcional)</span></label>
            <input type="text" id="nome_fantasia" name="nome_fantasia" value="<?= $v('nome_fantasia') ?>" maxlength="150"
                <?= invalido($erros, 'nome_fantasia') ?>>
            <?= erro_campo($erros, 'nome_fantasia') ?>
        </div>
        <div>
            <label for="inscricao_municipal">Inscrição municipal <span class="opcional">(opcional)</span></label>
            <input type="text" id="inscricao_municipal" name="inscricao_municipal" maxlength="20"
                   value="<?= $v('inscricao_municipal') ?>" aria-describedby="dica-im"<?= invalido($erros, 'inscricao_municipal') ?>>
            <p class="dica" id="dica-im">Necessária para emitir a nota fiscal de serviço.</p>
            <?= erro_campo($erros, 'inscricao_municipal') ?>
        </div>
        <div>
            <label for="inscricao_estadual">Inscrição estadual <span class="opcional">(opcional)</span></label>
            <input type="text" id="inscricao_estadual" name="inscricao_estadual" maxlength="20"
                   value="<?= $v('inscricao_estadual') ?>" placeholder="ou ISENTO"<?= invalido($erros, 'inscricao_estadual') ?>>
            <?= erro_campo($erros, 'inscricao_estadual') ?>
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Endereço</legend>
    <div class="grade">
        <div>
            <label for="cep">CEP</label>
            <input type="text" id="cep" name="cep" data-mascara="cep" required maxlength="9" inputmode="numeric"
                   value="<?= e(strlen($cep) === 8 ? formatar_cep($cep) : $cep) ?>"<?= invalido($erros, 'cep') ?>>
            <?= erro_campo($erros, 'cep') ?>
        </div>
        <div class="largo-2">
            <label for="logradouro">Logradouro</label>
            <input type="text" id="logradouro" name="logradouro" value="<?= $v('logradouro') ?>" required maxlength="200"
                <?= invalido($erros, 'logradouro') ?>>
            <?= erro_campo($erros, 'logradouro') ?>
        </div>
        <div>
            <label for="numero">Número</label>
            <input type="text" id="numero" name="numero" value="<?= $v('numero') ?>" required maxlength="20"
                <?= invalido($erros, 'numero') ?>>
            <?= erro_campo($erros, 'numero') ?>
        </div>
        <div>
            <label for="complemento">Complemento <span class="opcional">(opcional)</span></label>
            <input type="text" id="complemento" name="complemento" value="<?= $v('complemento') ?>" maxlength="100"
                <?= invalido($erros, 'complemento') ?>>
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
            <input type="text" id="municipio" name="municipio" value="<?= $v('municipio') ?>" required maxlength="100"
                <?= invalido($erros, 'municipio') ?>>
            <?= erro_campo($erros, 'municipio') ?>
        </div>
        <div>
            <label for="uf">UF</label>
            <select id="uf" name="uf" required<?= invalido($erros, 'uf') ?>>
                <option value="">Selecione</option>
                <?php foreach ($ufs as $uf) : ?>
                    <option value="<?= e($uf) ?>"<?= selecionado(mb_strtoupper((string) ($dados['uf'] ?? '')) === $uf) ?>><?= e($uf) ?></option>
                <?php endforeach; ?>
            </select>
            <?= erro_campo($erros, 'uf') ?>
        </div>
    </div>
</fieldset>

<fieldset>
    <legend>Contato</legend>
    <div class="grade">
        <div>
            <label for="telefone">Telefone da empresa</label>
            <input type="text" id="telefone" name="telefone" data-mascara="telefone" required maxlength="15"
                   inputmode="tel" value="<?= $telefone('telefone') ?>"<?= invalido($erros, 'telefone') ?>>
            <?= erro_campo($erros, 'telefone') ?>
        </div>
        <div class="largo-2">
            <label for="email_financeiro">E-mail financeiro</label>
            <input type="email" id="email_financeiro" name="email_financeiro" required maxlength="254"
                   value="<?= $v('email_financeiro') ?>" aria-describedby="dica-financeiro"<?= invalido($erros, 'email_financeiro') ?>>
            <p class="dica" id="dica-financeiro">Recebe faturas e avisos de cobrança. Pode ser diferente do e-mail de login.</p>
            <?= erro_campo($erros, 'email_financeiro') ?>
        </div>
        <div>
            <label for="contato_nome">Responsável comercial <span class="opcional">(opcional)</span></label>
            <input type="text" id="contato_nome" name="contato_nome" value="<?= $v('contato_nome') ?>" maxlength="150"
                <?= invalido($erros, 'contato_nome') ?>>
            <?= erro_campo($erros, 'contato_nome') ?>
        </div>
        <div>
            <label for="contato_telefone">Telefone do responsável <span class="opcional">(opcional)</span></label>
            <input type="text" id="contato_telefone" name="contato_telefone" data-mascara="telefone" maxlength="15"
                   inputmode="tel" value="<?= $telefone('contato_telefone') ?>"<?= invalido($erros, 'contato_telefone') ?>>
            <?= erro_campo($erros, 'contato_telefone') ?>
        </div>
    </div>
</fieldset>
