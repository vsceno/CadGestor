<?php

declare(strict_types=1);

namespace CadGestor\Acesso;

use CadGestor\Biometria\TipoBiometria;
use DateTimeImmutable;

/**
 * Pedido de passagem vindo da catraca, já com a identificação resolvida.
 */
final readonly class SolicitacaoAcesso
{
    /**
     * @param string|null       $pessoaId  pessoa reconhecida pela biometria (null = ninguém reconhecido)
     * @param float             $score     confiança do reconhecimento (0 a 1)
     * @param DateTimeImmutable $dataHora  instante da passagem, no fuso da aplicação
     */
    public function __construct(
        public string $catracaId,
        public ?string $pessoaId,
        public TipoBiometria $tipo,
        public float $score,
        public DateTimeImmutable $dataHora,
    ) {
    }
}
