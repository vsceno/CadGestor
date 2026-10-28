<?php

declare(strict_types=1);

namespace CadGestor\Acesso;

/**
 * Dados que a DecisaoAcesso consulta. Separar a consulta da regra permite
 * testar cada ramo da decisão sem banco de dados.
 */
interface FonteDadosAcesso
{
    /** @return array{id: string, empresa_id: string, ativo: bool, empresa_ativa: bool}|null */
    public function catraca(string $catracaId): ?array;

    /** @return array{id: string, ativo: bool, anonimizada: bool}|null */
    public function pessoa(string $pessoaId): ?array;

    /**
     * Vínculos não encerrados da pessoa com a empresa.
     *
     * @return list<array{id: string, perfil_id: string, status: string, data_inicio: string, data_fim: ?string}>
     */
    public function vinculos(string $pessoaId, string $empresaId): array;

    /** Perfil aceito pela empresa e ativo. */
    public function perfilPermitido(string $empresaId, string $perfilId): bool;

    /** @return list<array{dia_semana: int, hora_inicio: string, hora_fim: string, catraca_id: ?string}> */
    public function regrasDoVinculo(string $vinculoId): array;

    /** @return list<array{dia_semana: int, hora_inicio: string, hora_fim: string, catraca_id: ?string}> */
    public function regrasDoPerfil(string $empresaId, string $perfilId): array;

    /** @return list<array{dia_semana: int, hora_inicio: string, hora_fim: string}> */
    public function horarioPadrao(string $empresaId): array;

    /** Biometria ativa do tipo, com consentimento ativo. */
    public function biometriaAtiva(string $pessoaId, string $tipo): bool;
}
