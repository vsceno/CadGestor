<?php
/**
 * @var array<string, mixed> $dados
 * @var array<string, string> $erros
 * @var list<string> $ufs
 */

use CadGestor\Nucleo\Visao;
?>
<nav class="trilha" aria-label="Você está em"><a href="/assinatura">Minha assinatura</a><span>/</span>Dados da empresa</nav>

<section class="cartao cartao-largo">
    <h1>Dados da empresa</h1>
    <p class="subtitulo">Usados na cobrança e na nota fiscal do CadGestor.</p>

    <dl class="dados">
        <dt>Razão social</dt><dd><?= e($dados['nome'] ?? '') ?></dd>
        <dt>CNPJ</dt><dd><?= ($dados['cnpj'] ?? null) ? e(formatar_cnpj((string) $dados['cnpj'])) : '—' ?></dd>
    </dl>
    <p class="dica">Para mudar a razão social ou o CNPJ (troca de titularidade do contrato), fale com o comercial do CadGestor.</p>

    <?php if ($erros !== []) : ?>
        <div class="alerta alerta-erro" role="alert">Corrija os campos destacados.</div>
    <?php endif; ?>

    <form method="post" action="/assinatura/empresa" class="formulario">
        <?= csrf_campo() ?>
        <?= Visao::parcial('dados-empresa', ['dados' => $dados, 'erros' => $erros, 'ufs' => $ufs]) ?>
        <div class="acoes">
            <button type="submit" class="botao botao-primario">Salvar</button>
            <a href="/assinatura" class="botao">Cancelar</a>
        </div>
    </form>
</section>
