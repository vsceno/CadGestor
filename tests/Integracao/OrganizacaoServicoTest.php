<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Nucleo\Contexto;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Seguranca\LimitadorTentativas;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\OrganizacaoServico;
use CadGestor\Servicos\RecuperacaoContaServico;
use CadGestor\Servicos\UsuarioServico;

/**
 * Cadastro de organizações (clientes) pela plataforma.
 */
final class OrganizacaoServicoTest extends BancoTestCase
{
    private OrganizacaoServico $servico;
    private ContextoBanco $contextoBanco;
    private CaixaDeEmails $emails;

    protected function setUp(): void
    {
        parent::setUp();
        $auditoria = new Auditoria($this->pdo, new Contexto('10.0.0.1'));
        $this->contextoBanco = new ContextoBanco($this->pdo);
        $this->emails = new CaixaDeEmails();
        $usuarios = new UsuarioRepositorio($this->pdo);
        $organizacoes = new OrganizacaoRepositorio($this->pdo);
        $this->servico = new OrganizacaoServico(
            $this->pdo,
            $organizacoes,
            new AssinaturaRepositorio($this->pdo),
            new UsuarioServico($this->pdo, $usuarios, new HashSenha(), $auditoria),
            $usuarios,
            new RecuperacaoContaServico(
                $this->pdo,
                $usuarios,
                $organizacoes,
                $this->contextoBanco,
                new HashSenha(),
                new LimitadorTentativas($this->pdo, 'segredo-teste'),
                $this->emails,
                $auditoria,
                'http://cadgestor.test',
            ),
            $this->contextoBanco,
            $auditoria,
        );

        // Modo plataforma: fora de qualquer organização.
        $this->contextoBanco->definir(null, null, true);
    }

    public function testOrganizacaoNasceComCadastroCompletoPerfisETermoModelo(): void
    {
        $id = $this->servico->criar($this->entrada(['nome' => '  Condomínio   Solar ']));

        self::assertSame('Condomínio Solar', $this->valor('SELECT nome FROM organizacao WHERE id = :id', $id));
        self::assertSame('11444777000161', $this->valor('SELECT cnpj FROM organizacao WHERE id = :id', $id));
        self::assertSame('60115170', $this->valor('SELECT cep FROM organizacao WHERE id = :id', $id));
        self::assertSame('CE', $this->valor('SELECT uf FROM organizacao WHERE id = :id', $id));
        self::assertSame('8532221100', $this->valor('SELECT telefone FROM organizacao WHERE id = :id', $id));
        self::assertSame('financeiro@solar.exemplo', $this->valor('SELECT email_financeiro FROM organizacao WHERE id = :id', $id));
        self::assertSame(4, (int) $this->valor('SELECT count(*) FROM perfil_vinculo WHERE organizacao_id = :id', $id));
        self::assertSame('1.0', $this->valor('SELECT versao FROM termo_consentimento WHERE organizacao_id = :id', $id));
        self::assertSame('ativa', $this->valor('SELECT status FROM assinatura WHERE organizacao_id = :id', $id));
        self::assertNull(
            $this->valor("SELECT organizacao_id FROM auditoria WHERE registro_id = :id AND acao = 'criar'", $id),
            'Criação de organização é ação da plataforma.',
        );
    }

    public function testCadastroExigeCnpjEnderecoTelefoneEEmailFinanceiro(): void
    {
        try {
            $this->servico->criar(['nome' => 'Condomínio Solar', 'plano_id' => '']);
            self::fail('Deveria recusar o cadastro incompleto.');
        } catch (ErroValidacao $erro) {
            foreach (['cnpj', 'cep', 'logradouro', 'numero', 'bairro', 'municipio', 'uf', 'telefone', 'email_financeiro', 'plano'] as $campo) {
                self::assertArrayHasKey($campo, $erro->erros, "Campo {$campo} deveria ser obrigatório.");
            }
        }
    }

