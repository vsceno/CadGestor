<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

/**
 * Cálculo de página atual, deslocamento (OFFSET) e total de páginas.
 */
final readonly class Paginacao
{
    public int $pagina;
    public int $totalPaginas;

    public function __construct(int $pagina, public int $total, public int $porPagina = 25)
    {
        $this->totalPaginas = max(1, (int) ceil($total / max(1, $porPagina)));
        $this->pagina = min(max(1, $pagina), $this->totalPaginas);
    }

    public static function daRequisicao(Requisicao $requisicao, int $total, int $porPagina = 25): self
    {
        return new self((int) $requisicao->consulta('pagina', '1'), $total, $porPagina);
    }

    public function deslocamento(): int
    {
        return ($this->pagina - 1) * $this->porPagina;
    }
}
