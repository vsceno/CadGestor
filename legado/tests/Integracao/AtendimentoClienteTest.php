<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Cobranca\GatewaySimulado;
use CadGestor\Nucleo\Contexto;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Seguranca\LimitadorTentativas;
use CadGestor\Seguranca\Papel;
use CadGestor\Servicos\AtendimentoClienteServico;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\RecuperacaoContaServico;
use DateTimeImmutable;

/**
 * Ferramentas de atendimento da plataforma: plano, teste grátis, baixa
 * manual, anotações, histórico e acesso dos administradores do cliente.
 */
final class AtendimentoClienteTest extends BancoTestCase
{
    private AtendimentoClienteServico $servico;
    private ContextoBanco $contextoBanco;
    private OrganizacaoRepositorio $organizacoes;
    private UsuarioRepositorio $usuarios;
    private LimitadorTentativas $limitador;
    private CaixaDeEmails $emails;
    private Contexto $contexto;
    private string $plataformaId;
    private string $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contexto = new Contexto('10.0.0.5');
        $auditoria = new Auditoria($this->pdo, $this->contexto);
        $this->contextoBanco = new ContextoBanco($this->pdo);
        $this->organizacoes = new OrganizacaoRepositorio($this->pdo);
        $this->usuarios = new UsuarioRepositorio($this->pdo);
        $this->limitador = new LimitadorTentativas($this->pdo, 'segredo-de-teste');
        $this->emails = new CaixaDeEmails();
        $this->servico = new AtendimentoClienteServico(
            $this->pdo,
            $this->organizacoes,
            new AssinaturaRepositorio($this->pdo),
            $this->usuarios,
            new GatewaySimulado('token'),
            new RecuperacaoContaServico(
                $this->pdo,
                $this->usuarios,
                $this->organizacoes,
                $this->contextoBanco,
                new HashSenha(),
                $this->limitador,
                $this->emails,
                $auditoria,
                'http://cadgestor.test',
            ),
            $this->limitador,
            $this->contextoBanco,
            $auditoria,
        );

        $this->cliente = $this->criarOrganizacao('Cliente Atendimento');
        self::usarOrganizacao($this->pdo, $this->cliente);
        $this->inserir(<<<'SQL'
            INSERT INTO assinatura (plano_id, status, teste_ate, gateway, gateway_assinatura_id)
            SELECT id, 'teste', '2026-11-10', 'simulado', 'sim_ass_1' FROM plano WHERE codigo = 'basico'
            RETURNING id
            SQL);
        $this->inserir(<<<'SQL'
            INSERT INTO fatura (assinatura_id, gateway, gateway_cobranca_id, valor_centavos, vencimento)
            SELECT id, 'simulado', 'cob-1', 19900, '2026-11-11' FROM assinatura WHERE organizacao_id = cg_org_atual()
            RETURNING id
            SQL);

