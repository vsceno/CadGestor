<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Dominio\Cnpj;
use CadGestor\Dominio\IntervaloHorario;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\EmpresaRepositorio;
use CadGestor\Repositorios\PerfilRepositorio;
use InvalidArgumentException;
use PDO;

/**
 * Regras de cadastro de empresas, perfis aceitos e horários padrão.
 */
final class EmpresaServico
{
    public const UFS = [
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly EmpresaRepositorio $empresas,
        private readonly PerfilRepositorio $perfis,
        private readonly Auditoria $auditoria,
    ) {
    }

    /** @param array<string, string> $entrada */
    public function criar(array $entrada): string
    {
        $dados = $this->validar($entrada);

        return Transacao::executar($this->pdo, function () use ($dados): string {
            $id = $this->empresas->inserir($dados);
            $this->auditoria->registrar('criar', 'empresa', $id, null, $dados);

            return $id;
        });
    }

    /** @param array<string, string> $entrada */
    public function atualizar(string $id, array $entrada): void
    {
        $atual = $this->empresas->buscar($id) ?? throw ErroValidacao::campo('id', 'Empresa não encontrada.');
        $dados = $this->validar($entrada, $id);

        Transacao::executar($this->pdo, function () use ($id, $atual, $dados): void {
            $this->empresas->atualizar($id, $dados);
            $this->auditoria->registrar('alterar', 'empresa', $id, $atual, $dados);
        });
    }

    public function definirAtivo(string $id, bool $ativo): void
    {
        $atual = $this->empresas->buscar($id) ?? throw ErroValidacao::campo('id', 'Empresa não encontrada.');

        Transacao::executar($this->pdo, function () use ($id, $ativo, $atual): void {
            $this->empresas->definirAtivo($id, $ativo);
            $this->auditoria->registrar('alterar', 'empresa', $id, ['ativo' => $atual['ativo']], ['ativo' => $ativo]);
        });
    }

    /** @param list<string> $perfisIds */
    public function definirPerfis(string $empresaId, array $perfisIds): void
    {
        $validos = array_column($this->perfis->listar(apenasAtivos: true), 'id');
        $selecionados = array_values(array_intersect(array_unique($perfisIds), $validos));
        $antes = $this->empresas->perfisAceitos($empresaId);

        Transacao::executar($this->pdo, function () use ($empresaId, $selecionados, $antes): void {
            $this->empresas->definirPerfis($empresaId, $selecionados);
            $this->auditoria->registrar('alterar', 'empresa_perfil', $empresaId, ['perfis' => $antes], [
                'perfis' => $selecionados,
            ]);
        });
    }

    /**
     * @param list<string> $dias
     * @param list<string> $inicios
     * @param list<string> $fins
     */
    public function definirHorarios(string $empresaId, array $dias, array $inicios, array $fins): void
    {
        $intervalos = self::montarIntervalos($dias, $inicios, $fins);
        $antes = $this->empresas->horarios($empresaId);

        Transacao::executar($this->pdo, function () use ($empresaId, $intervalos, $antes): void {
            $this->empresas->definirHorarios($empresaId, $intervalos);
            $this->auditoria->registrar('alterar', 'empresa_horario_padrao', $empresaId, ['horarios' => $antes], [
                'horarios' => array_map(
                    static fn (IntervaloHorario $i): string => "{$i->diaSemana} {$i->inicio}-{$i->fim}",
                    $intervalos,
                ),
            ]);
        });
    }

