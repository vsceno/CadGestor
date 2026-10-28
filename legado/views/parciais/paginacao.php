<?php
/**
 * Navegação entre páginas, preservando os filtros da query string.
 *
 * @var \CadGestor\Nucleo\Paginacao $paginacao
 * @var array<string, string>       $filtros
 */
if ($paginacao->totalPaginas <= 1) {
    return;
}

$link = static fn (int $pagina): string => '?' . http_build_query($filtros + ['pagina' => $pagina]);
?>
<nav class="paginacao" aria-label="Paginação">
    <span><?= e($paginacao->total) ?> registro(s) · página <?= e($paginacao->pagina) ?>
        de <?= e($paginacao->totalPaginas) ?></span>
    <?php if ($paginacao->pagina > 1) : ?>
        <a class="botao botao-pequeno" href="<?= e($link($paginacao->pagina - 1)) ?>">Anterior</a>
    <?php endif; ?>
    <?php if ($paginacao->pagina < $paginacao->totalPaginas) : ?>
        <a class="botao botao-pequeno" href="<?= e($link($paginacao->pagina + 1)) ?>">Próxima</a>
    <?php endif; ?>
</nav>
