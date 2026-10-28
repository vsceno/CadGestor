<?php

declare(strict_types=1);

namespace CadGestor\Acesso;

use CadGestor\Dominio\IntervaloHorario;
use CadGestor\Dominio\ValidadeVinculo;

/**
 * Motor único de decisão de acesso na catraca.
 *
 * Ordem de verificação (a primeira falha nega e define o motivo):
 *   1. Catraca existe e está ativa (e a empresa dela também).
 *   2. Pessoa identificada, existente e ativa.
 *   3. Vínculo vigente com a empresa da catraca (status e validade).
 *   4. Perfil do vínculo aceito pela empresa e ativo.
 *   5. Regra de acesso para o dia, a hora e a catraca
 *      (hierarquia: regras do vínculo > regras do perfil > horário padrão).
 *   6. Biometria confere: confiança >= limiar e biometria ativa (com consentimento).
 *
 * Com vários vínculos vigentes na mesma empresa (ex.: Funcionário e Aluno),
 * basta um deles passar nas etapas 4 e 5.
 */
final class DecisaoAcesso
{
    public function __construct(
        private readonly FonteDadosAcesso $fonte,
        private readonly float $limiarConfianca,
    ) {
        if ($limiarConfianca <= 0 || $limiarConfianca > 1) {
            throw new \InvalidArgumentException('O limiar de confiança deve estar entre 0 (exclusivo) e 1.');
        }
    }

    public function decidir(SolicitacaoAcesso $s): ResultadoDecisao
    {
        // 1. Catraca
        $catraca = $this->fonte->catraca($s->catracaId);

        if ($catraca === null) {
            return ResultadoDecisao::negado(ResultadoDecisao::CATRACA_INEXISTENTE);
        }

        $empresaId = $catraca['empresa_id'];

        if (!$catraca['ativo'] || !$catraca['empresa_ativa']) {
            return ResultadoDecisao::negado(ResultadoDecisao::CATRACA_INATIVA, $s->catracaId, $empresaId);
        }

        // 2. Pessoa
        if ($s->pessoaId === null) {
            return ResultadoDecisao::negado(ResultadoDecisao::PESSOA_NAO_IDENTIFICADA, $s->catracaId, $empresaId);
        }

        $pessoa = $this->fonte->pessoa($s->pessoaId);

        if ($pessoa === null) {
            return ResultadoDecisao::negado(ResultadoDecisao::PESSOA_NAO_IDENTIFICADA, $s->catracaId, $empresaId);
        }

        if (!$pessoa['ativo'] || $pessoa['anonimizada']) {
            return ResultadoDecisao::negado(
                ResultadoDecisao::PESSOA_INATIVA,
                $s->catracaId,
                $empresaId,
                $s->pessoaId,
            );
        }

        // 3. Vínculo vigente com a empresa da catraca
        $emVigor = static fn (array $v): bool =>
            ValidadeVinculo::vigente($v['status'], $v['data_inicio'], $v['data_fim'], $s->dataHora);
        $vigentes = array_values(array_filter($this->fonte->vinculos($s->pessoaId, $empresaId), $emVigor));

        if ($vigentes === []) {
            return ResultadoDecisao::negado(
                ResultadoDecisao::SEM_VINCULO_ATIVO,
                $s->catracaId,
                $empresaId,
                $s->pessoaId,
            );
        }

        // 4 e 5. Perfil permitido e regra de horário (basta um vínculo passar)
        $motivo = ResultadoDecisao::PERFIL_NAO_PERMITIDO;
        $referencia = $vigentes[0];
        $aprovado = null;

        foreach ($vigentes as $vinculo) {
            if (!$this->fonte->perfilPermitido($empresaId, $vinculo['perfil_id'])) {
                continue;
            }

            $motivo = ResultadoDecisao::FORA_DO_HORARIO;
            $referencia = $vinculo;

            if ($this->dentroDoHorario($vinculo, $empresaId, $s)) {
                $aprovado = $vinculo;
                break;
            }
        }

        if ($aprovado === null) {
            return ResultadoDecisao::negado(
                $motivo,
                $s->catracaId,
                $empresaId,
                $s->pessoaId,
                $referencia['id'],
                $referencia['perfil_id'],
            );
        }

        // 6. Biometria confere
        if ($s->score < $this->limiarConfianca || !$this->fonte->biometriaAtiva($s->pessoaId, $s->tipo->value)) {
            return ResultadoDecisao::negado(
                ResultadoDecisao::BIOMETRIA_NAO_CONFERE,
                $s->catracaId,
                $empresaId,
                $s->pessoaId,
                $aprovado['id'],
                $aprovado['perfil_id'],
            );
        }

        return new ResultadoDecisao(
            true,
            null,
            $s->catracaId,
            $empresaId,
            $s->pessoaId,
            $aprovado['id'],
            $aprovado['perfil_id'],
        );
    }

    /**
     * Aplica a hierarquia de regras: se o vínculo tem regras próprias, só elas
     * valem; senão, as do perfil na empresa; senão, o horário padrão da empresa.
     *
     * @param array{id: string, perfil_id: string} $vinculo
     */
    private function dentroDoHorario(array $vinculo, string $empresaId, SolicitacaoAcesso $s): bool
    {
        $regras = $this->fonte->regrasDoVinculo($vinculo['id']);

        if ($regras === []) {
            $regras = $this->fonte->regrasDoPerfil($empresaId, $vinculo['perfil_id']);
        }

        if ($regras === []) {
            $regras = $this->fonte->horarioPadrao($empresaId);
        }

        $dia = (int) $s->dataHora->format('N');
        $hora = $s->dataHora->format('H:i:s');

        foreach ($regras as $regra) {
            $catracaDaRegra = $regra['catraca_id'] ?? null;

            if ($catracaDaRegra !== null && $catracaDaRegra !== $s->catracaId) {
                continue;
            }

            $intervalo = new IntervaloHorario((int) $regra['dia_semana'], $regra['hora_inicio'], $regra['hora_fim']);

            if ($intervalo->contem($dia, $hora)) {
                return true;
            }
        }

        return false;
    }
}
