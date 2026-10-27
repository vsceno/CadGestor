<?php
/**
 * @var array<string, mixed> $pessoa
 * @var list<array<string, mixed>> $vinculos
 * @var list<array<string, mixed>> $opcoesVinculo
 * @var array<string, mixed>|null $lgpd
 */

use CadGestor\Dominio\Idade;
use CadGestor\Dominio\ValidadeVinculo;
use CadGestor\Nucleo\Visao;

$id = (string) $pessoa['id'];
$hoje = new DateTimeImmutable('now');
$anonimizada = $pessoa['anonimizada_em'] !== null;
$idade = Idade::emAnos($pessoa['data_nascimento'], $hoje);

$opcoesPorEmpresa = [];
foreach ($opcoesVinculo as $opcao) {
    $opcoesPorEmpresa[$opcao['razao_social']][] = $opcao;
}

$classeSituacao = [
    ValidadeVinculo::VIGENTE => 'selo-ok',
    ValidadeVinculo::FUTURO => 'selo-aviso',
    ValidadeVinculo::VENCIDO => 'selo-falha',
    ValidadeVinculo::SUSPENSO => 'selo-aviso',
    ValidadeVinculo::ENCERRADO => 'selo-neutro',
];
?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <div>
            <h1><?= e($pessoa['nome']) ?></h1>
            <p class="subtitulo">
                CPF <?= e($anonimizada ? '—' : formatar_cpf($pessoa['cpf'])) ?> ·
                <span class="selo <?= $pessoa['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                    <?= $pessoa['ativo'] ? 'Ativa' : 'Inativa' ?>
                </span>
                <?php if ($anonimizada) : ?>
                    <span class="selo selo-neutro">Anonimizada em <?= e(formatar_data_hora($pessoa['anonimizada_em'])) ?></span>
                <?php endif; ?>
            </p>
        </div>
        <?php if (!$anonimizada) : ?>
            <div class="acoes">
                <a class="botao" href="/pessoas/<?= e($id) ?>/editar">Editar dados</a>
                <form method="post" action="/pessoas/<?= e($id) ?>/situacao" class="form-inline"
                      data-confirmar="<?= $pessoa['ativo'] ? 'Inativar a pessoa? O acesso será negado em todas as catracas.' : 'Reativar a pessoa?' ?>">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="ativo" value="<?= $pessoa['ativo'] ? '0' : '1' ?>">
                    <button type="submit" class="botao <?= $pessoa['ativo'] ? 'botao-perigo' : '' ?>">
                        <?= $pessoa['ativo'] ? 'Inativar' : 'Reativar' ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$anonimizada) : ?>
        <dl class="dados">
            <dt>Nascimento</dt>
            <dd>
                <?php if ($pessoa['data_nascimento']) : ?>
                    <?= e(formatar_data($pessoa['data_nascimento'])) ?> (<?= e($idade) ?> anos)
                    <?php if ($idade !== null && $idade < Idade::MAIORIDADE) : ?>
                        <span class="selo selo-aviso">Menor de idade</span>
                    <?php endif; ?>
                <?php else : ?>
                    <span class="selo selo-aviso">Não informado: coleta biométrica bloqueada</span>
                <?php endif; ?>
            </dd>
            <?php if ($pessoa['documento_tipo']) : ?>
                <dt>Documento</dt>
                <dd><?= e(mb_strtoupper($pessoa['documento_tipo'])) ?> <?= e($pessoa['documento_numero']) ?>
                    <?= e(trim(($pessoa['documento_orgao'] ?? '') . '/' . ($pessoa['documento_uf'] ?? ''), '/')) ?></dd>
            <?php endif; ?>
            <?php if ($pessoa['email'] || $pessoa['telefone']) : ?>
                <dt>Contato</dt>
                <dd><?= e(implode(' · ', array_filter([$pessoa['email'], formatar_telefone($pessoa['telefone'])]))) ?></dd>
            <?php endif; ?>
        </dl>
    <?php endif; ?>
</section>

