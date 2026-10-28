<?php

declare(strict_types=1);

namespace CadGestor\Acesso;

/**
 * Resultado da decisão: liberado ou negado (com motivo), mais os dados que
 * vão para o registro do evento.
 */
final readonly class ResultadoDecisao
{
    public const CATRACA_INEXISTENTE = 'catraca_inexistente';
    public const CATRACA_INATIVA = 'catraca_inativa';
    public const PESSOA_NAO_IDENTIFICADA = 'pessoa_nao_identificada';
    public const PESSOA_INATIVA = 'pessoa_inativa';
    public const SEM_VINCULO_ATIVO = 'sem_vinculo_ativo';
    public const PERFIL_NAO_PERMITIDO = 'perfil_nao_permitido';
    public const FORA_DO_HORARIO = 'fora_do_horario';
    public const BIOMETRIA_NAO_CONFERE = 'biometria_nao_confere';
    public const FALHA_SISTEMA = 'falha_sistema';

    public function __construct(
        public bool $liberado,
        public ?string $motivo,
        public ?string $catracaId = null,
        public ?string $empresaId = null,
        public ?string $pessoaId = null,
        public ?string $vinculoId = null,
        public ?string $perfilId = null,
    ) {
    }

    public static function negado(
        string $motivo,
        ?string $catracaId = null,
        ?string $empresaId = null,
        ?string $pessoaId = null,
        ?string $vinculoId = null,
        ?string $perfilId = null,
    ): self {
        return new self(false, $motivo, $catracaId, $empresaId, $pessoaId, $vinculoId, $perfilId);
    }

    public static function rotuloMotivo(?string $motivo): string
    {
        return match ($motivo) {
            null => '',
            self::CATRACA_INEXISTENTE => 'Catraca não cadastrada',
            self::CATRACA_INATIVA => 'Catraca ou empresa inativa',
            self::PESSOA_NAO_IDENTIFICADA => 'Pessoa não identificada',
            self::PESSOA_INATIVA => 'Pessoa inativa',
            self::SEM_VINCULO_ATIVO => 'Sem vínculo vigente com a empresa',
            self::PERFIL_NAO_PERMITIDO => 'Perfil não permitido pela empresa',
            self::FORA_DO_HORARIO => 'Fora do horário permitido',
            self::BIOMETRIA_NAO_CONFERE => 'Biometria não confere',
            self::FALHA_SISTEMA => 'Falha do sistema',
            default => $motivo,
        };
    }
}
