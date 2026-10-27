<?php
/**
 * @var list<array<string, mixed>> $termos
 * @var array<string, mixed>|null $vigente
 * @var array<string, string> $erros
 * @var array<string, string> $dados
 */
?>
<section class="cartao">
    <h1>Termos de consentimento</h1>
    <div class="alerta alerta-aviso">
        O texto do termo deve ser elaborado/validado pelo jurídico do cliente. Termos publicados não podem ser
        alterados (o titular aceitou aquele texto): para mudar, publique uma nova versão.
    </div>

    <table class="tabela">
        <thead><tr><th>Versão</th><th>Publicado em</th><th>Hash SHA-256</th><th class="numero">Aceites</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($termos as $t) : ?>
            <tr>
                <td><strong><?= e($t['versao']) ?></strong></td>
                <td><?= e(formatar_data_hora($t['vigente_desde'])) ?></td>
                <td><code><?= e(substr((string) $t['hash_texto'], 0, 16)) ?>…</code></td>
                <td class="numero"><?= e($t['aceites']) ?></td>
                <td><?= $vigente !== null && $vigente['id'] === $t['id'] ? '<span class="selo selo-ok">Vigente</span>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($vigente !== null) : ?>
        <details>
            <summary>Ver texto da versão vigente</summary>
            <div class="texto-termo"><?= e($vigente['texto']) ?></div>
        </details>
    <?php endif; ?>
</section>

<section class="cartao">
    <h2>Publicar nova versão</h2>
    <form method="post" action="/termos" class="formulario"
          data-confirmar="Publicar? O texto ficará imutável e passará a valer para novos aceites.">
        <?= csrf_campo() ?>
        <label for="versao">Versão</label>
        <input type="text" id="versao" name="versao" value="<?= e($dados['versao'] ?? '') ?>" required maxlength="20"
               placeholder="ex.: 1.1"<?= invalido($erros, 'versao') ?>>
        <?= erro_campo($erros, 'versao') ?>
        <label for="texto">Texto do termo</label>
        <textarea id="texto" name="texto" required<?= invalido($erros, 'texto') ?>><?= e($dados['texto'] ?? ($vigente['texto'] ?? '')) ?></textarea>
        <?= erro_campo($erros, 'texto') ?>
        <div class="acoes">
            <button type="submit" class="botao botao-primario">Publicar versão</button>
        </div>
    </form>
</section>
