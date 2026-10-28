<?php
/**
 * @var string|null $id
 * @var array<string, mixed> $dados
 * @var array<string, string> $erros
 * @var array<string, string> $tiposDocumento
 * @var list<string> $ufs
 * @var string $titulo
 */

$v = static fn (string $campo): string => e($dados[$campo] ?? '');
$cpf = (string) ($dados['cpf'] ?? '');
$telefone = (string) ($dados['telefone'] ?? '');
?>
<section class="cartao cartao-medio">
    <h1><?= e($titulo) ?></h1>

    <?php if ($erros !== []) : ?>
        <div class="alerta alerta-erro" role="alert">Corrija os campos destacados.</div>
    <?php endif; ?>

    <?php if (isset($erros['plano'])) : ?>
        <div class="alerta alerta-erro" role="alert">
            <?= e($erros['plano']) ?> <a href="/assinatura">Minha assinatura</a>
        </div>
    <?php endif; ?>
    <form method="post" action="<?= $id === null ? '/pessoas' : '/pessoas/' . e($id) ?>" class="formulario" autocomplete="off">
        <?= csrf_campo() ?>

        <fieldset>
            <legend>Identificação</legend>
            <div class="grade">
                <div class="largo">
                    <label for="nome">Nome completo</label>
                    <input type="text" id="nome" name="nome" value="<?= $v('nome') ?>" required maxlength="150"
                        <?= invalido($erros, 'nome') ?>>
                    <?= erro_campo($erros, 'nome') ?>
                </div>
                <div>
                    <label for="cpf">CPF</label>
                    <input type="text" id="cpf" name="cpf" data-mascara="cpf" required maxlength="14" inputmode="numeric"
                           value="<?= e(strlen($cpf) === 11 ? formatar_cpf($cpf) : $cpf) ?>"<?= invalido($erros, 'cpf') ?>>
                    <?= erro_campo($erros, 'cpf') ?>
                </div>
                <div>
                    <label for="data_nascimento">Data de nascimento</label>
                    <input type="date" id="data_nascimento" name="data_nascimento" value="<?= $v('data_nascimento') ?>"
                           max="<?= e(date('Y-m-d')) ?>" aria-describedby="dica-nasc"<?= invalido($erros, 'data_nascimento') ?>>
                    <p class="dica" id="dica-nasc">Necessária para coletar biometria (regra de menores de 18 anos).</p>
                    <?= erro_campo($erros, 'data_nascimento') ?>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Documento de identidade</legend>
            <div class="grade">
                <div>
                    <label for="documento_tipo">Tipo</label>
                    <select id="documento_tipo" name="documento_tipo"<?= invalido($erros, 'documento_tipo') ?>>
                        <option value="">Não informado</option>
                        <?php foreach ($tiposDocumento as $valor => $rotulo) : ?>
                            <option value="<?= e($valor) ?>"<?= selecionado(($dados['documento_tipo'] ?? '') === $valor) ?>><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= erro_campo($erros, 'documento_tipo') ?>
                </div>
                <div>
                    <label for="documento_numero">Número</label>
                    <input type="text" id="documento_numero" name="documento_numero" value="<?= $v('documento_numero') ?>"
                           maxlength="30"<?= invalido($erros, 'documento_numero') ?>>
                    <?= erro_campo($erros, 'documento_numero') ?>
                </div>
                <div>
                    <label for="documento_orgao">Órgão emissor</label>
                    <input type="text" id="documento_orgao" name="documento_orgao" value="<?= $v('documento_orgao') ?>"
                           maxlength="20"<?= invalido($erros, 'documento_orgao') ?>>
                    <?= erro_campo($erros, 'documento_orgao') ?>
                </div>
                <div>
                    <label for="documento_uf">UF</label>
                    <select id="documento_uf" name="documento_uf"<?= invalido($erros, 'documento_uf') ?>>
                        <option value="">—</option>
                        <?php foreach ($ufs as $uf) : ?>
                            <option value="<?= e($uf) ?>"<?= selecionado(($dados['documento_uf'] ?? '') === $uf) ?>><?= e($uf) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= erro_campo($erros, 'documento_uf') ?>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Contato</legend>
            <div class="grade">
                <div>
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" value="<?= $v('email') ?>" maxlength="254"
                        <?= invalido($erros, 'email') ?>>
                    <?= erro_campo($erros, 'email') ?>
                </div>
                <div>
                    <label for="telefone">Telefone</label>
                    <input type="text" id="telefone" name="telefone" data-mascara="telefone" maxlength="15"
                           value="<?= e(preg_match('/^\d{10,11}$/', $telefone) ? formatar_telefone($telefone) : $telefone) ?>"
                        <?= invalido($erros, 'telefone') ?>>
                    <?= erro_campo($erros, 'telefone') ?>
                </div>
            </div>
        </fieldset>

        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar</button>
            <a href="<?= $id === null ? '/pessoas' : '/pessoas/' . e($id) ?>" class="botao">Cancelar</a>
        </div>
    </form>
</section>
