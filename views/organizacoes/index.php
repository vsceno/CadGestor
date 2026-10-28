<?php
/** @var list<array<string, mixed>> $organizacoes */
?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <h1>Organizações</h1>
        <a class="botao botao-primario" href="/organizacoes/nova">Nova organização</a>
    </div>

    <p class="dica">
        Cada organização é um cliente, com empresas, catracas, pessoas e usuários próprios.
        Uma organização nunca enxerga os dados de outra. Daqui você vê só as contagens; para
        atuar dentro de uma organização, use <strong>Entrar (suporte)</strong>, o que fica
        registrado na auditoria dela.
    </p>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr><th>Organização</th><th class="numero">Usuários</th><th class="numero">Empresas</th>
                    <th class="numero">Pessoas</th><th class="numero">Catracas</th><th>Situação</th><th></th></tr>
            </thead>
            <tbody>
            <?php if ($organizacoes === []) : ?>
                <tr><td colspan="7">Nenhuma organização cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($organizacoes as $o) : ?>
                <tr>
                    <td><a href="/organizacoes/<?= e($o['id']) ?>"><strong><?= e($o['nome']) ?></strong></a></td>
                    <td class="numero"><?= e($o['usuarios']) ?></td>
                    <td class="numero"><?= e($o['empresas']) ?></td>
                    <td class="numero"><?= e($o['pessoas']) ?></td>
                    <td class="numero"><?= e($o['catracas']) ?></td>
                    <td>
                        <span class="selo <?= $o['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                            <?= $o['ativo'] ? 'Ativa' : 'Inativa' ?>
                        </span>
                    </td>
                    <td>
                        <form method="post" action="/organizacoes/<?= e($o['id']) ?>/entrar" class="form-inline">
                            <?= csrf_campo() ?>
                            <button type="submit" class="botao-link">Entrar (suporte)</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
