<?php

declare(strict_types=1);

namespace CadGestor\Biometria;

/**
 * Implementação SIMULADA para desenvolvimento e testes. Não faz biometria de
 * verdade e não deve ser usada em produção.
 *
 * - O "template" é um hash da amostra: amostras idênticas geram o mesmo
 *   template; qualquer diferença gera outro.
 * - A identificação compara o template da amostra com os candidatos: igual
 *   dá confiança 1.0; diferente dá a fração de bytes coincidentes (~0).
 * - Os "dispositivos" ficam em memória, só durante a requisição.
 */
final class IdentificadorSimulado implements IdentificadorBiometrico
{
    private const PREFIXO = 'SIMTPL1:';

    /** @var array<string, array<string, array<string, string>>> dispositivo => referência => tipo => template */
    private array $dispositivos = [];

    public function nome(): string
    {
        return 'simulado';
    }

    public function extrairTemplate(TipoBiometria $tipo, string $amostra): string
    {
        if ($amostra === '') {
            throw new \InvalidArgumentException('Amostra biométrica vazia.');
        }

        return self::PREFIXO . hash('sha256', $tipo->value . "\0" . $amostra, true);
    }

    public function cadastrar(string $dispositivo, string $referencia, TipoBiometria $tipo, string $template): void
    {
        $this->dispositivos[$dispositivo][$referencia][$tipo->value] = $template;
    }

    public function remover(string $dispositivo, string $referencia): void
    {
        unset($this->dispositivos[$dispositivo][$referencia]);
    }

    /** @return list<string> referências cadastradas no dispositivo simulado */
    public function cadastradosEm(string $dispositivo): array
    {
        return array_keys($this->dispositivos[$dispositivo] ?? []);
    }

    public function identificar(TipoBiometria $tipo, string $amostra, array $candidatos): ResultadoIdentificacao
    {
        $template = $this->extrairTemplate($tipo, $amostra);
        $melhor = ResultadoIdentificacao::naoIdentificado();

        foreach ($candidatos as $referencia => $candidato) {
            $score = self::similaridade($template, $candidato);

            if ($score > $melhor->score) {
                $melhor = new ResultadoIdentificacao((string) $referencia, $score);
            }
        }

        return $melhor;
    }

    private static function similaridade(string $a, string $b): float
    {
        if (hash_equals($a, $b)) {
            return 1.0;
        }

        $tamanho = min(strlen($a), strlen($b));

        if ($tamanho === 0) {
            return 0.0;
        }

        $iguais = 0;

        for ($i = 0; $i < $tamanho; $i++) {
            $iguais += $a[$i] === $b[$i] ? 1 : 0;
        }

        return round($iguais / max(strlen($a), strlen($b)), 4);
    }
}
