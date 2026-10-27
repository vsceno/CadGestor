<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Acesso;

use CadGestor\Acesso\FonteDadosAcesso;

/**
 * Fonte de dados em memória para os testes.
 */
final class FonteEmMemoria implements FonteDadosAcesso
{
    /** @var array<string, array{id: string, empresa_id: string, ativo: bool, empresa_ativa: bool}> */
    public array $catracas = ['C1' => ['id' => 'C1', 'empresa_id' => 'E1', 'ativo' => true, 'empresa_ativa' => true]];

    /** @var array<string, array{id: string, ativo: bool, anonimizada: bool}> */
    public array $pessoas = ['P1' => ['id' => 'P1', 'ativo' => true, 'anonimizada' => false]];

    /** @var list<array<string, mixed>> */
    public array $vinculos = [[
        'id' => 'V1', 'empresa_id' => 'E1', 'perfil_id' => 'F', 'status' => 'ativo',
        'data_inicio' => '2026-01-01', 'data_fim' => null,
    ]];

    /** @var list<string> */
    public array $perfisPermitidos = ['F'];

    /** @var list<array<string, mixed>> */
    public array $regrasVinculo = [];

    /** @var list<array<string, mixed>> regras do perfil F */
    public array $regrasPerfil;

    /** @var array<string, list<array<string, mixed>>> regras de outros perfis */
    public array $regrasPorPerfil = [];

    /** @var list<array<string, mixed>> */
    public array $horarioPadrao = [];

    /** @var list<string> */
    public array $biometrias = ['P1:digital'];

    public function __construct()
    {
        $this->regrasPerfil = array_map(
            static fn (int $dia): array => ['dia_semana' => $dia, 'hora_inicio' => '07:00:00', 'hora_fim' => '19:00:00', 'catraca_id' => null],
            [1, 2, 3, 4, 5],
        );
    }

    public function catraca(string $catracaId): ?array
    {
        return $this->catracas[$catracaId] ?? null;
    }

    public function pessoa(string $pessoaId): ?array
    {
        return $this->pessoas[$pessoaId] ?? null;
    }

    public function vinculos(string $pessoaId, string $empresaId): array
    {
        return array_values(array_filter(
            $this->vinculos,
            static fn (array $v): bool => $v['empresa_id'] === $empresaId && $v['status'] !== 'encerrado',
        ));
    }

    public function perfilPermitido(string $empresaId, string $perfilId): bool
    {
        return in_array($perfilId, $this->perfisPermitidos, true);
    }

    public function regrasDoVinculo(string $vinculoId): array
    {
        return $vinculoId === 'V1' ? $this->regrasVinculo : [];
    }

    public function regrasDoPerfil(string $empresaId, string $perfilId): array
    {
        return $perfilId === 'F' ? $this->regrasPerfil : ($this->regrasPorPerfil[$perfilId] ?? []);
    }

    public function horarioPadrao(string $empresaId): array
    {
        return $this->horarioPadrao;
    }

    public function biometriaAtiva(string $pessoaId, string $tipo): bool
    {
        return in_array("{$pessoaId}:{$tipo}", $this->biometrias, true);
    }
}
