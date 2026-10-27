<?php
/**
 * @var string|null $id
 * @var array<string, mixed> $dados
 * @var array<string, string> $erros
 * @var list<array<string, mixed>> $empresas
 * @var array<string, string> $tipos
 * @var array<string, string> $modos
 * @var string $titulo
 */

$v = static fn (string $campo): string => e($dados[$campo] ?? '');
?>
<section class="cartao cartao-medio">
    <h1><?= e($titulo) ?></h1>

    <?php if ($id !== null) : ?>
        <p class="subtitulo">
            Empresa: <a href="/empresas/<?= e($dados['empresa_id']) ?>"><?= e($dados['razao_social'] ?? '') ?></a> ·
            <span class="selo <?= ($dados['ativo'] ?? false) ? 'selo-ok' : 'selo-neutro' ?>">
                <?= ($dados['ativo'] ?? false) ? 'Ativa' : 'Inativa' ?>
            </span> ·
            Último contato: <?= e(formatar_data_hora($dados['ultimo_contato_em'] ?? null) ?: 'nunca') ?>
        </p>
    <?php endif; ?>

    <form method="post" action="<?= $id === null ? '/catracas' : '/catracas/' . e($id) ?>" class="formulario">
        <?= csrf_campo() ?>

        <?php if ($id === null) : ?>
            <label for="empresa_id">Empresa</label>
            <select id="empresa_id" name="empresa_id" required<?= invalido($erros, 'empresa_id') ?>>
                <option value="">Selecione</option>
                <?php foreach ($empresas as $empresa) : ?>
                    <option value="<?= e($empresa['id']) ?>"<?= selecionado(($dados['empresa_id'] ?? '') === $empresa['id']) ?>>
                        <?= e($empresa['razao_social']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?= erro_campo($erros, 'empresa_id') ?>
        <?php endif; ?>

        <div class="grade">
            <div>
                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" value="<?= $v('nome') ?>" required maxlength="100"
                    <?= invalido($erros, 'nome') ?>>
                <?= erro_campo($erros, 'nome') ?>
            </div>
            <div>
                <label for="localizacao">Localização</label>
                <input type="text" id="localizacao" name="localizacao" value="<?= $v('localizacao') ?>" maxlength="150">
                <?= erro_campo($erros, 'localizacao') ?>
            </div>
            <div class="largo">
                <label for="identificador_dispositivo">Identificador do dispositivo</label>
                <input type="text" id="identificador_dispositivo" name="identificador_dispositivo" required maxlength="100"
                       value="<?= $v('identificador_dispositivo') ?>" aria-describedby="dica-ident"
                    <?= invalido($erros, 'identificador_dispositivo') ?>>
                <p class="dica" id="dica-ident">Número de série ou código configurado na catraca.</p>
                <?= erro_campo($erros, 'identificador_dispositivo') ?>
            </div>
            <div>
                <label for="tipo_identificacao">Identificação</label>
                <select id="tipo_identificacao" name="tipo_identificacao"<?= invalido($erros, 'tipo_identificacao') ?>>
                    <?php foreach ($tipos as $valor => $rotulo) : ?>
                        <option value="<?= e($valor) ?>"<?= selecionado(($dados['tipo_identificacao'] ?? '') === $valor) ?>>
                            <?= e($rotulo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="modo_falha">Modo de falha</label>
                <select id="modo_falha" name="modo_falha"<?= invalido($erros, 'modo_falha') ?>>
                    <?php foreach ($modos as $valor => $rotulo) : ?>
                        <option value="<?= e($valor) ?>"<?= selecionado(($dados['modo_falha'] ?? '') === $valor) ?>>
                            <?= e($rotulo) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="fabricante">Fabricante</label>
                <input type="text" id="fabricante" name="fabricante" value="<?= $v('fabricante') ?>" maxlength="100">
            </div>
            <div>
                <label for="modelo">Modelo</label>
                <input type="text" id="modelo" name="modelo" value="<?= $v('modelo') ?>" maxlength="100">
            </div>
        </div>
        <p class="dica">
            Modo de falha: comportamento quando a catraca não consegue falar com o servidor.
            "Bloquear" é o mais seguro; em rotas de fuga, a liberação em emergência deve ser feita pelo
            hardware/alarme de incêndio, independentemente do sistema.
        </p>

        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar</button>
            <a href="/catracas" class="botao">Voltar</a>
        </div>
    </form>

    <?php if ($id !== null) : ?>
        <hr>
        <div class="acoes">
            <form method="post" action="/catracas/<?= e($id) ?>/token" class="form-inline"
                  data-confirmar="Gerar novo token? O token atual deixará de funcionar imediatamente e a catraca precisará ser reconfigurada.">
                <?= csrf_campo() ?>
                <button type="submit" class="botao">Gerar novo token</button>
            </form>
            <form method="post" action="/catracas/<?= e($id) ?>/situacao" class="form-inline"
                  data-confirmar="<?= ($dados['ativo'] ?? false) ? 'Inativar a catraca? Ela passará a negar todos os acessos.' : 'Reativar a catraca?' ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="ativo" value="<?= ($dados['ativo'] ?? false) ? '0' : '1' ?>">
                <button type="submit" class="botao <?= ($dados['ativo'] ?? false) ? 'botao-perigo' : '' ?>">
                    <?= ($dados['ativo'] ?? false) ? 'Inativar' : 'Reativar' ?>
                </button>
            </form>
        </div>
    <?php endif; ?>
</section>
