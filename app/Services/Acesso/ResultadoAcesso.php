<?php

namespace App\Services\Acesso;

/**
 * Decisão de uma passagem: liberada (com a pessoa, o vínculo e o perfil que
 * liberaram) ou negada (com o motivo, sem dado pessoal além do id).
 */
final readonly class ResultadoAcesso
{
    /** Rótulos dos motivos, usados nas telas e nos alertas. */
    public const ROTULOS = [
        'catraca_inexistente' => 'catraca inexistente',
        'catraca_inativa' => 'catraca desativada',
        'pessoa_nao_identificada' => 'pessoa não identificada',
        'pessoa_inativa' => 'pessoa desativada',
        'sem_vinculo_ativo' => 'sem vínculo ativo',
        'perfil_nao_permitido' => 'perfil não permitido nesta catraca',
        'fora_do_horario' => 'fora do horário permitido',
        'biometria_nao_confere' => 'biometria não confere',
        'falha_sistema' => 'falha do sistema',
    ];

    private function __construct(
        public string $resultado,
        public ?string $motivo,
        public ?string $pessoaId,
        public ?string $vinculoId,
        public ?string $perfilId,
    ) {}

    public static function liberado(string $pessoaId, string $vinculoId, string $perfilId): self
    {
        return new self('liberado', null, $pessoaId, $vinculoId, $perfilId);
    }

    public static function negado(string $motivo, ?string $pessoaId = null): self
    {
        return new self('negado', $motivo, $pessoaId, null, null);
    }

    public function liberou(): bool
    {
        return $this->resultado === 'liberado';
    }
}
