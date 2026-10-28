<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\CatracaRepositorio;
use CadGestor\Repositorios\EmpresaRepositorio;
use PDO;

/**
 * Cadastro de catracas e emissão do token de autenticação do dispositivo.
 *
 * O token funciona como a chave de um cofre: é entregue uma única vez a quem
 * instala a catraca. O sistema guarda só a "impressão digital" dele (SHA-256),
 * então nem quem tem acesso ao banco consegue se passar pela catraca.
 */
final class CatracaServico
{
    public const PREFIXO_TOKEN = 'cgt_';

    public const TIPOS_IDENTIFICACAO = [
        'digital' => 'Biometria digital',
        'facial' => 'Reconhecimento facial',
        'digital_facial' => 'Digital e facial',
    ];

    public const MODOS_FALHA = [
        'bloquear' => 'Bloquear (fail-secure): sem servidor, ninguém passa',
        'liberar' => 'Liberar (fail-safe): sem servidor, a catraca libera',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly CatracaRepositorio $catracas,
        private readonly EmpresaRepositorio $empresas,
        private readonly Auditoria $auditoria,
        private readonly LimitesPlano $limites,
    ) {
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param array<string, string> $entrada
     * @return array{id: string, token: string}
     */
    public function criar(array $entrada): array
    {
        $this->limites->garantirCapacidade('catracas');
        $dados = $this->validar($entrada);
        $token = self::gerarToken();

        $id = Transacao::executar($this->pdo, function () use ($dados, $token): string {
            $id = $this->catracas->inserir($dados, self::hashToken($token));
            $this->auditoria->registrar('criar', 'catraca', $id, null, $dados);

            return $id;
        });

        return ['id' => $id, 'token' => $token];
    }

    /** @param array<string, string> $entrada */
    public function atualizar(string $id, array $entrada): void
    {
        $atual = $this->catracas->buscar($id) ?? throw ErroValidacao::campo('id', 'Catraca não encontrada.');
        $entrada['empresa_id'] = (string) $atual['empresa_id'];
        $dados = $this->validar($entrada, $id);

        Transacao::executar($this->pdo, function () use ($id, $atual, $dados): void {
            $this->catracas->atualizar($id, $dados);
            $this->auditoria->registrar('alterar', 'catraca', $id, $atual, $dados);
        });
    }

    /** Gera um novo token; o anterior deixa de funcionar imediatamente. */
    public function renovarToken(string $id): string
    {
        if ($this->catracas->buscar($id) === null) {
            throw ErroValidacao::campo('id', 'Catraca não encontrada.');
        }

        $token = self::gerarToken();

        Transacao::executar($this->pdo, function () use ($id, $token): void {
            $this->catracas->definirToken($id, self::hashToken($token));
            $this->auditoria->registrar('alterar', 'catraca', $id, null, ['token_renovado' => true]);
        });

        return $token;
    }

    public function definirAtivo(string $id, bool $ativo): void
    {
        $atual = $this->catracas->buscar($id) ?? throw ErroValidacao::campo('id', 'Catraca não encontrada.');

        if ($ativo && $atual['ativo'] !== true) {
            $this->limites->garantirCapacidade('catracas');
        }

        Transacao::executar($this->pdo, function () use ($id, $ativo, $atual): void {
            $this->catracas->definirAtivo($id, $ativo);
            $this->auditoria->registrar('alterar', 'catraca', $id, ['ativo' => $atual['ativo']], ['ativo' => $ativo]);
        });
    }

    private static function gerarToken(): string
    {
        return self::PREFIXO_TOKEN . bin2hex(random_bytes(32));
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
            'empresa_id' => $texto('empresa_id'),
            'nome' => $texto('nome'),
            'localizacao' => $opcional('localizacao'),
            'identificador_dispositivo' => $texto('identificador_dispositivo'),
            'tipo_identificacao' => $texto('tipo_identificacao'),
            'modo_falha' => $texto('modo_falha'),
            'fabricante' => $opcional('fabricante'),
            'modelo' => $opcional('modelo'),
        ];
        $erros = [];

        $empresa = preg_match('/^[0-9a-f-]{36}$/i', $dados['empresa_id']) === 1
            ? $this->empresas->buscar($dados['empresa_id'])
            : null;

        if ($empresa === null) {
            $erros['empresa_id'] = 'Selecione a empresa.';
        } elseif ($id === null && $empresa['ativo'] !== true) {
            $erros['empresa_id'] = 'A empresa está inativa.';
        }

        if (mb_strlen($dados['nome']) < 2 || mb_strlen($dados['nome']) > 100) {
            $erros['nome'] = 'Informe o nome da catraca (2 a 100 caracteres).';
        }

        if (preg_match('/^[A-Za-z0-9._:-]{3,100}$/', $dados['identificador_dispositivo']) !== 1) {
            $erros['identificador_dispositivo'] = 'Use 3 a 100 caracteres: letras, números, ponto, hífen, dois-pontos.';
        } elseif ($this->catracas->identificadorEmUso($dados['identificador_dispositivo'], $id)) {
            $erros['identificador_dispositivo'] = 'Já existe uma catraca com este identificador.';
        }

        if (!array_key_exists($dados['tipo_identificacao'], self::TIPOS_IDENTIFICACAO)) {
            $erros['tipo_identificacao'] = 'Selecione o tipo de identificação.';
        }

        if (!array_key_exists($dados['modo_falha'], self::MODOS_FALHA)) {
            $erros['modo_falha'] = 'Selecione o modo de falha.';
        }

        foreach (['localizacao' => 150, 'fabricante' => 100, 'modelo' => 100] as $campo => $maximo) {
            if ($dados[$campo] !== null && mb_strlen($dados[$campo]) > $maximo) {
                $erros[$campo] = "Máximo de {$maximo} caracteres.";
            }
        }

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        return $dados;
    }
}