    /**
     * Converte linhas de formulário (dia/início/fim) em intervalos válidos.
     * Linhas totalmente vazias são ignoradas.
     *
     * @param list<string> $dias
     * @param list<string> $inicios
     * @param list<string> $fins
     * @return list<IntervaloHorario>
     */
    public static function montarIntervalos(array $dias, array $inicios, array $fins, string $campo = 'horarios'): array
    {
        $intervalos = [];

        foreach ($dias as $i => $dia) {
            $inicio = $inicios[$i] ?? '';
            $fim = $fins[$i] ?? '';

            if ($inicio === '' && $fim === '') {
                continue;
            }

            try {
                $intervalos[] = IntervaloHorario::deFormulario($dia, $inicio, $fim);
            } catch (InvalidArgumentException $erro) {
                throw ErroValidacao::campo($campo, 'Linha ' . ($i + 1) . ': ' . $erro->getMessage());
            }
        }

        $sobreposicao = IntervaloHorario::verificarSobreposicao($intervalos);

        if ($sobreposicao !== null) {
            throw ErroValidacao::campo($campo, $sobreposicao);
        }

        return $intervalos;
    }

    /**
     * @param array<string, string> $entrada
     * @return array<string, string|null>
     */
    private function validar(array $entrada, ?string $id = null): array
    {
        $texto = static fn (string $campo): string => trim(preg_replace('/\s+/u', ' ', $entrada[$campo] ?? '') ?? '');
        $opcional = static fn (string $campo): ?string => $texto($campo) === '' ? null : $texto($campo);

        $dados = [
            'razao_social' => $texto('razao_social'),
            'nome_fantasia' => $opcional('nome_fantasia'),
            'cnpj' => Cnpj::normalizar($entrada['cnpj'] ?? ''),
            'cep' => preg_replace('/\D/', '', $entrada['cep'] ?? '') ?? '',
            'logradouro' => $texto('logradouro'),
            'numero' => $texto('numero'),
            'complemento' => $opcional('complemento'),
            'bairro' => $texto('bairro'),
            'municipio' => $texto('municipio'),
            'uf' => mb_strtoupper($texto('uf')),
            'responsavel_nome' => $texto('responsavel_nome'),
            'responsavel_cargo' => $opcional('responsavel_cargo'),
            'responsavel_email' => mb_strtolower($texto('responsavel_email')),
            'responsavel_telefone' => preg_replace('/\D/', '', $entrada['responsavel_telefone'] ?? '') ?? '',
        ];

        $erros = [];
        $obrigatorios = [
            'razao_social' => [3, 200], 'logradouro' => [2, 200], 'numero' => [1, 20], 'bairro' => [2, 100],
            'municipio' => [2, 100], 'responsavel_nome' => [3, 150],
        ];

        foreach ($obrigatorios as $campo => [$minimo, $maximo]) {
            $tamanho = mb_strlen((string) $dados[$campo]);

            if ($tamanho < $minimo || $tamanho > $maximo) {
                $erros[$campo] = "Preencha este campo ({$minimo} a {$maximo} caracteres).";
            }
        }

        foreach (['nome_fantasia' => 200, 'complemento' => 100, 'responsavel_cargo' => 100] as $campo => $maximo) {
            if ($dados[$campo] !== null && mb_strlen($dados[$campo]) > $maximo) {
                $erros[$campo] = "Máximo de {$maximo} caracteres.";
            }
        }

        if (!Cnpj::valido($dados['cnpj'])) {
            $erros['cnpj'] = 'CNPJ inválido. Confira os dígitos (aceita o formato alfanumérico).';
        } elseif ($this->empresas->cnpjEmUso($dados['cnpj'], $id)) {
            $erros['cnpj'] = 'Já existe uma empresa com este CNPJ.';
        }

        if (preg_match('/^\d{8}$/', (string) $dados['cep']) !== 1) {
            $erros['cep'] = 'CEP deve ter 8 dígitos.';
        }

        if (!in_array($dados['uf'], self::UFS, true)) {
            $erros['uf'] = 'Selecione a UF.';
        }

        if (filter_var($dados['responsavel_email'], FILTER_VALIDATE_EMAIL) === false) {
            $erros['responsavel_email'] = 'Informe um e-mail válido.';
        }

        if (preg_match('/^\d{10,11}$/', (string) $dados['responsavel_telefone']) !== 1) {
            $erros['responsavel_telefone'] = 'Telefone com DDD (10 ou 11 dígitos).';
        }

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        return $dados;
    }
}
