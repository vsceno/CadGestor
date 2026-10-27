<?php
/**
 * @var array<string, ?string> $filtros
 * @var string|null $aviso
 * @var list<array<string, mixed>> $porPerfil
 * @var list<array<string, mixed>> $porEmpresa
 * @var list<array<string, mixed>> $porMotivo
 * @var list<array<string, mixed>> $porDia
 * @var list<array<string, mixed>> $eventos
 * @var \CadGestor\Nucleo\Paginacao $paginacao
 * @var list<array{id: string, nome: string}> $empresas
 * @var list<array{id: string, nome: string}> $perfis
 * @var list<array{id: string, nome: string}> $catracas
 */

use CadGestor\Acesso\ResultadoDecisao;
use CadGestor\Nucleo\Visao;

$query = array_filter($filtros, static fn (?string $v): bool => $v !== null && $v !== '');
$exportar = static fn (string $tipo): string => '/relatorios/exportar?' . http_build_query($query + ['tipo' => $tipo]);
$totalLiberados = array_sum(array_column($porPerfil, 'liberados'));
$totalNegados = array_sum(array_column($porPerfil, 'negados'));
$total = $totalLiberados + $totalNegados;

$selectOpcoes = static function (string $nome, string $rotulo, array $opcoes, ?string $atual): string {
    $html = '<div><label for="' . e($nome) . '">' . e($rotulo) . '</label><select id="' . e($nome) . '" name="' . e($nome) . '">'
        . '<option value="">Todos</option>';
    foreach ($opcoes as $o) {
        $html .= '<option value="' . e($o['id']) . '"' . selecionado($atual === $o['id']) . '>' . e($o['nome']) . '</option>';
    }

    return $html . '</select></div>';
};

