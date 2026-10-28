<?php
/**
 * @var list<array<string, mixed>> $catracas
 * @var list<array<string, mixed>> $empresas
 * @var string|null $empresaId
 */
?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <h1>Catracas</h1>
        <a class="botao botao-primario" href="/catracas/nova<?= $empresaId ? '?empresa=' . e($empresaId) : '' ?>">Nova catraca</a>
    </div>

    <form method="get" action="/catracas" class="filtros">
        <div>
            <label for="empresa">Empresa</label>
            <select id="empresa" name="empresa" data-auto-enviar>
                <option value="">Todas</option>
                <?php foreach ($empresas as $empresa) : ?>
                    <option value="<?= e($empresa['id']) ?>"<?= selecionado($empresaId === $empresa['id']) ?>>
                        <?= e($empresa['razao_social']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr><th>Catraca</th><th>Empresa</th><th>Identificador</th><th>Identificação</th>
                    <th>Modo de falha</th><th>Último contato</th><th>Situação</th></tr>
            </thead>
            <tbody>
            <?php if ($catracas === []) : ?>
                <tr><td colspan="7" class="vazio">Nenhuma catraca cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($catracas as $c) : ?>
                <tr>
                    <td>
                        <a href="/catracas/<?= e($c['id']) ?>/editar"><?= e($c['nome']) ?></a>
                        <?php if ($c['localizacao']) : ?><br><small class="subtitulo"><?= e($c['localizacao']) ?></small><?php endif; ?>
                    </td>
                    <td><a href="/empresas/<?= e($c['empresa_id']) ?>"><?= e($c['razao_social']) ?></a></td>
                    <td><code><?= e($c['identificador_dispositivo']) ?></code></td>
                    <td><?= e(str_replace('_', ' + ', $c['tipo_identificacao'])) ?></td>
                    <td><?= $c['modo_falha'] === 'bloquear' ? 'Bloquear' : 'Liberar' ?></td>
                    <td><?= e(formatar_data_hora($c['ultimo_contato_em']) ?: 'nunca') ?></td>
                    <td>
                        <span class="selo <?= $c['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                            <?= $c['ativo'] ? 'Ativa' : 'Inativa' ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
