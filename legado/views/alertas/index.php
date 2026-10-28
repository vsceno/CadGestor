<?php
/**
 * @var list<array<string, mixed>> $alertas
 * @var array<string, string> $tipos
 * @var string $filtroTipo
 * @var bool $somentePendentes
 * @var \CadGestor\Nucleo\Paginacao $paginacao
 * @var bool $podeTratar
 */

use CadGestor\Nucleo\Visao;

?>
<section class="cartao">
    <h1>Alertas</h1>

    <form method="get" action="/alertas" class="filtros">
        <div>
            <label for="situacao">Situação</label>
            <select id="situacao" name="situacao" data-auto-enviar>
                <option value="pendentes"<?= selecionado($somentePendentes) ?>>Pendentes</option>
                <option value="todos"<?= selecionado(!$somentePendentes) ?>>Todos</option>
            </select>
        </div>
        <div>
            <label for="tipo">Tipo</label>
            <select id="tipo" name="tipo" data-auto-enviar>
                <option value="">Todos</option>
                <?php foreach ($tipos as $valor => $rotulo) : ?>
                    <option value="<?= e($valor) ?>"<?= selecionado($filtroTipo === $valor) ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead><tr><th>Data/hora</th><th>Tipo</th><th>Descrição</th><th>Catraca</th><th>Pessoa</th><th>Tratamento</th></tr></thead>
            <tbody>
            <?php if ($alertas === []) : ?>
                <tr><td colspan="6" class="vazio">Nenhum alerta.</td></tr>
            <?php endif; ?>
            <?php foreach ($alertas as $a) : ?>
                <tr>
                    <td><?= e(formatar_data_hora($a['data_hora'], true)) ?></td>
                    <td><span class="selo <?= $a['tratado_em'] ? 'selo-neutro' : 'selo-falha' ?>"><?= e($tipos[$a['tipo']] ?? $a['tipo']) ?></span></td>
                    <td><?= e($a['descricao']) ?></td>
                    <td><?= e($a['catraca_nome'] ?? '—') ?></td>
                    <td><?= e($a['pessoa_nome'] ?? '—') ?></td>
                    <td>
                        <?php if ($a['tratado_em']) : ?>
                            <?= e(formatar_data_hora($a['tratado_em'])) ?> por <?= e($a['tratado_por_nome'] ?? '—') ?>
                            <?php if ($a['observacao']) : ?><br><small class="subtitulo"><?= e($a['observacao']) ?></small><?php endif; ?>
                        <?php elseif ($podeTratar) : ?>
                            <form method="post" action="/alertas/<?= e($a['id']) ?>/tratar" class="formulario">
                                <?= csrf_campo() ?>
                                <input type="text" name="observacao" maxlength="500" placeholder="Observação (opcional)"
                                       aria-label="Observação do tratamento">
                                <button type="submit" class="botao botao-pequeno">Marcar como tratado</button>
                            </form>
                        <?php else : ?>
                            Pendente
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= Visao::parcial('paginacao', [
        'paginacao' => $paginacao,
        'filtros' => ['situacao' => $somentePendentes ? 'pendentes' : 'todos', 'tipo' => $filtroTipo],
    ]) ?>
</section>
