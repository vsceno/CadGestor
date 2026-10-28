<?php

declare(strict_types=1);

namespace CadGestor\Dominio;

use DateTimeImmutable;

/**
 * Cálculo de validade de um vínculo em uma data.
 *
 * Um vínculo vale como um crachá com prazo: só abre a porta se estiver ativo
 * (nem suspenso nem encerrado) E a data estiver entre o início e o fim,
 * ambos inclusivos. Sem data de fim, vale por tempo indeterminado.
 */
final class ValidadeVinculo
{
    public const VIGENTE = 'vigente';
    public const FUTURO = 'futuro';
    public const VENCIDO = 'vencido';
    public const SUSPENSO = 'suspenso';
    public const ENCERRADO = 'encerrado';

    public static function situacao(string $status, string $dataInicio, ?string $dataFim, DateTimeImmutable $em): string
    {
        if ($status === 'encerrado') {
            return self::ENCERRADO;
        }

        if ($status === 'suspenso') {
            return self::SUSPENSO;
        }

        $dia = $em->format('Y-m-d');

        if ($dia < $dataInicio) {
            return self::FUTURO;
        }

        if ($dataFim !== null && $dia > $dataFim) {
            return self::VENCIDO;
        }

        return self::VIGENTE;
    }

    public static function vigente(string $status, string $dataInicio, ?string $dataFim, DateTimeImmutable $em): bool
    {
        return self::situacao($status, $dataInicio, $dataFim, $em) === self::VIGENTE;
    }

    /** Dias que faltam até o fim (0 = vence hoje), ou null se não há data de fim. */
    public static function diasParaVencer(?string $dataFim, DateTimeImmutable $em): ?int
    {
        if ($dataFim === null) {
            return null;
        }

        $fim = new DateTimeImmutable($dataFim, $em->getTimezone());
        $hoje = new DateTimeImmutable($em->format('Y-m-d'), $em->getTimezone());

        return (int) $hoje->diff($fim)->format('%r%a');
    }

    public static function rotulo(string $situacao): string
    {
        return match ($situacao) {
            self::VIGENTE => 'Vigente',
            self::FUTURO => 'Ainda não iniciado',
            self::VENCIDO => 'Vencido',
            self::SUSPENSO => 'Suspenso',
            self::ENCERRADO => 'Encerrado',
            default => $situacao,
        };
    }
}