    public function testRazaoSocialECnpjDuplicadosSaoRecusados(): void
    {
        $this->servico->criar($this->entrada());

        try {
            $this->servico->criar($this->entrada(['nome' => 'condomínio solar', 'cnpj' => '11.222.333/0001-81']));
            self::fail('Razão social repetida.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('nome', $erro->erros);
        }

        try {
            $this->servico->criar($this->entrada(['nome' => 'Outro Cliente']));
            self::fail('CNPJ repetido.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('cnpj', $erro->erros);
        }
    }

    public function testEdicaoAtualizaCadastroEAuditaSoOsCamposAlterados(): void
    {
        $id = $this->servico->criar($this->entrada());
        $this->servico->atualizar($id, $this->entrada(['numero' => '200', 'complemento' => 'Sala 3']));

        self::assertSame('200', $this->valor('SELECT numero FROM organizacao WHERE id = :id', $id));
        $depois = json_decode((string) $this->valor(
            "SELECT dados_depois FROM auditoria WHERE registro_id = :id AND acao = 'alterar' ORDER BY data_hora DESC LIMIT 1",
            $id,
        ), true);
        self::assertSame(['numero', 'complemento'], $depois['campos']);
        self::assertArrayNotHasKey('cep', $depois);

        // O próprio CNPJ do cliente não conta como "em uso" na edição.
        $this->servico->atualizar($id, $this->entrada(['nome' => 'Condomínio Solar Ltda']));
        self::assertSame('Condomínio Solar Ltda', $this->valor('SELECT nome FROM organizacao WHERE id = :id', $id));
    }

    public function testAdministradorRecebeLinkENinguemVeASenha(): void
    {
        $id = $this->servico->criar($this->entrada());
        $adminId = $this->servico->criarAdministrador($id, 'Síndica Teste', 'sindica@exemplo.com');

        self::assertSame($id, $this->valor('SELECT organizacao_id FROM usuario_sistema WHERE id = :id', $adminId));
        self::assertSame('administrador', $this->valor('SELECT papel FROM usuario_papel WHERE usuario_id = :id', $adminId));
        self::assertSame($id, $this->valor(
            "SELECT organizacao_id FROM auditoria WHERE registro_id = :id AND acao = 'criar'",
            $adminId,
        ), 'O cliente vê na própria auditoria quem criou o administrador.');

        // Link de boas-vindas para o e-mail do administrador; no banco, só o hash do token.
        self::assertCount(1, $this->emails->enviados);
        self::assertSame('sindica@exemplo.com', $this->emails->enviados[0]['para']);
        $token = $this->emails->ultimoToken('redefinir-senha');
        self::assertNotNull($token);
        self::assertSame(hash('sha256', $token), $this->valor(
            'SELECT redefinicao_token_hash FROM usuario_sistema WHERE id = :id',
            $adminId,
        ));
        self::assertStringNotContainsString('senha provisória', mb_strtolower($this->emails->enviados[0]['texto']));

        // Depois, o contexto volta ao modo plataforma.
        self::assertNull($this->contextoBanco->organizacaoId());
        self::assertSame('', $this->pdo->query("SELECT current_setting('app.organizacao_id')")->fetchColumn());
    }

    public function testDesativarOrganizacao(): void
    {
        $id = $this->servico->criar($this->entrada());
        $this->servico->alterarSituacao($id, false);

        self::assertFalse($this->valor('SELECT ativo FROM organizacao WHERE id = :id', $id));
    }

    /**
     * @param array<string, string> $troca
     * @return array<string, string>
     */
    private function entrada(array $troca = []): array
    {
        return $troca + [
            'nome' => 'Condomínio Solar',
            'cnpj' => '11.444.777/0001-61',
            'nome_fantasia' => 'Solar',
            'inscricao_municipal' => '123456',
            'inscricao_estadual' => 'ISENTO',
            'cep' => '60115-170',
            'logradouro' => 'Avenida Exemplo',
            'numero' => '100',
            'complemento' => '',
            'bairro' => 'Centro',
            'municipio' => 'Fortaleza',
            'uf' => 'ce',
            'telefone' => '(85) 3222-1100',
            'email_financeiro' => 'Financeiro@Solar.exemplo',
            'contato_nome' => 'Contato Exemplo',
            'contato_telefone' => '(85) 99999-0000',
            'plano_id' => $this->plano('basico'),
        ];
    }

    private function plano(string $codigo): string
    {
        return (string) $this->pdo->query("SELECT id FROM plano WHERE codigo = '{$codigo}'")->fetchColumn();
    }

    private function valor(string $sql, string $id): mixed
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['id' => $id]);

        return $consulta->fetchColumn();
    }
}
