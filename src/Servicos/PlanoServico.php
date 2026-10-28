<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\AssinaturaRepositorio;
use PDO;

/**
 * Catálogo de planos (somente a plataforma). Planos não são apagados: saem
 * de venda, e quem já assina continua no plano até trocar.
 *
 * Mudança de preço vale para novas assinaturas e para a próxima troca de
 * plano; reajuste de quem já assina deve seguir o contrato (feito no gateway).
 */
final class PlanoServico
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly Auditoria $auditoria,
    ) {
    }

    /** @param array<string, string> $entrada */
    public function criar(array $entrada): string
    {
        $dados = $this->validar($entrada, null);

        return Transacao::executar($this->pdo, function () use ($dados): string {
            $id = $this->assinaturas->inserirPlano($dados);
            $this->auditoria->registrar('criar', 'plano', $id, null, $dados);

            return $id;
        });
    }

    /** @param array<string, string> $entrada */
    public function atualizar(string $id, array $entrada): void
    {
        $atual = $this->assinaturas->plano($id) ?? throw ErroValidacao::campo('id', 'Plano não encontrado.');
        $dados = $this->validar($entrada, $atual);

        Transacao::executar($this->pdo, function () use ($id, $atual, $dados): void {
            $this->assinaturas->atualizarPlano($id, $dados);
            $this->auditoria->registrar('alterar', 'plano', $id, $atual, $dados);
        });
    }

    /**
     * @param array<string, string>     $entrada
     * @param array<string, mixed>|null $atual
     * @return array<string, mixed>
     */
    private function validar(array $entrada, ?array $atual): array
    {
        $codigo = $atual === null ? mb_strtolower(trim($entrada['codigo'] ?? '')) : (string) $atual['codigo'];
        $nome = trim(preg_replace('/\s+/u', ' ', $entrada['nome'] ?? '') ?? '');
        $descricao = trim($entrada['descricao'] ?? '');
        $preco = self::centavos($entrada['preco'] ?? '');
        $inteiro = static fn (string $campo): ?int => filter_var(
            $entrada[$campo] ?? '',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1_000_000]],
        ) ?: null;
        $erros = [];

        if (preg_match('/^[a-z][a-z0-9_]{1,29}$/', $codigo) !== 1) {
            $erros['codigo'] = 'Código: letras minúsculas, números e "_" (2 a 30), começando por letra.';
        } elseif ($atual === null && $this->assinaturas->planoPorCodigo($codigo) !== null) {
            $erros['codigo'] = 'Já existe um plano com este código.';
        }

        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 60) {
            $erros['nome'] = 'Informe o nome (2 a 60 caracteres).';
        }

        if (mb_strlen($descricao) > 255) {
            $erros['descricao'] = 'Máximo de 255 caracteres.';
        }

        if ($preco === null) {
            $erros['preco'] = 'Informe o preço mensal (ex.: 199,90).';
        }

        foreach (['limite_pessoas' => 'pessoas', 'limite_catracas' => 'catracas'] as $campo => $rotulo) {
            if ($inteiro($campo) === null) {
                $erros[$campo] = "Informe o limite de {$rotulo} (número inteiro maior que zero).";
            }
        }

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        return [
            'codigo' => $codigo,
            'nome' => $nome,
            'descricao' => $descricao === '' ? null : $descricao,
            'preco_centavos' => $preco,
            'limite_pessoas' => $inteiro('limite_pessoas'),
            'limite_catracas' => $inteiro('limite_catracas'),
            'disponivel_venda' => ($entrada['disponivel_venda'] ?? '') === '1',
            'ordem' => (int) filter_var($entrada['ordem'] ?? '0', FILTER_VALIDATE_INT, [
                'options' => ['default' => 0, 'min_range' => 0, 'max_range' => 999],
            ]),
        ];
    }

    /** "1.290,50" ou "1290.5" -> 129050. Sem float, para não perder centavos. */
    public static function centavos(string $valor): ?int
    {
        $valor = str_replace(['R$', ' '], '', trim($valor));

        if (preg_match('/^\d{1,3}(\.\d{3})*(,\d{1,2})?$|^\d+([.,]\d{1,2})?$/', $valor) !== 1) {
            return null;
        }

        if (str_contains($valor, ',')) {
            [$reais, $cents] = array_pad(explode(',', str_replace('.', '', $valor)), 2, '0');
        } elseif (preg_match('/^\d+\.\d{1,2}$/', $valor) === 1) {
            [$reais, $cents] = explode('.', $valor);
        } else {
            // "1.290" = mil duzentos e noventa (ponto de milhar), sem centavos.
            [$reais, $cents] = [str_replace('.', '', $valor), '0'];
        }

        $total = (int) $reais * 100 + (int) str_pad($cents, 2, '0');

        return $total <= 100_000_000 ? $total : null;
    }
}
