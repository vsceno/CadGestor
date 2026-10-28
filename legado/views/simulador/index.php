<?php
/**
 * @var list<array<string, mixed>> $catracas
 * @var list<array<string, mixed>> $pessoas
 * @var array<string, string> $cenarios
 * @var array<string, string> $entrada
 * @var array<string, mixed>|null $resultado
 * @var list<array<string, mixed>> $eventos
 */

use CadGestor\Acesso\ResultadoDecisao;

?>
<section class="cartao">
    <h1>Simulador de catraca</h1>
    <div class="alerta alerta-info">
        Ferramenta de desenvolvimento (indisponível em produção). Usa o mesmo motor de decisão da API, sem o token
        do dispositivo. Os eventos e alertas gerados são gravados normalmente.
    </div>

    <form method="post" action="/simulador" class="formulario">
        <?= csrf_campo() ?>
        <div class="grade">
            <div>
                <label for="catraca">Catraca</label>
                <select id="catraca" name="catraca" required>
                    <?php foreach ($catracas as $c) : ?>
                        <option value="<?= e($c['id']) ?>"<?= selecionado(($entrada['catraca'] ?? '') === $c['id']) ?>>
                            <?= e($c['nome']) ?> · <?= e($c['razao_social']) ?><?= $c['ativo'] ? '' : ' (inativa)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="pessoa">Pessoa apresentada</label>
                <select id="pessoa" name="pessoa">
                    <?php foreach ($pessoas as $p) : ?>
                        <option value="<?= e($p['id']) ?>"<?= selecionado(($entrada['pessoa'] ?? '') === $p['id']) ?>><?= e($p['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="tipo">Identificação</label>
                <select id="tipo" name="tipo">
                    <option value="digital"<?= selecionado(($entrada['tipo'] ?? '') !== 'facial') ?>>Digital</option>
                    <option value="facial"<?= selecionado(($entrada['tipo'] ?? '') === 'facial') ?>>Facial</option>
                </select>
            </div>
            <div>
                <label for="cenario">Cenário</label>
                <select id="cenario" name="cenario">
                    <?php foreach ($cenarios as $valor => $rotulo) : ?>
                        <option value="<?= e($valor) ?>"<?= selecionado(($entrada['cenario'] ?? 'confere') === $valor) ?>><?= e($rotulo) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="acoes"><button type="submit" class="botao botao-primario">Passar na catraca</button></div>
    </form>

    <?php if ($resultado !== null) : ?>
        <div class="alerta <?= $resultado['liberado'] ? 'alerta-sucesso' : 'alerta-erro' ?>" role="status" data-resultado>
            <strong><?= $resultado['liberado'] ? 'LIBERADO' : 'NEGADO' ?></strong>
            <?php if (!$resultado['liberado']) : ?> · <?= e($resultado['motivo']) ?> (<code><?= e($resultado['codigo']) ?></code>)<?php endif; ?>
            · <?= e($resultado['data_hora']) ?>
        </div>
    <?php endif; ?>
</section>

<section class="cartao">
    <h2>Últimos eventos</h2>
    <div class="tabela-rolagem">
        <table class="tabela tabela-compacta">
            <thead><tr><th>Data/hora</th><th>Catraca</th><th>Pessoa</th><th>Perfil</th><th>Tipo</th><th>Resultado</th><th>Motivo</th></tr></thead>
            <tbody>
            <?php foreach ($eventos as $ev) : ?>
                <tr>
                    <td><?= e(formatar_data_hora($ev['data_hora'], true)) ?><?= $ev['offline'] ? ' <span class="selo selo-neutro">offline</span>' : '' ?></td>
                    <td><?= e($ev['catraca_nome'] ?? '—') ?></td>
                    <td><?= e($ev['pessoa_nome'] ?? '—') ?></td>
                    <td><?= e($ev['perfil_nome'] ?? '—') ?></td>
                    <td><?= e($ev['tipo_identificacao']) ?></td>
                    <td><span class="selo <?= $ev['resultado'] === 'liberado' ? 'selo-ok' : 'selo-falha' ?>"><?= e($ev['resultado']) ?></span></td>
                    <td><?= e(ResultadoDecisao::rotuloMotivo($ev['motivo_negacao'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
