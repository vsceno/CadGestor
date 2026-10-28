<?php
/**
 * @var array<string, mixed> $empresa
 * @var list<array<string, mixed>> $perfis
 * @var list<string> $perfisAceitos
 * @var list<array<string, mixed>> $horarios
 * @var list<array<string, mixed>> $catracas
 * @var int $totalRegras
 */

use CadGestor\Nucleo\Visao;

$id = (string) $empresa['id'];
?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <div>
            <h1><?= e($empresa['razao_social']) ?></h1>
            <p class="subtitulo">
                CNPJ <?= e(formatar_cnpj($empresa['cnpj'])) ?> ·
                <span class="selo <?= $empresa['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                    <?= $empresa['ativo'] ? 'Ativa' : 'Inativa' ?>
                </span>
            </p>
        </div>
        <div class="acoes">
            <a class="botao" href="/empresas/<?= e($id) ?>/editar">Editar dados</a>
            <form method="post" action="/empresas/<?= e($id) ?>/situacao" class="form-inline"
                  data-confirmar="<?= $empresa['ativo'] ? 'Inativar a empresa? As catracas dela passarão a negar acessos.' : 'Reativar a empresa?' ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="ativo" value="<?= $empresa['ativo'] ? '0' : '1' ?>">
                <button type="submit" class="botao <?= $empresa['ativo'] ? 'botao-perigo' : '' ?>">
                    <?= $empresa['ativo'] ? 'Inativar' : 'Reativar' ?>
                </button>
            </form>
        </div>
    </div>

    <dl class="dados">
        <?php if ($empresa['nome_fantasia']) : ?>
            <dt>Nome fantasia</dt><dd><?= e($empresa['nome_fantasia']) ?></dd>
        <?php endif; ?>
        <dt>Endereço</dt>
        <dd>
            <?= e($empresa['logradouro']) ?>, <?= e($empresa['numero']) ?>
            <?= $empresa['complemento'] ? ' - ' . e($empresa['complemento']) : '' ?> ·
            <?= e($empresa['bairro']) ?> · <?= e($empresa['municipio']) ?>/<?= e($empresa['uf']) ?> ·
            CEP <?= e(formatar_cep($empresa['cep'])) ?>
        </dd>
        <dt>Responsável legal</dt>
        <dd>
            <?= e($empresa['responsavel_nome']) ?><?= $empresa['responsavel_cargo'] ? ' (' . e($empresa['responsavel_cargo']) . ')' : '' ?>
            · <?= e($empresa['responsavel_email']) ?> · <?= e(formatar_telefone($empresa['responsavel_telefone'])) ?>
        </dd>
    </dl>
</section>

<section class="cartao" id="perfis">
    <h2>Perfis aceitos</h2>
    <p class="dica">Só pessoas com vínculo em um destes perfis podem passar nas catracas desta empresa.</p>
    <form method="post" action="/empresas/<?= e($id) ?>/perfis" class="formulario">
        <?= csrf_campo() ?>
        <?php foreach ($perfis as $perfil) : ?>
            <label class="opcao">
                <input type="checkbox" name="perfis[]" value="<?= e($perfil['id']) ?>"
                    <?= marcado(in_array($perfil['id'], $perfisAceitos, true)) ?>>
                <?= e($perfil['nome']) ?>
            </label>
        <?php endforeach; ?>
        <div class="acoes"><button type="submit" class="botao botao-primario">Salvar perfis</button></div>
    </form>
</section>

<section class="cartao" id="horarios">
    <h2>Horário padrão de funcionamento</h2>
    <p class="dica">Usado quando o perfil não tem regras próprias nesta empresa.</p>
    <form method="post" action="/empresas/<?= e($id) ?>/horarios" class="formulario">
        <?= csrf_campo() ?>
        <?= Visao::parcial('editor-horarios', [
            'idBloco' => 'horarios-padrao',
            'linhas' => $horarios,
            'catracas' => null,
        ]) ?>
        <div class="acoes"><button type="submit" class="botao botao-primario">Salvar horários</button></div>
    </form>
</section>

<section class="cartao" id="catracas">
    <div class="cabecalho-pagina">
        <h2>Catracas</h2>
        <div class="acoes">
            <a class="botao" href="/empresas/<?= e($id) ?>/regras">Regras de acesso (<?= e($totalRegras) ?>)</a>
            <a class="botao botao-primario" href="/catracas/nova?empresa=<?= e($id) ?>">Nova catraca</a>
        </div>
    </div>
    <table class="tabela">
        <thead><tr><th>Nome</th><th>Identificador</th><th>Modo de falha</th><th>Último contato</th><th>Situação</th></tr></thead>
        <tbody>
        <?php if ($catracas === []) : ?>
            <tr><td colspan="5" class="vazio">Nenhuma catraca cadastrada.</td></tr>
        <?php endif; ?>
        <?php foreach ($catracas as $catraca) : ?>
            <tr>
                <td><a href="/catracas/<?= e($catraca['id']) ?>/editar"><?= e($catraca['nome']) ?></a></td>
                <td><code><?= e($catraca['identificador_dispositivo']) ?></code></td>
                <td><?= $catraca['modo_falha'] === 'bloquear' ? 'Bloquear' : 'Liberar' ?></td>
                <td><?= e(formatar_data_hora($catraca['ultimo_contato_em']) ?: '—') ?></td>
                <td>
                    <span class="selo <?= $catraca['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                        <?= $catraca['ativo'] ? 'Ativa' : 'Inativa' ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
