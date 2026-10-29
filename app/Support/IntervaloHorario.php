<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Intervalo de funcionamento de um dia da semana (1 = segunda ... 7 = domingo).
 *
 * Usado pelo horário padrão da empresa e pelas regras de acesso. Um horário
 * que termina às 00:00 (vindo do formulário) é tratado como o fim do dia.
 */
final readonly class IntervaloHorario
{
    public const DIAS = [1 => 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado', 'Domingo'];

    public function __construct(
        public int $diaSemana,
        public string $inicio,
        public string $fim,
    ) {
        if ($diaSemana < 1 || $diaSemana > 7) {
            throw new InvalidArgumentException('Dia da semana inválido.');
        }

        if (! self::horaValida($inicio) || ! self::horaValida($fim)) {
            throw new InvalidArgumentException('Horário inválido.');
        }

        if ($fim <= $inicio) {
            throw new InvalidArgumentException('O horário final deve ser maior que o inicial.');
        }
    }

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

    public function sobrepoe(self $outro): bool
    {
        return $this->diaSemana === $outro->diaSemana
            && $this->inicio < $outro->fim
            && $outro->inicio < $this->fim;
    }

    /**
     * @param  list<self>  $intervalos
     * @return string|null mensagem do primeiro conflito, ou null se não houver
     */
    public static function verificarSobreposicao(array $intervalos): ?string
    {
        foreach ($intervalos as $i => $a) {
            foreach (array_slice($intervalos, $i + 1) as $b) {
                if ($a->sobrepoe($b)) {
                    return 'Há horários sobrepostos na '.mb_strtolower(self::DIAS[$a->diaSemana]).'.';
                }
            }
        }

        return null;
    }

    public function rotuloDia(): string
    {
        return self::DIAS[$this->diaSemana];
    }

    /** Hora para o formulário (HH:MM). O fim às 24:00 volta a 00:00, como o formulário grava. */
    public static function paraFormulario(string $hora): string
    {
        $hora = substr($hora, 0, 5);

        return $hora === '24:00' ? '00:00' : $hora;
    }

    private static function horaValida(string $hora): bool
    {
        return preg_match('/^(([01]\d|2[0-3]):[0-5]\d:[0-5]\d|24:00:00)$/', $hora) === 1;
    }

    private static function normalizarHora(string $hora): string
    {
        $hora = trim($hora);

        return preg_match('/^\d{2}:\d{2}$/', $hora) === 1 ? $hora.':00' : $hora;
    }
}
