<?php
/**
 * @var \CadGestor\Seguranca\UsuarioAutenticado $usuarioPainel
 * @var array<string, int> $indicadores
 */

$cartoes = [
    ['Empresas ativas', $indicadores['empresas'] ?? 0],
    ['Pessoas ativas', $indicadores['pessoas'] ?? 0],
    ['Vínculos ativos', $indicadores['vinculos'] ?? 0],
    ['Catracas ativas', $indicadores['catracas'] ?? 0],
    ['Eventos hoje', $indicadores['eventos_hoje'] ?? 0],
    ['Alertas pendentes', $indicadores['alertas_pendentes'] ?? 0],
];
?>
<section>
    <h1>Olá, <?= e($usuarioPainel->primeiroNome()) ?></h1>
    <p class="subtitulo">
        Seus papéis:
        <?= e(implode(', ', array_map(static fn ($p): string => $p->rotulo(), $usuarioPainel->papeis))) ?>
    </p>

    <div class="indicadores">
        <?php foreach ($cartoes as [$rotulo, $valor]) : ?>
            <div class="indicador">
                <span class="indicador-valor"><?= e(number_format($valor, 0, ',', '.')) ?></span>
                <span class="indicador-rotulo"><?= e($rotulo) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>
