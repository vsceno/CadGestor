<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use CadGestor\Dominio\DadosCadastraisCliente;
use PDO;

/**
 * Acesso a organizacao (os clientes do CadGestor).
 *
 * Pelo RLS, a plataforma enxerga todas; um usuário de organização, só a dele.
 */
final class OrganizacaoRepositorio
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Organizações com contagens (sem dados pessoais). Só no modo plataforma.
     *
     * @return list<array<string, mixed>>
     */
    public function listarComResumo(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT o.id, o.nome, o.nome_fantasia, o.cnpj, o.ativo, o.created_at,
                   COALESCE(r.usuarios, 0) AS usuarios, COALESCE(r.empresas, 0) AS empresas,
                   COALESCE(r.pessoas, 0) AS pessoas, COALESCE(r.catracas, 0) AS catracas
              FROM organizacao o
              LEFT JOIN cg_resumo_organizacoes() r ON r.organizacao_id = o.id
             ORDER BY o.ativo DESC, o.nome
            SQL)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function buscar(string $id): ?array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT id, nome, ativo, created_at, cnpj, termos_versao, termos_aceitos_em, nome_fantasia,
                   inscricao_municipal, inscricao_estadual, cep, logradouro, numero, complemento, bairro,
                   municipio, uf, telefone, email_financeiro, contato_nome, contato_telefone
              FROM organizacao WHERE id = :id
            SQL);
        $consulta->execute(['id' => $id]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function nomeEmUso(string $nome, ?string $excetoId = null): bool
    {
        $consulta = $this->pdo->prepare(
            'SELECT 1 FROM organizacao WHERE lower(nome) = lower(:nome) AND id IS DISTINCT FROM CAST(:id AS uuid)'
        );
        $consulta->execute(['nome' => $nome, 'id' => $excetoId]);

        return $consulta->fetchColumn() !== false;
    }

    /**
     * Venda manual: organização com razão social, CNPJ e dados cadastrais.
     *
     * @param array<string, string|null> $dados campos de DadosCadastraisCliente::CAMPOS_CONTATO
     */
    public function inserir(string $nome, ?string $cnpj = null, array $dados = []): string
    {
        $campos = array_keys(self::parametros($dados));
        $colunas = implode('', array_map(static fn (string $c): string => ', ' . $c, $campos));
        $valores = implode('', array_map(static fn (string $c): string => ', :' . $c, $campos));
        $consulta = $this->pdo->prepare(
            "INSERT INTO organizacao (nome, cnpj{$colunas}) VALUES (:nome, :cnpj{$valores}) RETURNING id"
        );
        $consulta->execute(['nome' => $nome, 'cnpj' => $cnpj] + $dados);

        return (string) $consulta->fetchColumn();
    }

    /** Cliente vindo do cadastro self-service, com o aceite dos Termos de Uso. */
    public function inserirCliente(
        string $nome,
        string $cnpj,
        string $telefone,
        string $versaoTermos,
        string $ip,
    ): string {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO organizacao (nome, cnpj, telefone, termos_versao, termos_aceitos_em, termos_aceitos_ip)
            VALUES (:nome, :cnpj, :telefone, :versao, now(), CAST(:ip AS inet))
            RETURNING id
            SQL);
        $consulta->execute([
            'nome' => $nome,
            'cnpj' => $cnpj,
            'telefone' => $telefone,
            'versao' => $versaoTermos,
            'ip' => $ip,
        ]);

        return (string) $consulta->fetchColumn();
    }

    /** CNPJ já usado por algum cliente (consulta o sistema todo, não só a organização atual). */
    public function cnpjEmUso(string $cnpj, ?string $excetoId = null): bool
    {
        $consulta = $this->pdo->prepare('SELECT cg_cnpj_cliente_em_uso(:cnpj, CAST(:exceto AS uuid))');
        $consulta->execute(['cnpj' => $cnpj, 'exceto' => $excetoId]);

        return (bool) $consulta->fetchColumn();
    }

    /** Perfis padrão e termo modelo da organização nova (idempotente). */
    public function provisionar(string $id): void
    {
        $this->pdo->prepare('SELECT cg_provisionar_organizacao(:id)')->execute(['id' => $id]);
    }

    /**
     * Edição pela plataforma (razão social, CNPJ e dados cadastrais).
     *
     * @param array<string, string|null> $dados campos de DadosCadastraisCliente::CAMPOS_CONTATO
     */
    public function atualizar(string $id, string $nome, ?string $cnpj = null, array $dados = []): void
    {
        $campos = array_keys(self::parametros($dados));
        $atribuicoes = implode('', array_map(static fn (string $c): string => ", {$c} = :{$c}", $campos));
        $this->pdo->prepare("UPDATE organizacao SET nome = :nome, cnpj = :cnpj{$atribuicoes} WHERE id = :id")
            ->execute(['id' => $id, 'nome' => $nome, 'cnpj' => $cnpj] + $dados);
    }

    /**
     * O administrador do cliente atualiza os próprios dados de contato. Passa
     * por uma função do banco que só mexe nesses campos e confere o papel.
     *
     * @param array<string, string|null> $dados campos de DadosCadastraisCliente::CAMPOS_CONTATO
     */
    public function atualizarContatoDaPropria(array $dados): void
    {
        $campos = DadosCadastraisCliente::CAMPOS_CONTATO;
        $marcadores = implode(', ', array_map(static fn (string $c): string => ':' . $c, $campos));
        $parametros = [];

        foreach ($campos as $campo) {
            $parametros[$campo] = $dados[$campo] ?? null;
        }

        $this->pdo->prepare("SELECT cg_atualizar_contato_organizacao({$marcadores})")->execute($parametros);
    }

    // --- Anotações internas e histórico (somente plataforma) ------------------

    public function adicionarNota(string $organizacaoId, string $texto): void
    {
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO organizacao_nota (organizacao_id, autor_id, texto) VALUES (:org, cg_usuario_atual(), :texto)
            SQL)->execute(['org' => $organizacaoId, 'texto' => $texto]);
    }

    /**
     * Linha do tempo do cliente: eventos comerciais (via função do banco, que
     * filtra os dados) e anotações internas, do mais recente ao mais antigo.
     *
     * @return list<array<string, mixed>>
     */
    public function historico(string $organizacaoId, int $limite = 100): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT data_hora, acao, origem, tabela, antes::text AS antes, depois::text AS depois, NULL AS texto,
                   NULL AS autor
              FROM cg_historico_comercial(:org, :limite)
            UNION ALL
            SELECT n.created_at, 'nota', 'plataforma', 'organizacao_nota', NULL, NULL, n.texto, u.nome
              FROM organizacao_nota n JOIN usuario_sistema u ON u.id = n.autor_id
             WHERE n.organizacao_id = :org
             ORDER BY 1 DESC
             LIMIT :limite
            SQL);
        $consulta->execute(['org' => $organizacaoId, 'limite' => $limite]);

        return array_map(static function (array $linha): array {
            $linha['antes'] = $linha['antes'] === null ? [] : json_decode((string) $linha['antes'], true);
            $linha['depois'] = $linha['depois'] === null ? [] : json_decode((string) $linha['depois'], true);

            return $linha;
        }, $consulta->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Só colunas da lista fechada podem virar nome de coluna no SQL.
     *
     * @param array<string, string|null> $dados
     * @return array<string, string|null>
     */
    private static function parametros(array $dados): array
    {
        $permitidos = array_flip(DadosCadastraisCliente::CAMPOS_CONTATO);

        if (array_diff_key($dados, $permitidos) !== []) {
            throw new \InvalidArgumentException('Campo de organização desconhecido.');
        }

        return $dados;
    }

    public function definirAtivo(string $id, bool $ativo): void
    {
        $this->pdo->prepare('UPDATE organizacao SET ativo = :ativo WHERE id = :id')
            ->execute(['id' => $id, 'ativo' => $ativo ? 'true' : 'false']);
    }
}