$tabelaResumo = static function (string $titulo, array $linhas, string $tipoExportacao) use ($exportar): string {
    ob_start(); ?>
    <section class="cartao">
        <div class="cabecalho-pagina">
            <h2><?= e($titulo) ?></h2>
            <a class="botao botao-pequeno" href="<?= e($exportar($tipoExportacao)) ?>">Exportar CSV</a>
        </div>
        <table class="tabela tabela-compacta">
            <thead><tr><th>Grupo</th><th class="numero">Total</th><th class="numero">Liberados</th>
                <th class="numero">Negados</th><th class="numero">% negados</th><th class="numero">Pessoas</th></tr></thead>
            <tbody>
            <?php if ($linhas === []) : ?><tr><td colspan="6" class="vazio">Sem eventos no período.</td></tr><?php endif; ?>
            <?php foreach ($linhas as $l) : ?>
                <tr>
                    <td><?= e($l['grupo']) ?></td>
                    <td class="numero"><?= e($l['total']) ?></td>
                    <td class="numero"><?= e($l['liberados']) ?></td>
                    <td class="numero"><?= e($l['negados']) ?></td>
                    <td class="numero"><?= e(number_format(100 * $l['negados'] / max(1, $l['total']), 1, ',', '')) ?>%</td>
                    <td class="numero"><?= e($l['pessoas']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php
    return (string) ob_get_clean();
};
?>
<section class="cartao">
    <h1>Relatórios de acesso</h1>
    <?php if ($aviso !== null) : ?><div class="alerta alerta-aviso"><?= e($aviso) ?></div><?php endif; ?>

    <form method="get" action="/relatorios" class="filtros">
        <div><label for="inicio">De</label><input type="date" id="inicio" name="inicio" value="<?= e($filtros['inicio']) ?>"></div>
        <div><label for="fim">Até</label><input type="date" id="fim" name="fim" value="<?= e($filtros['fim']) ?>"></div>
        <?= $selectOpcoes('empresa', 'Empresa', $empresas, $filtros['empresa']) ?>
        <?= $selectOpcoes('perfil', 'Perfil', $perfis, $filtros['perfil']) ?>
        <?= $selectOpcoes('catraca', 'Catraca', $catracas, $filtros['catraca']) ?>
        <div>
            <label for="resultado">Resultado</label>
            <select id="resultado" name="resultado">
                <option value="">Todos</option>
                <option value="liberado"<?= selecionado($filtros['resultado'] === 'liberado') ?>>Liberado</option>
                <option value="negado"<?= selecionado($filtros['resultado'] === 'negado') ?>>Negado</option>
            </select>
        </div>
        <div><button type="submit" class="botao botao-primario">Aplicar</button></div>
    </form>

    <div class="indicadores">
        <div class="indicador"><span class="indicador-valor"><?= e(number_format($total, 0, ',', '.')) ?></span><span class="indicador-rotulo">Eventos no período</span></div>
        <div class="indicador"><span class="indicador-valor"><?= e(number_format($totalLiberados, 0, ',', '.')) ?></span><span class="indicador-rotulo">Liberados</span></div>
        <div class="indicador"><span class="indicador-valor"><?= e(number_format($totalNegados, 0, ',', '.')) ?></span><span class="indicador-rotulo">Negados</span></div>
    </div>
</section>

<?= $tabelaResumo('Por perfil', $porPerfil, 'perfil') ?>
<?= $tabelaResumo('Por empresa', $porEmpresa, 'empresa') ?>

<div class="grade">
    <section class="cartao">
        <h2>Motivos de negação</h2>
        <table class="tabela tabela-compacta">
            <thead><tr><th>Motivo</th><th class="numero">Ocorrências</th></tr></thead>
            <tbody>
            <?php if ($porMotivo === []) : ?><tr><td colspan="2" class="vazio">Nenhuma negação.</td></tr><?php endif; ?>
            <?php foreach ($porMotivo as $m) : ?>
                <tr><td><?= e(ResultadoDecisao::rotuloMotivo($m['motivo'])) ?></td><td class="numero"><?= e($m['total']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <section class="cartao">
        <h2>Por dia</h2>
        <table class="tabela tabela-compacta">
            <thead><tr><th>Dia</th><th class="numero">Liberados</th><th class="numero">Negados</th></tr></thead>
            <tbody>
            <?php if ($porDia === []) : ?><tr><td colspan="3" class="vazio">Sem eventos.</td></tr><?php endif; ?>
            <?php foreach ($porDia as $d) : ?>
                <tr><td><?= e(formatar_data($d['dia'])) ?></td><td class="numero"><?= e($d['liberados']) ?></td><td class="numero"><?= e($d['negados']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<section class="cartao">
    <div class="cabecalho-pagina">
        <h2>Eventos</h2>
        <a class="botao botao-pequeno" href="<?= e($exportar('eventos')) ?>">Exportar CSV</a>
    </div>
    <p class="dica">CPF exibido de forma mascarada. Exportações ficam registradas na trilha de auditoria.</p>
    <div class="tabela-rolagem">
        <table class="tabela tabela-compacta">
            <thead><tr><th>Data/hora</th><th>Empresa</th><th>Catraca</th><th>Pessoa</th><th>CPF</th><th>Perfil</th>
                <th>Tipo</th><th>Resultado</th><th>Motivo</th></tr></thead>
            <tbody>
            <?php if ($eventos === []) : ?><tr><td colspan="9" class="vazio">Nenhum evento no período.</td></tr><?php endif; ?>
            <?php foreach ($eventos as $ev) : ?>
                <tr>
                    <td><?= e(formatar_data_hora($ev['data_hora'], true)) ?><?= $ev['offline'] ? ' <span class="selo selo-neutro">offline</span>' : '' ?></td>
                    <td><?= e($ev['empresa_nome'] ?? '—') ?></td>
                    <td><?= e($ev['catraca_nome'] ?? '—') ?></td>
                    <td><?= e($ev['pessoa_nome'] ?? '—') ?></td>
                    <td><?= e(mascarar_cpf($ev['pessoa_cpf'])) ?></td>
                    <td><?= e($ev['perfil_nome'] ?? '—') ?></td>
                    <td><?= e($ev['tipo_identificacao']) ?></td>
                    <td><span class="selo <?= $ev['resultado'] === 'liberado' ? 'selo-ok' : 'selo-falha' ?>"><?= e($ev['resultado']) ?></span></td>
                    <td><?= e(ResultadoDecisao::rotuloMotivo($ev['motivo_negacao'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= Visao::parcial('paginacao', ['paginacao' => $paginacao, 'filtros' => $query]) ?>
</section>
