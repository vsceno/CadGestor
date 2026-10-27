<?php
/**
 * Diagnóstico do ambiente (apenas fora de produção).
 *
 * @var string $titulo
 * @var string $ambiente
 * @var list<array{item: string, ok: bool, detalhe: string}> $verificacoes
 */
$falhas = count(array_filter($verificacoes, static fn (array $v): bool => !$v['ok']));
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

    <table class="tabela">
        <thead>
            <tr><th>Verificação</th><th>Situação</th><th>Detalhe</th></tr>
        </thead>
        <tbody>
        <?php foreach ($verificacoes as $v) : ?>
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

    <p class="nota">Health check em JSON: <a href="/saude">/saude</a></p>
</section>
