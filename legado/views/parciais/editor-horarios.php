<?php
/**
 * Editor de faixas de horário com linhas dinâmicas (dia, início, fim e,
 * opcionalmente, catraca). Envia dia[], inicio[], fim[] e catraca[].
 *
 * @var string $idBloco
 * @var list<array<string, mixed>> $linhas  com dia_semana, hora_inicio, hora_fim e catraca_id
 * @var list<array<string, mixed>>|null $catracas  null = sem coluna de catraca
 */

$comCatraca = $catracas !== null;
$paraInput = static fn (?string $hora): string => $hora === null ? '' : ($hora === '24:00:00' ? '00:00' : substr($hora, 0, 5));

$linhaHtml = static function (string $indice, array $linha) use ($comCatraca, $catracas, $paraInput): string {
    ob_start();
    ?>
    <div class="linha-horario<?= $comCatraca ? '' : ' sem-catraca' ?>">
        <select name="dia[]" aria-label="Dia da semana">
            <?php for ($d = 1; $d <= 7; $d++) : ?>
                <option value="<?= $d ?>"<?= selecionado((int) ($linha['dia_semana'] ?? 1) === $d) ?>>
                    <?= e(nome_dia_semana($d)) ?>
                </option>
            <?php endfor; ?>
        </select>
        <input type="time" name="inicio[]" value="<?= e($paraInput($linha['hora_inicio'] ?? null)) ?>"
               aria-label="Hora inicial">
        <input type="time" name="fim[]" value="<?= e($paraInput($linha['hora_fim'] ?? null)) ?>"
               aria-label="Hora final">
        <?php if ($comCatraca) : ?>
            <select name="catraca[]" aria-label="Catraca">
                <option value="">Todas as catracas</option>
                <?php foreach ($catracas as $catraca) : ?>
                    <option value="<?= e($catraca['id']) ?>"<?= selecionado(($linha['catraca_id'] ?? null) === $catraca['id']) ?>>
                        <?= e($catraca['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="button" class="botao botao-pequeno" data-remover-linha aria-label="Remover linha">✕</button>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<div id="<?= e($idBloco) ?>">
    <?php foreach ($linhas as $i => $linha) : ?>
        <?= $linhaHtml((string) $i, $linha) ?>
    <?php endforeach; ?>
    <template data-modelo><?= $linhaHtml('__i__', ['dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fim' => '18:00:00']) ?></template>
</div>
<button type="button" class="botao botao-pequeno" data-adicionar="<?= e($idBloco) ?>">+ Adicionar faixa</button>
<p class="dica">O horário final é exclusivo (até 12:00 = libera até 11:59). Final 00:00 significa meia-noite (fim do dia).
    Turnos que viram a noite usam duas linhas.</p>
