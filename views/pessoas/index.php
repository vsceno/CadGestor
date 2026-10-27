<?php
/**
 * @var list<array<string, mixed>> $pessoas
 * @var string $busca
 * @var \CadGestor\Nucleo\Paginacao $paginacao
 */

use CadGestor\Nucleo\Visao;

?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <h1>Pessoas</h1>
        <a class="botao botao-primario" href="/pessoas/nova">Nova pessoa</a>
    </div>

    <form method="post" action="/pessoas/busca" class="filtros">
        <?= csrf_campo() ?>
        <div>
            <label for="busca">Nome ou CPF</label>
            <input type="search" id="busca" name="busca" value="<?= e($busca) ?>" maxlength="100" autocomplete="off">
        </div>
        <div class="acoes">
            <button type="submit" class="botao">Buscar</button>
            <?php if ($busca !== '') : ?><a class="botao" href="/pessoas">Limpar</a><?php endif; ?>
        </div>
    </form>
    <p class="dica">A busca é enviada de forma protegida: nome e CPF não aparecem no endereço da página.</p>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr><th>Nome</th><th>CPF</th><th class="numero">Vínculos ativos</th><th>Consentimento</th>
                    <th class="numero">Biometrias</th><th>Situação</th></tr>
            </thead>
            <tbody>
            <?php if ($pessoas === []) : ?>
                <tr><td colspan="6" class="vazio">Nenhuma pessoa encontrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($pessoas as $p) : ?>
                <tr>
                    <td><a href="/pessoas/<?= e($p['id']) ?>"><?= e($p['nome']) ?></a></td>
                    <td><?= e(mascarar_cpf($p['cpf'])) ?></td>
                    <td class="numero"><?= e($p['vinculos_ativos']) ?></td>
                    <td>
                        <span class="selo <?= $p['consentimento_ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                            <?= $p['consentimento_ativo'] ? 'Ativo' : 'Pendente' ?>
                        </span>
                    </td>
                    <td class="numero"><?= e($p['biometrias']) ?></td>
                    <td>
                        <span class="selo <?= $p['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                            <?= $p['ativo'] ? 'Ativa' : 'Inativa' ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($busca === '') : ?>
        <?= Visao::parcial('paginacao', ['paginacao' => $paginacao, 'filtros' => []]) ?>
    <?php endif; ?>
</section>
