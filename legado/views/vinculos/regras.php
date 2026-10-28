<?php
/**
 * @var array<string, mixed> $vinculo
 * @var list<array<string, mixed>> $regras
 * @var list<array<string, mixed>> $catracas
 */

use CadGestor\Nucleo\Visao;

?>
<section class="cartao">
    <h1>Regras próprias do vínculo</h1>
    <p class="subtitulo">
        <a href="/pessoas/<?= e($vinculo['pessoa_id']) ?>#vinculos"><?= e($vinculo['pessoa_nome']) ?></a> ·
        <?= e($vinculo['razao_social']) ?> · <?= e($vinculo['perfil_nome']) ?>
    </p>
    <div class="alerta alerta-info">
        Exceção individual: quando há regras aqui, <strong>somente elas</strong> valem para este vínculo
        (as regras do perfil e o horário padrão da empresa deixam de ser usados).
        Remova todas as linhas e salve para voltar a seguir o perfil.
    </div>

    <form method="post" action="/vinculos/<?= e($vinculo['id']) ?>/regras" class="formulario">
        <?= csrf_campo() ?>
        <?= Visao::parcial('editor-horarios', [
            'idBloco' => 'regras-vinculo',
            'linhas' => $regras,
            'catracas' => $catracas,
        ]) ?>
        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar regras do vínculo</button>
            <a class="botao" href="/pessoas/<?= e($vinculo['pessoa_id']) ?>#vinculos">Voltar</a>
        </div>
    </form>
</section>
