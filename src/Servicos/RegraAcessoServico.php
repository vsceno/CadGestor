<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Dominio\IntervaloHorario;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\CatracaRepositorio;
use CadGestor\Repositorios\EmpresaRepositorio;
use CadGestor\Repositorios\RegraAcessoRepositorio;
use InvalidArgumentException;
use PDO;

/**
 * Matriz perfil x catraca x horário de cada empresa, e exceções por vínculo.
 */
final class RegraAcessoServico
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly RegraAcessoRepositorio $regras,
        private readonly EmpresaRepositorio $empresas,
        private readonly CatracaRepositorio $catracas,
        private readonly Auditoria $auditoria,
    ) {
    }

    /**
     * @param list<string> $dias
     * @param list<string> $inicios
     * @param list<string> $fins
     * @param list<string> $catracas
     */
    public function definirDoPerfil(
        string $empresaId,
        string $perfilId,
        array $dias,
        array $inicios,
        array $fins,
        array $catracas,
    ): void {
        if (!in_array($perfilId, $this->empresas->perfisAceitos($empresaId), true)) {
            throw ErroValidacao::campo('regras', 'Este perfil não é aceito pela empresa.');
        }

        $regras = $this->montar($empresaId, $dias, $inicios, $fins, $catracas);

        Transacao::executar($this->pdo, function () use ($empresaId, $perfilId, $regras): void {
            $this->regras->definirDoPerfil($empresaId, $perfilId, $regras);
            $this->auditoria->registrar('alterar', 'regra_acesso', $perfilId, null, [
                'empresa_id' => $empresaId,
                'regras' => self::descrever($regras),
            ]);
        });
    }

    /**
     * Regras próprias de um vínculo. Quando existem, substituem as do perfil.
     * Listas vazias removem a exceção (o vínculo volta a seguir o perfil).
     *
     * @param list<string> $dias
     * @param list<string> $inicios
     * @param list<string> $fins
     * @param list<string> $catracas
     */
    public function definirDoVinculo(
        string $empresaId,
        string $vinculoId,
        array $dias,
        array $inicios,
        array $fins,
        array $catracas,
    ): void {
        $regras = $this->montar($empresaId, $dias, $inicios, $fins, $catracas);

        Transacao::executar($this->pdo, function () use ($empresaId, $vinculoId, $regras): void {
            $this->regras->definirDoVinculo($empresaId, $vinculoId, $regras);
            $this->auditoria->registrar('alterar', 'regra_acesso', $vinculoId, null, [
                'vinculo_id' => $vinculoId,
                'regras' => self::descrever($regras),
            ]);
        });
    }

    /**
     * @param list<string> $dias
     * @param list<string> $inicios
     * @param list<string> $fins
     * @param list<string> $catracas
     * @return list<array{0: IntervaloHorario, 1: ?string}>
     */
    private function montar(string $empresaId, array $dias, array $inicios, array $fins, array $catracas): array
    {
        $catracasDaEmpresa = array_column($this->catracas->daEmpresa($empresaId), 'id');
        $regras = [];
        $porCatraca = [];

        foreach ($dias as $i => $dia) {
            $inicio = $inicios[$i] ?? '';
            $fim = $fins[$i] ?? '';

            if ($inicio === '' && $fim === '') {
                continue;
            }

            try {
                $intervalo = IntervaloHorario::deFormulario($dia, $inicio, $fim);
            } catch (InvalidArgumentException $erro) {
                throw ErroValidacao::campo('regras', 'Linha ' . ($i + 1) . ': ' . $erro->getMessage());
            }

            $catracaId = ($catracas[$i] ?? '') === '' ? null : $catracas[$i];

            if ($catracaId !== null && !in_array($catracaId, $catracasDaEmpresa, true)) {
                throw ErroValidacao::campo('regras', 'Linha ' . ($i + 1) . ': catraca não pertence à empresa.');
            }

            $regras[] = [$intervalo, $catracaId];
            $porCatraca[$catracaId ?? 'todas'][] = $intervalo;
        }

        foreach ($porCatraca as $intervalos) {
            $erro = IntervaloHorario::verificarSobreposicao($intervalos);

            if ($erro !== null) {
                throw ErroValidacao::campo('regras', $erro);
            }
        }

        return $regras;
    }

    /**
     * @param list<array{0: IntervaloHorario, 1: ?string}> $regras
     * @return list<string>
     */
    private static function descrever(array $regras): array
    {
        return array_map(
            static fn (array $r): string => "{$r[0]->diaSemana} {$r[0]->inicio}-{$r[0]->fim} " . ($r[1] ?? 'todas'),
            $regras,
        );
    }
}
