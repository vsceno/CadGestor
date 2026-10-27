<?php

declare(strict_types=1);

namespace CadGestor\Dominio;

use InvalidArgumentException;

/**
 * Faixa de horário em um dia da semana (ISO: 1 = segunda ... 7 = domingo).
 *
 * O fim é exclusivo: 08:00-12:00 libera às 11:59:59 e bloqueia às 12:00.
 * "24:00" representa o fim do dia; turnos que viram a meia-noite são
 * cadastrados como dois intervalos (22:00-24:00 e 00:00-06:00).
 */
final readonly class IntervaloHorario
{
    public function __construct(
        public int $diaSemana,
        public string $inicio,
        public string $fim,
    ) {
        if ($diaSemana < 1 || $diaSemana > 7) {
            throw new InvalidArgumentException('Dia da semana inválido.');
        }

        if (!self::horaValida($inicio) || !self::horaValida($fim)) {
            throw new InvalidArgumentException('Horário inválido.');
        }

        if ($fim <= $inicio) {
            throw new InvalidArgumentException('O horário final deve ser maior que o inicial.');
        }
    }

    /**
     * Cria a partir de dados de formulário ("HH:MM"). Um fim "00:00" com início
     * maior que zero é entendido como meia-noite do fim do dia (24:00).
     */
    public static function deFormulario(string $dia, string $inicio, string $fim): self
    {
        $inicio = self::normalizarHora($inicio);
        $fim = self::normalizarHora($fim);

        if ($fim === '00:00:00' && $inicio !== '00:00:00') {
            $fim = '24:00:00';
        }

        if (preg_match('/^[1-7]$/', $dia) !== 1) {
            throw new InvalidArgumentException('Dia da semana inválido.');
        }

        return new self((int) $dia, $inicio, $fim);
    }

    /** Verdadeiro se o horário (HH:MM:SS) está dentro do intervalo, com fim exclusivo. */
    public function contem(int $diaSemana, string $hora): bool
    {
        return $diaSemana === $this->diaSemana && $hora >= $this->inicio && $hora < $this->fim;
    }

    public function sobrepoe(self $outro): bool
    {
        return $this->diaSemana === $outro->diaSemana
            && $this->inicio < $outro->fim
            && $outro->inicio < $this->fim;
    }

    /**
     * @param list<self> $intervalos
     * @return string|null mensagem de erro se houver sobreposição
     */
    public static function verificarSobreposicao(array $intervalos): ?string
    {
        foreach ($intervalos as $i => $a) {
            foreach (array_slice($intervalos, $i + 1) as $b) {
                if ($a->sobrepoe($b)) {
                    return 'Há horários sobrepostos na ' . mb_strtolower(nome_dia_semana($a->diaSemana)) . '.';
                }
            }
        }

        return null;
    }

    private static function horaValida(string $hora): bool
    {
        return preg_match('/^(([01]\d|2[0-3]):[0-5]\d:[0-5]\d|24:00:00)$/', $hora) === 1;
    }

    private static function normalizarHora(string $hora): string
    {
        $hora = trim($hora);

        if (preg_match('/^\d{2}:\d{2}$/', $hora) === 1) {
            return $hora . ':00';
        }

        return $hora;
    }
}
