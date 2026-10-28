<?php

declare(strict_types=1);

namespace CadGestor\Dominio;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Política de retenção: quando os dados biométricos e pessoais de alguém
 * devem ser eliminados.
 *
 * Regra: a pessoa é elegível quando NÃO tem nenhum vínculo em vigor (todos
 * encerrados ou vencidos) e já se passaram mais de N dias desde a data de
 * referência:
 *   - a maior data de fim entre os vínculos; ou
 *   - a data de cadastro, se nunca teve vínculo (cadastro sem finalidade).
 *
 * PRAZO PROVISÓRIO (padrão 30 dias): deve ser validado pelo jurídico do cliente.
 */
final class PoliticaRetencao
{
    public function __construct(private readonly int $dias)
    {
        if ($dias < 1 || $dias > 3650) {
            throw new InvalidArgumentException('O prazo de retenção deve estar entre 1 e 3650 dias.');
        }
    }

    public function dias(): int
    {
        return $this->dias;
    }

    /**
     * @param list<array{status: string, data_inicio: string, data_fim: ?string}> $vinculos
     */
    public function dataReferencia(array $vinculos, string $cadastradoEm): ?string
    {
        if ($vinculos === []) {
            return substr($cadastradoEm, 0, 10);
        }

        $fins = [];

        foreach ($vinculos as $vinculo) {
            if ($vinculo['data_fim'] === null) {
                return null; // vínculo sem prazo: segue em vigor (ou depende de encerramento manual)
            }

            $fins[] = $vinculo['data_fim'];
        }

        return max($fins);
    }

    /**
     * @param list<array{status: string, data_inicio: string, data_fim: ?string}> $vinculos
     */
    public function elegivel(array $vinculos, string $cadastradoEm, DateTimeImmutable $hoje): bool
    {
        foreach ($vinculos as $vinculo) {
            if (
                $vinculo['status'] !== 'encerrado'
                && ValidadeVinculo::situacao($vinculo['status'], $vinculo['data_inicio'], $vinculo['data_fim'], $hoje)
                    !== ValidadeVinculo::VENCIDO
            ) {
                return false; // ainda há vínculo vigente, futuro ou suspenso dentro do prazo
            }
        }

        $referencia = $this->dataReferencia($vinculos, $cadastradoEm);

        if ($referencia === null) {
            return false;
        }

        return $hoje->format('Y-m-d') > $this->dataLimite($referencia);
    }

    /** Último dia em que os dados ainda podem ser mantidos (referência + N dias). */
    public function dataLimite(string $dataReferencia): string
    {
        return (new DateTimeImmutable($dataReferencia))->modify("+{$this->dias} days")->format('Y-m-d');
    }
}
