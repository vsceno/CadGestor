<?php
/**
 * @var list<array<string, mixed>> $perfis
 * @var array<string, string> $basesLegais
 */
?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <h1>Perfis de vínculo</h1>
        <a class="botao botao-primario" href="/perfis/novo">Novo perfil</a>
    </div>

    <div class="alerta alerta-aviso">
        A base legal de cada perfil para o tratamento de biometria (dado sensível) deve ser
        <strong>validada pelo jurídico</strong> do cliente. Enquanto isso, mantenha "Consentimento".
    </div>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr><th>Perfil</th><th>Base legal</th><th class="numero">Empresas</th>
                    <th class="numero">Vínculos ativos</th><th>Situação</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($perfis as $perfil) : ?>
                <tr>
                    <td>
                        <strong><?= e($perfil['nome']) ?></strong>
                        <?php if ($perfil['descricao']) : ?>
                            <br><small class="subtitulo"><?= e($perfil['descricao']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($basesLegais[$perfil['base_legal']] ?? $perfil['base_legal']) ?><br>
                        <span class="selo <?= $perfil['base_legal_validada'] ? 'selo-ok' : 'selo-aviso' ?>">
                            <?= $perfil['base_legal_validada'] ? 'Validada pelo jurídico' : 'Aguardando jurídico' ?>
                        </span>
                    </td>
                    <td class="numero"><?= e($perfil['empresas']) ?></td>
                    <td class="numero"><?= e($perfil['vinculos']) ?></td>
                    <td>
                        <span class="selo <?= $perfil['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                            <?= $perfil['ativo'] ? 'Ativo' : 'Inativo' ?>
                        </span>
                    </td>
                    <td><a href="/perfis/<?= e($perfil['id']) ?>/editar">Editar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