<section class="cartao" id="vinculos">
    <h2>Vínculos</h2>
    <p class="dica">O histórico é preservado: vínculos encerrados continuam listados.</p>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr><th>Empresa</th><th>Perfil</th><th>Período</th><th>Situação</th><th>Regras</th><th>Ações</th></tr>
            </thead>
            <tbody>
            <?php if ($vinculos === []) : ?>
                <tr><td colspan="6" class="vazio">Nenhum vínculo.</td></tr>
            <?php endif; ?>
            <?php foreach ($vinculos as $v) : ?>
                <?php
                $situacao = ValidadeVinculo::situacao($v['status'], $v['data_inicio'], $v['data_fim'], $hoje);
                $dias = ValidadeVinculo::diasParaVencer($v['data_fim'], $hoje);
                ?>
                <tr>
                    <td><?= e($v['razao_social']) ?></td>
                    <td><?= e($v['perfil_nome']) ?></td>
                    <td>
                        <?= e(formatar_data($v['data_inicio'])) ?> a <?= e($v['data_fim'] ? formatar_data($v['data_fim']) : 'indeterminado') ?>
                        <?php if ($situacao === ValidadeVinculo::VIGENTE && $dias !== null && $dias <= 30) : ?>
                            <br><small class="subtitulo">vence em <?= e($dias) ?> dia(s)</small>
                        <?php endif; ?>
                        <?php if ($v['motivo_encerramento']) : ?>
                            <br><small class="subtitulo">Motivo: <?= e($v['motivo_encerramento']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="selo <?= e($classeSituacao[$situacao]) ?>"><?= e(ValidadeVinculo::rotulo($situacao)) ?></span></td>
                    <td>
                        <a href="/vinculos/<?= e($v['id']) ?>/regras">
                            <?= (int) $v['regras_proprias'] > 0 ? 'Próprias (' . e($v['regras_proprias']) . ')' : 'Do perfil' ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($v['status'] !== 'encerrado' && !$anonimizada) : ?>
                            <details>
                                <summary>Gerenciar</summary>
                                <form method="post" action="/vinculos/<?= e($v['id']) ?>" class="formulario">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="acao" value="periodo">
                                    <label for="ini-<?= e($v['id']) ?>">Início</label>
                                    <input type="date" id="ini-<?= e($v['id']) ?>" name="data_inicio" value="<?= e($v['data_inicio']) ?>" required>
                                    <label for="fim-<?= e($v['id']) ?>">Validade</label>
                                    <input type="date" id="fim-<?= e($v['id']) ?>" name="data_fim" value="<?= e($v['data_fim'] ?? '') ?>">
                                    <button type="submit" class="botao botao-pequeno">Alterar período</button>
                                </form>
                                <form method="post" action="/vinculos/<?= e($v['id']) ?>" class="form-inline">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="acao" value="<?= $v['status'] === 'suspenso' ? 'reativar' : 'suspender' ?>">
                                    <button type="submit" class="botao botao-pequeno"><?= $v['status'] === 'suspenso' ? 'Reativar' : 'Suspender' ?></button>
                                </form>
                                <form method="post" action="/vinculos/<?= e($v['id']) ?>" class="formulario"
                                      data-confirmar="Encerrar o vínculo? Esta ação não pode ser desfeita (o histórico é mantido).">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="acao" value="encerrar">
                                    <label for="mot-<?= e($v['id']) ?>">Motivo do encerramento</label>
                                    <input type="text" id="mot-<?= e($v['id']) ?>" name="motivo" required maxlength="255">
                                    <button type="submit" class="botao botao-pequeno botao-perigo">Encerrar</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!$anonimizada && $pessoa['ativo']) : ?>
        <h2>Novo vínculo</h2>
        <form method="post" action="/pessoas/<?= e($id) ?>/vinculos" class="formulario">
            <?= csrf_campo() ?>
            <div class="grade">
                <div class="largo">
                    <label for="empresa_perfil">Empresa e perfil</label>
                    <select id="empresa_perfil" name="empresa_perfil" required>
                        <option value="">Selecione</option>
                        <?php foreach ($opcoesPorEmpresa as $empresa => $opcoes) : ?>
                            <optgroup label="<?= e($empresa) ?>">
                                <?php foreach ($opcoes as $o) : ?>
                                    <option value="<?= e($o['empresa_id'] . '|' . $o['perfil_id']) ?>"><?= e($o['perfil_nome']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <p class="dica">Só aparecem empresas ativas e os perfis que cada uma aceita.</p>
                </div>
                <div>
                    <label for="data_inicio">Início</label>
                    <input type="date" id="data_inicio" name="data_inicio" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div>
                    <label for="data_fim">Validade (opcional)</label>
                    <input type="date" id="data_fim" name="data_fim">
                </div>
            </div>
            <div class="acoes"><button type="submit" class="botao botao-primario">Adicionar vínculo</button></div>
        </form>
    <?php endif; ?>
</section>

<?php if ($lgpd !== null) : ?>
    <?= Visao::parcial('lgpd-pessoa', $lgpd + ['pessoa' => $pessoa]) ?>
<?php endif; ?>
