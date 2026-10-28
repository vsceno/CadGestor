<?php
/**
 * Diagnóstico do ambiente e do banco (apenas fora de produção).
 *
 * @var string $titulo
 * @var string $ambiente
 * @var list<array{item: string, ok: bool, detalhe: string}> $verificacoes
 * @var list<array{item: string, ok: bool, detalhe: string}> $verificacoesDados
 * @var list<array{tabela: string, colunas: int, registros: int}> $tabelas
 */
$todas = [...$verificacoes, ...$verificacoesDados];
$falhas = count(array_filter($todas, static fn (array $v): bool => !$v['ok']));
$grupos = ['Ambiente' => $verificacoes, 'Banco de dados (Fase 1)' => $verificacoesDados];
?>
<section class="cartao">
    <h1><?= e($titulo) ?></h1>
    <p class="subtitulo">
        Ambiente: <strong><?= e($ambiente) ?></strong> ·
        <?php if ($falhas === 0) : ?>
            <span class="selo selo-ok">tudo certo</span>
        <?php else : ?>
            <span class="selo selo-falha"><?= e($falhas) ?> pendência(s)</span>
        <?php endif; ?>
    </p>

    <?php foreach ($grupos as $nomeGrupo => $itens) : ?>
        <h2><?= e($nomeGrupo) ?></h2>
        <table class="tabela">
            <thead>
                <tr><th>Verificação</th><th>Situação</th><th>Detalhe</th></tr>
            </thead>
            <tbody>
            <?php foreach ($itens as $v) : ?>
                <tr>
                    <td><?= e($v['item']) ?></td>
                    <td>
                        <span class="selo <?= $v['ok'] ? 'selo-ok' : 'selo-falha' ?>"><?= $v['ok'] ? 'OK' : 'Falha' ?></span>
                    </td>
                    <td><?= e($v['detalhe']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>

    <?php if ($tabelas !== []) : ?>
        <h2>Tabelas do schema (<?= e(count($tabelas)) ?>)</h2>
        <table class="tabela tabela-compacta">
            <thead>
                <tr><th>Tabela</th><th class="numero">Colunas</th><th class="numero">Registros</th></tr>
            </thead>
            <tbody>
            <?php foreach ($tabelas as $t) : ?>
                <tr>
                    <td><code><?= e($t['tabela']) ?></code></td>
                    <td class="numero"><?= e($t['colunas']) ?></td>
                    <td class="numero"><?= e($t['registros']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="nota">Registros são estimativa das estatísticas do PostgreSQL.</p>
    <?php endif; ?>

    <p class="nota">Health check em JSON: <a href="/saude">/saude</a></p>
</section>
