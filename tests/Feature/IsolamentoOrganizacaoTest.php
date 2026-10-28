<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Multi-tenant: com a conexão da APLICAÇÃO (sujeita ao RLS), uma organização
 * não enxerga, não altera e não referencia dados de outra.
 *
 * Usa a conexão da aplicação (papel cadgestor_app), dentro da transação
 * do teste, desfeita no final.
 */
final class IsolamentoOrganizacaoTest extends TestCase
{
    private const CPF = '52998224725';

    private PDO $conexao;

    private string $orgA;

    private string $orgB;

    private string $empresaA;

    private string $pessoaA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conexao = DB::connection('pgsql')->getPdo();

        // A plataforma cria duas organizações (clientes).
        self::usarOrganizacao($this->conexao, null, true);
        $this->orgA = $this->app("INSERT INTO organizacao (nome) VALUES ('Cliente A') RETURNING id");
        $this->orgB = $this->app("INSERT INTO organizacao (nome) VALUES ('Cliente B') RETURNING id");
        $this->app('SELECT cg_provisionar_organizacao(:id)', ['id' => $this->orgA]);
        $this->app('SELECT cg_provisionar_organizacao(:id)', ['id' => $this->orgB]);

        self::usarOrganizacao($this->conexao, $this->orgA);
        $this->empresaA = $this->novaEmpresa();
        $this->pessoaA = $this->app(
            "INSERT INTO pessoa (nome, cpf) VALUES ('Maria Cliente A', :cpf) RETURNING id",
            ['cpf' => self::CPF],
        );
    }

    public function test_organizacao_nao_enxerga_dados_de_outra(): void
    {
        self::usarOrganizacao($this->conexao, $this->orgB);

        self::assertSame(0, $this->contar('SELECT count(*) FROM empresa'));
        self::assertSame(0, $this->contar('SELECT count(*) FROM pessoa WHERE id = :id', ['id' => $this->pessoaA]));
        self::assertSame(4, $this->contar('SELECT count(*) FROM perfil_vinculo'), 'Só os perfis da própria organização.');
        self::assertSame(1, $this->contar('SELECT count(*) FROM organizacao'), 'Só a própria organização.');
    }

    public function test_organizacao_nao_altera_dados_de_outra(): void
    {
        self::usarOrganizacao($this->conexao, $this->orgB);

        $alteracao = $this->conexao->prepare("UPDATE pessoa SET nome = 'Invasor' WHERE id = :id");
        $alteracao->execute(['id' => $this->pessoaA]);
        self::assertSame(0, $alteracao->rowCount());

        self::usarOrganizacao($this->conexao, $this->orgA);
        self::assertSame('Maria Cliente A', $this->app('SELECT nome FROM pessoa WHERE id = :id', ['id' => $this->pessoaA]));
    }

    public function test_mesmo_cpf_e_cnpj_podem_existir_em_organizacoes_diferentes(): void
    {
        self::usarOrganizacao($this->conexao, $this->orgB);

        $this->app("INSERT INTO pessoa (nome, cpf) VALUES ('Maria Cliente B', :cpf)", ['cpf' => self::CPF]);
        $this->novaEmpresa();

        self::assertSame(1, $this->contar('SELECT count(*) FROM pessoa'));
        self::assertSame(1, $this->contar('SELECT count(*) FROM empresa'));
    }

    public function test_nao_referencia_registro_de_outra_organizacao(): void
    {
        self::usarOrganizacao($this->conexao, $this->orgB);
        $pessoaB = $this->app("INSERT INTO pessoa (nome, cpf) VALUES ('João', '11144477735') RETURNING id");
        $perfilB = $this->app("SELECT id FROM perfil_vinculo WHERE nome = 'Funcionário'");

        // Mesmo conhecendo o UUID da empresa do cliente A, a FK composta recusa.
        self::assertSame('23503', $this->violacao(
            'INSERT INTO vinculo (pessoa_id, empresa_id, perfil_id, data_inicio) VALUES (:p, :e, :f, CURRENT_DATE)',
            ['p' => $pessoaB, 'e' => $this->empresaA, 'f' => $perfilB],
        ));
    }

    public function test_nao_grava_com_organizacao_alheia_nem_muda_de_organizacao(): void
    {
        self::usarOrganizacao($this->conexao, $this->orgB);

        // 42501: a política de RLS recusa a linha de outra organização.
        self::assertSame('42501', $this->violacao(
            "INSERT INTO pessoa (organizacao_id, nome, cpf) VALUES (:o, 'Intrusa', '11144477735')",
            ['o' => $this->orgA],
        ));

        self::usarOrganizacao($this->conexao, $this->orgA);
        self::assertSame('23514', $this->violacao(
            'UPDATE pessoa SET organizacao_id = :o WHERE id = :id',
            ['o' => $this->orgB, 'id' => $this->pessoaA],
        ));
    }

    public function test_sem_contexto_nada_aparece(): void
    {
        self::usarOrganizacao($this->conexao, null);

        self::assertSame(0, $this->contar('SELECT count(*) FROM pessoa'));
        self::assertSame(0, $this->contar('SELECT count(*) FROM perfil_vinculo'));
        self::assertSame(0, $this->contar('SELECT count(*) FROM organizacao'));
    }

    public function test_organizacao_nao_altera_o_proprio_cadastro_nem_ve_o_resumo_da_plataforma(): void
    {
        self::usarOrganizacao($this->conexao, $this->orgA);

        self::assertSame('42501', $this->violacao(
            "UPDATE organizacao SET nome = 'Renomeada' WHERE id = :id",
            ['id' => $this->orgA],
        ));
        self::assertSame('42501', $this->violacao('SELECT * FROM cg_resumo_organizacoes()'));
        self::assertSame('42501', $this->violacao(
            'SELECT cg_provisionar_organizacao(:id)',
            ['id' => $this->orgB],
        ));
    }

    public function test_usuario_de_organizacao_nao_recebe_papel_da_plataforma(): void
    {
        self::usarOrganizacao($this->conexao, $this->orgA);
        $usuario = $this->app(<<<'SQL'
            INSERT INTO usuario_sistema (nome, email, senha_hash)
            VALUES ('Admin A', 'admin.a@exemplo.com', 'x') RETURNING id
            SQL);

        self::assertSame('23514', $this->violacao(
            "INSERT INTO usuario_papel (usuario_id, papel) VALUES (:u, 'plataforma')",
            ['u' => $usuario],
        ));

        // B não vê o usuário de A, mas o e-mail continua reservado no sistema todo.
        self::usarOrganizacao($this->conexao, $this->orgB);
        self::assertSame(0, $this->contar('SELECT count(*) FROM usuario_sistema WHERE id = :id', ['id' => $usuario]));
        self::assertTrue((bool) $this->app("SELECT cg_email_em_uso('ADMIN.A@exemplo.com', NULL)"));
    }

    public function test_token_da_catraca_revela_a_organizacao_so_se_estiver_ativa(): void
    {
        $hash = str_repeat('ab', 32);
        $this->app(<<<'SQL'
            INSERT INTO catraca (empresa_id, nome, identificador_dispositivo, token_hash)
            VALUES (:e, 'Portaria', 'DISP-A', :h)
            SQL, ['e' => $this->empresaA, 'h' => $hash]);

        self::usarOrganizacao($this->conexao, null);
        self::assertSame($this->orgA, $this->app(
            'SELECT organizacao_id FROM cg_catraca_por_token(:h)',
            ['h' => $hash],
        ));

        self::usarOrganizacao($this->conexao, null, true);
        $this->app('UPDATE organizacao SET ativo = false WHERE id = :id', ['id' => $this->orgA]);
        self::usarOrganizacao($this->conexao, null);
        self::assertSame(0, $this->contar('SELECT count(*) FROM cg_catraca_por_token(:h)', ['h' => $hash]));
    }

    public function test_eventos_sem_organizacao_ficam_so_na_plataforma(): void
    {
        self::usarOrganizacao($this->conexao, null);
        $this->app(<<<'SQL'
            INSERT INTO evento_acesso (tipo_identificacao, resultado, motivo_negacao)
            VALUES ('digital', 'negado', 'catraca_inexistente')
            SQL);

        self::assertSame(1, $this->contar('SELECT count(*) FROM evento_acesso WHERE organizacao_id IS NULL'));

        self::usarOrganizacao($this->conexao, $this->orgA);
        self::assertSame(0, $this->contar('SELECT count(*) FROM evento_acesso'));
    }

    public function test_assinatura_e_faturas_so_da_propria_organizacao_e_catalogo_so_a_plataforma_altera(): void
    {
        self::usarOrganizacao($this->conexao, null, true);
        $this->app(<<<'SQL'
            INSERT INTO assinatura (organizacao_id, plano_id, status)
            SELECT :o, id, 'ativa' FROM plano WHERE codigo = 'basico'
            SQL, ['o' => $this->orgA]);

        self::usarOrganizacao($this->conexao, $this->orgB);
        self::assertSame(0, $this->contar('SELECT count(*) FROM assinatura'));
        self::assertSame(3, $this->contar('SELECT count(*) FROM plano'), 'O catálogo de planos é público.');

        // Um cliente não consegue mexer no preço do catálogo.
        $alteracao = $this->conexao->prepare('UPDATE plano SET preco_centavos = 1');
        $alteracao->execute();
        self::assertSame(0, $alteracao->rowCount());

        self::usarOrganizacao($this->conexao, $this->orgA);
        self::assertSame(1, $this->contar('SELECT count(*) FROM assinatura'));
    }

    // ---------------------------------------------------------------------

    public function test_anotacoes_e_historico_comercial_so_para_a_plataforma(): void
    {
        self::usarOrganizacao($this->conexao, null, true);
        $plataforma = $this->app(<<<'SQL'
            INSERT INTO usuario_sistema (organizacao_id, nome, email, senha_hash)
            VALUES (NULL, 'Equipe', 'equipe@plataforma.exemplo', 'x') RETURNING id
            SQL);
        $this->app("INSERT INTO usuario_papel (usuario_id, papel) VALUES (:id, 'plataforma')", ['id' => $plataforma]);
        $this->app("SELECT set_config('app.usuario_id', :id, true)", ['id' => $plataforma]);

        $this->app(
            "INSERT INTO organizacao_nota (organizacao_id, autor_id, texto) VALUES (:o, :a, 'Ligou pedindo desconto')",
            ['o' => $this->orgA, 'a' => $plataforma],
        );
        self::assertSame(1, $this->contar('SELECT count(*) FROM organizacao_nota'));
        $outro = $this->app(<<<'SQL'
            INSERT INTO usuario_sistema (organizacao_id, nome, email, senha_hash)
            VALUES (NULL, 'Outra pessoa', 'outra@plataforma.exemplo', 'x') RETURNING id
            SQL);
        self::assertSame('42501', $this->violacao(
            "INSERT INTO organizacao_nota (organizacao_id, autor_id, texto) VALUES (:o, :a, 'Assinada por outro')",
            ['o' => $this->orgA, 'a' => $outro],
        ), 'A anotação sai sempre no nome de quem está logado.');
        self::assertSame('42501', $this->violacao("UPDATE organizacao_nota SET texto = 'Editada'"), 'Só-acrescentar.');
        $this->app('SELECT count(*) FROM cg_historico_comercial(:o)', ['o' => $this->orgA]);

        // O cliente não enxerga as anotações nem consegue ler o histórico comercial.
        self::usarOrganizacao($this->conexao, $this->orgA);
        self::assertSame(0, $this->contar('SELECT count(*) FROM organizacao_nota'));
        self::assertSame('42501', $this->violacao(
            "INSERT INTO organizacao_nota (organizacao_id, autor_id, texto) VALUES (:o, :a, 'Do cliente')",
            ['o' => $this->orgA, 'a' => $plataforma],
        ));
        self::assertSame('42501', $this->violacao('SELECT * FROM cg_historico_comercial(:o)', ['o' => $this->orgA]));
    }

    public function test_cliente_so_atualiza_contatos_da_propria_organizacao_e_so_sendo_administrador(): void
    {
        $chamada = 'SELECT cg_atualizar_contato_organizacao(NULL, NULL, NULL, :cep, :rua, :num, NULL, :bairro, :cidade, '
            .":uf, :tel, 'fin@cliente.exemplo', NULL, NULL)";
        $dados = ['cep' => '60115170', 'rua' => 'Rua A', 'num' => '1', 'bairro' => 'Centro', 'cidade' => 'Fortaleza',
            'uf' => 'CE', 'tel' => '8532221100'];

        self::usarOrganizacao($this->conexao, $this->orgA);
        self::assertSame('42501', $this->violacao($chamada, $dados), 'Sem usuário logado.');

        $consulta = $this->app("INSERT INTO usuario_sistema (nome, email, senha_hash) VALUES ('Consulta A', 'consulta@a.exemplo', 'x') RETURNING id");
        $this->app("INSERT INTO usuario_papel (usuario_id, papel) VALUES (:id, 'consulta')", ['id' => $consulta]);
        $this->app("SELECT set_config('app.usuario_id', :id, true)", ['id' => $consulta]);
        self::assertSame('42501', $this->violacao($chamada, $dados), 'Papel consulta não altera o cadastro.');

        $admin = $this->app("INSERT INTO usuario_sistema (nome, email, senha_hash) VALUES ('Admin A', 'admin@a.exemplo', 'x') RETURNING id");
        $this->app("INSERT INTO usuario_papel (usuario_id, papel) VALUES (:id, 'administrador')", ['id' => $admin]);
        $this->app("SELECT set_config('app.usuario_id', :id, true)", ['id' => $admin]);
        $this->app($chamada, $dados);
        self::assertSame('8532221100', $this->app('SELECT telefone FROM organizacao WHERE id = :id', ['id' => $this->orgA]));
        self::assertSame('Cliente A', $this->app('SELECT nome FROM organizacao WHERE id = :id', ['id' => $this->orgA]));

        // A outra organização não muda, e o admin de A não vira admin de B trocando o contexto.
        self::usarOrganizacao($this->conexao, $this->orgB);
        self::assertSame('42501', $this->violacao($chamada, $dados));
        self::usarOrganizacao($this->conexao, null, true);
        self::assertNull($this->app('SELECT telefone FROM organizacao WHERE id = :id', ['id' => $this->orgB]));
    }

    /** Contexto do banco até o fim da transação do teste (null = sem cliente). */
    private static function usarOrganizacao(PDO $pdo, ?string $organizacaoId, bool $plataforma = false): void
    {
        $pdo->prepare(<<<'SQL'
            SELECT set_config('app.organizacao_id', :o, true), set_config('app.plataforma', :p, true),
                   set_config('app.usuario_id', '', true)
            SQL)->execute(['o' => $organizacaoId ?? '', 'p' => $plataforma ? 'on' : 'off']);
    }

    private function novaEmpresa(): string
    {
        return $this->app(<<<'SQL'
            INSERT INTO empresa (razao_social, cnpj, cep, logradouro, numero, bairro, municipio, uf,
                                 responsavel_nome, responsavel_email, responsavel_telefone)
            VALUES ('Prestadora Ltda', '11222333000181', '60000000', 'Rua Teste', '100', 'Centro', 'Fortaleza',
                    'CE', 'Responsável', 'responsavel@exemplo.com', '85999990000')
            RETURNING id
            SQL);
    }

    /**
     * Executa na conexão da aplicação e devolve a primeira coluna.
     *
     * @param  array<string, scalar|null>  $parametros
     */
    private function app(string $sql, array $parametros = []): mixed
    {
        $consulta = $this->conexao->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->columnCount() > 0 ? $consulta->fetchColumn() : null;
    }

    /** @param array<string, scalar|null> $parametros */
    private function contar(string $sql, array $parametros = []): int
    {
        return (int) $this->app($sql, $parametros);
    }

    /**
     * @param  array<string, scalar|null>  $parametros
     * @return string SQLSTATE da violação
     */
    private function violacao(string $sql, array $parametros = []): string
    {
        $this->conexao->exec('SAVEPOINT antes_da_violacao');

        try {
            $this->app($sql, $parametros);
        } catch (PDOException $erro) {
            $this->conexao->exec('ROLLBACK TO SAVEPOINT antes_da_violacao');

            return (string) $erro->getCode();
        }

        self::fail("Era esperada uma violação, mas o comando foi aceito: {$sql}");
    }
}
