<?php
/**
 * @var list<array<string, mixed>> $empresas
 * @var string $busca
 * @var string $situacao
 * @var \CadGestor\Nucleo\Paginacao $paginacao
 */

use CadGestor\Nucleo\Visao;

?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <h1>Empresas</h1>
        <a class="botao botao-primario" href="/empresas/nova">Nova empresa</a>
    </div>

    <form method="get" action="/empresas" class="filtros">
        <div>
            <label for="busca">Razão social, nome fantasia ou CNPJ</label>
            <input type="search" id="busca" name="busca" value="<?= e($busca) ?>" maxlength="100">
        </div>
        <div>
            <label for="situacao">Situação</label>
            <select id="situacao" name="situacao" data-auto-enviar>
                <option value="ativas"<?= selecionado($situacao === 'ativas') ?>>Ativas</option>
                <option value="inativas"<?= selecionado($situacao === 'inativas') ?>>Inativas</option>
                <option value="todas"<?= selecionado($situacao === 'todas') ?>>Todas</option>
            </select>
        </div>
        <div><button type="submit" class="botao">Filtrar</button></div>
    </form>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr>
                    <th>Razão social</th><th>CNPJ</th><th>Município</th>
                    <th class="numero">Catracas</th><th class="numero">Vínculos ativos</th><th>Situação</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($empresas === []) : ?>
                <tr><td colspan="6" class="vazio">Nenhuma empresa encontrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($empresas as $empresa) : ?>
                <tr>
                    <td>
                        <a href="/empresas/<?= e($empresa['id']) ?>"><?= e($empresa['razao_social']) ?></a>
                        <?php if ($empresa['nome_fantasia']) : ?>
                            <br><small class="subtitulo"><?= e($empresa['nome_fantasia']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e(formatar_cnpj($empresa['cnpj'])) ?></td>
                    <td><?= e($empresa['municipio']) ?>/<?= e($empresa['uf']) ?></td>
                    <td class="numero"><?= e($empresa['catracas']) ?></td>
                    <td class="numero"><?= e($empresa['vinculos']) ?></td>
                    <td>
                        <span class="selo <?= $empresa['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                            <?= $empresa['ativo'] ? 'Ativa' : 'Inativa' ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= Visao::parcial('paginacao', [
        'paginacao' => $paginacao,
        'filtros' => ['busca' => $busca, 'situacao' => $situacao],
    ]) ?>
</section>
