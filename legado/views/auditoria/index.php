<?php
/**
 * @var array<string, ?string> $filtros
 * @var list<array<string, mixed>> $registros
 * @var \CadGestor\Nucleo\Paginacao $paginacao
 * @var list<string> $acoes
 * @var list<string> $tabelas
 * @var list<array{id: string, nome: string}> $usuarios
 */

use CadGestor\Nucleo\Visao;

$query = array_filter($filtros, static fn (?string $v): bool => $v !== null && $v !== '');
$json = static function (?string $valor): string {
    if ($valor === null) {
        return '';
    }

    $dados = json_decode($valor, true);

    return is_array($dados) ? (string) json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $valor;
};
?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <h1>Trilha de auditoria</h1>
        <a class="botao" href="/auditoria/exportar?<?= e(http_build_query($query)) ?>">Exportar CSV</a>
    </div>
    <p class="dica">Registro somente-acréscimo: a aplicação não consegue alterar nem apagar estas linhas (permissão do banco).</p>

    <form method="get" action="/auditoria" class="filtros">
        <div><label for="inicio">De</label><input type="date" id="inicio" name="inicio" value="<?= e($filtros['inicio']) ?>"></div>
        <div><label for="fim">Até</label><input type="date" id="fim" name="fim" value="<?= e($filtros['fim']) ?>"></div>
        <div>
            <label for="acao">Ação</label>
            <select id="acao" name="acao">
                <option value="">Todas</option>
                <?php foreach ($acoes as $a) : ?><option value="<?= e($a) ?>"<?= selecionado($filtros['acao'] === $a) ?>><?= e($a) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="tabela">Tabela</label>
            <select id="tabela" name="tabela">
                <option value="">Todas</option>
                <?php foreach ($tabelas as $t) : ?><option value="<?= e($t) ?>"<?= selecionado($filtros['tabela'] === $t) ?>><?= e($t) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="usuario">Usuário</label>
            <select id="usuario" name="usuario">
                <option value="">Todos</option>
                <?php foreach ($usuarios as $u) : ?><option value="<?= e($u['id']) ?>"<?= selecionado($filtros['usuario'] === $u['id']) ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div><button type="submit" class="botao botao-primario">Aplicar</button></div>
    </form>

    <div class="tabela-rolagem">
        <table class="tabela tabela-compacta">
            <thead><tr><th>Data/hora</th><th>Usuário</th><th>Ação</th><th>Tabela / registro</th><th>Alterações</th><th>IP</th></tr></thead>
            <tbody>
            <?php if ($registros === []) : ?><tr><td colspan="6" class="vazio">Nenhum registro no período.</td></tr><?php endif; ?>
            <?php foreach ($registros as $r) : ?>
                <tr>
                    <td><?= e(formatar_data_hora($r['data_hora'], true)) ?></td>
                    <td><?= e($r['usuario_nome'] ?? '(sistema)') ?></td>
                    <td><code><?= e($r['acao']) ?></code></td>
                    <td><?= e($r['tabela'] ?? '—') ?><?php if ($r['registro_id']) : ?><br><small class="subtitulo"><?= e(substr((string) $r['registro_id'], 0, 13)) ?>…</small><?php endif; ?></td>
                    <td>
                        <?php if ($r['dados_antes'] || $r['dados_depois']) : ?>
                            <details>
                                <summary>ver</summary>
                                <?php if ($r['dados_antes']) : ?><strong>Antes</strong><pre class="trace"><?= e($json($r['dados_antes'])) ?></pre><?php endif; ?>
                                <?php if ($r['dados_depois']) : ?><strong>Depois</strong><pre class="trace"><?= e($json($r['dados_depois'])) ?></pre><?php endif; ?>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td><?= e($r['ip'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= Visao::parcial('paginacao', ['paginacao' => $paginacao, 'filtros' => $query]) ?>
</section>