        // Usuário da plataforma operando (organizacao_id NULL, papel plataforma).
        self::usarOrganizacao($this->pdo, null, true);
        $this->plataformaId = $this->inserir(<<<'SQL'
            INSERT INTO usuario_sistema (organizacao_id, nome, email, senha_hash, deve_trocar_senha)
            VALUES (NULL, 'Equipe Comercial', 'comercial@plataforma.exemplo', 'x', false) RETURNING id
            SQL);
        $this->pdo->prepare("INSERT INTO usuario_papel (usuario_id, papel) VALUES (:id, 'plataforma')")
            ->execute(['id' => $this->plataformaId]);
        $this->contexto->usuarioId = $this->plataformaId;
        $this->contextoBanco->definir(null, $this->plataformaId, true);
    }

    public function testEstenderTesteMoveAPrimeiraCobranca(): void
    {
        $fim = $this->servico->estenderTeste($this->cliente, 7, 'Cliente pediu mais prazo', new DateTimeImmutable('2026-11-01'));

        self::assertSame('2026-11-17', $fim);
        self::assertSame('2026-11-17', $this->valor("SELECT teste_ate::text FROM assinatura WHERE organizacao_id = :o"));
        self::assertSame('2026-11-18', $this->valor("SELECT vencimento::text FROM fatura WHERE organizacao_id = :o"));

        // Fica na trilha do CLIENTE, feito por um usuário da plataforma, com o motivo.
        $linha = $this->pdo->prepare(<<<'SQL'
            SELECT usuario_id, dados_depois->>'motivo' AS motivo FROM auditoria
             WHERE organizacao_id = :o AND acao = 'assinatura_alterada'
            SQL);
        $linha->execute(['o' => $this->cliente]);
        $auditoria = $linha->fetch();
        self::assertSame($this->plataformaId, $auditoria['usuario_id']);
        self::assertSame('Cliente pediu mais prazo', $auditoria['motivo']);

        $this->expectException(ErroValidacao::class);
        $this->servico->estenderTeste($this->cliente, 31, 'Prazo grande demais');
    }

    public function testBaixaManualQuitaFaturaEColocaEmDia(): void
    {
        $fatura = (string) $this->valor('SELECT id FROM fatura WHERE organizacao_id = :o');
        $this->pdo->prepare(<<<'SQL'
            UPDATE assinatura SET status = 'inadimplente', inadimplente_desde = '2026-11-12', teste_ate = NULL
             WHERE organizacao_id = :o
            SQL)->execute(['o' => $this->cliente]);
        $this->pdo->prepare("UPDATE fatura SET status = 'vencida' WHERE id = :id")->execute(['id' => $fatura]);

        $this->servico->darBaixaManual($this->cliente, $fatura, 'Pix recebido na conta da empresa');

        self::assertSame('paga', $this->valor("SELECT status FROM fatura WHERE organizacao_id = :o"));
        self::assertSame('ativa', $this->valor("SELECT status FROM assinatura WHERE organizacao_id = :o"));

        try {
            $this->servico->darBaixaManual($this->cliente, $fatura, 'Segunda baixa da mesma fatura');
            self::fail('Fatura já paga.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('fatura', $erro->erros);
        }

        // Fatura de outro cliente: o RLS esconde e a resposta é "não encontrada".
        try {
            $this->servico->darBaixaManual(self::organizacaoTeste(), $fatura, 'Tentativa cruzada');
            self::fail('Não pode quitar fatura de outro cliente.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('id', $erro->erros);
        }
    }

    public function testTrocaDePlanoExigeMotivoEUsoQueCaiba(): void
    {
        $profissional = (string) $this->pdo->query("SELECT id FROM plano WHERE codigo = 'profissional'")->fetchColumn();

        try {
            $this->servico->trocarPlano($this->cliente, $profissional, '');
            self::fail('Motivo é obrigatório.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('motivo', $erro->erros);
        }

        $this->servico->trocarPlano($this->cliente, $profissional, 'Upgrade negociado por telefone');
        self::assertSame('profissional', $this->valor(
            'SELECT p.codigo FROM assinatura a JOIN plano p ON p.id = a.plano_id WHERE a.organizacao_id = :o',
        ));
    }

    public function testAnotacoesSaoSoDaPlataformaEHistoricoNaoTrazDadosPessoais(): void
    {
        $this->servico->anotar($this->cliente, 'Cliente pediu desconto em março.');
        $this->servico->estenderTeste($this->cliente, 3, 'Atraso na instalação', new DateTimeImmutable('2026-11-01'));

        $historico = $this->organizacoes->historico($this->cliente);
        $acoes = array_column($historico, 'acao');
        self::assertContains('nota', $acoes);
        self::assertContains('assinatura_alterada', $acoes);

        // Administrador criado no cliente: o histórico mostra o fato, mas nunca o nome ou e-mail.
        $admin = $this->criarAdmin();
        $this->servico->enviarLinkRedefinicao($this->cliente, $admin);
        $json = json_encode($this->organizacoes->historico($this->cliente), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('admin@cliente.exemplo', (string) $json);
        self::assertStringNotContainsString('Admin do Cliente', (string) $json);
        // O isolamento (cliente não vê anotações nem histórico) está em IsolamentoOrganizacaoTest.
    }

    public function testFerramentasDeAcessoDoAdministrador(): void
    {
        $admin = $this->criarAdmin();

        // Link de redefinição: vai para o e-mail do administrador; a plataforma não recebe a senha.
        $this->servico->enviarLinkRedefinicao($this->cliente, $admin);
        self::assertSame('admin@cliente.exemplo', $this->emails->enviados[0]['para']);
        self::assertNotNull($this->emails->ultimoToken('redefinir-senha'));

        // Desbloqueio do login travado.
        $hash = $this->limitador->identificadorEmail('admin@cliente.exemplo');

        for ($i = 0; $i < LimitadorTentativas::MAXIMO_POR_EMAIL; $i++) {
            $this->limitador->registrar($hash, '10.9.9.' . $i, false);
        }

        self::assertTrue($this->limitador->emailBloqueado('admin@cliente.exemplo'));
        $this->servico->desbloquearLogin($this->cliente, $admin, '10.0.0.5');
        self::assertFalse($this->limitador->emailBloqueado('admin@cliente.exemplo'));

        // Desativar e reativar.
        $this->servico->alterarSituacaoAdministrador($this->cliente, $admin, false);
        self::assertFalse($this->valor('SELECT ativo FROM usuario_sistema WHERE organizacao_id = :o'));

        try {
            $this->servico->enviarLinkRedefinicao($this->cliente, $admin);
            self::fail('Administrador inativo não recebe link.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('usuario', $erro->erros);
        }

        // Usuário de outro cliente não é alcançável pela organização errada.
        try {
            $this->servico->alterarSituacaoAdministrador(self::organizacaoTeste(), $admin, true);
            self::fail('Administrador de outro cliente.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('id', $erro->erros);
        }
    }

    private function criarAdmin(): string
    {
        return $this->contextoBanco->dentroDe($this->cliente, function (): string {
            $id = $this->usuarios->inserir('Admin do Cliente', 'admin@cliente.exemplo', 'x', false);
            $this->usuarios->definirPapeis($id, [Papel::Administrador]);

            return $id;
        });
    }

    private function valor(string $sql): mixed
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['o' => $this->cliente]);

        return $consulta->fetchColumn();
    }
}
