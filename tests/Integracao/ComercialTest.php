<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Cobranca\CobrancaGateway;
use CadGestor\Cobranca\EventoPagamento;
use CadGestor\Cobranca\GatewaySimulado;
use CadGestor\Mensagens\Mensageiro;
use CadGestor\Nucleo\Contexto;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Servicos\AssinaturaServico;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\CadastroClienteServico;
use CadGestor\Servicos\LimitesPlano;
use CadGestor\Servicos\PagamentoServico;
use DateTimeImmutable;

/**
 * Cadastro self-service, pagamentos (webhooks), limites e assinatura.
 */
final class ComercialTest extends BancoTestCase
{
    private const SENHA = 'girassol amarelo varanda quarenta';

    private CadastroClienteServico $cadastro;
    private PagamentoServico $pagamentos;
    private AssinaturaRepositorio $assinaturas;
    private ContextoBanco $contextoBanco;

    /** @var list<array{para: string, assunto: string, texto: string}> */
    private array $emails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $auditoria = new Auditoria($this->pdo, new Contexto('10.0.0.9'));
        $this->contextoBanco = new ContextoBanco($this->pdo);
        $this->assinaturas = new AssinaturaRepositorio($this->pdo);
        $emails = &$this->emails;
        $mensageiro = new class ($emails) implements Mensageiro {
            /** @param list<array<string, string>> $caixa */
            public function __construct(private array &$caixa)
            {
            }

            public function enviar(string $para, string $assunto, string $texto): void
            {
                $this->caixa[] = ['para' => $para, 'assunto' => $assunto, 'texto' => $texto];
            }
        };

        $this->cadastro = new CadastroClienteServico(
            $this->pdo,
            new OrganizacaoRepositorio($this->pdo),
            new UsuarioRepositorio($this->pdo),
            $this->assinaturas,
            new GatewaySimulado('token-teste'),
            $this->contextoBanco,
            new HashSenha(),
            $mensageiro,
            $auditoria,
            'http://cadgestor.test',
        );
        $this->pagamentos = new PagamentoServico($this->pdo, $this->assinaturas, $this->contextoBanco, $auditoria);
    }

    public function testCadastroCriaAmbienteCompletoEmTeste(): void
    {
        $org = $this->cadastrar();

        self::assertSame('teste', $this->valor('SELECT status FROM assinatura WHERE organizacao_id = :id', $org));
        self::assertSame('2026-11-15', $this->valor('SELECT teste_ate::text FROM assinatura WHERE organizacao_id = :id', $org));
        self::assertSame('simulado', $this->valor('SELECT gateway FROM assinatura WHERE organizacao_id = :id', $org));
        self::assertSame(4, (int) $this->valor('SELECT count(*) FROM perfil_vinculo WHERE organizacao_id = :id', $org));
        self::assertSame(CadastroClienteServico::VERSAO_TERMOS, $this->valor('SELECT termos_versao FROM organizacao WHERE id = :id', $org));

        // Primeira fatura vence no dia seguinte ao fim do teste.
        self::assertSame('2026-11-16', $this->valor('SELECT vencimento::text FROM fatura WHERE organizacao_id = :id', $org));

        // Administrador criado, mas aguardando confirmação do e-mail (só o hash do token no banco).
        $admin = $this->pdo->prepare(<<<'SQL'
            SELECT u.email_verificado_em, u.verificacao_token_hash, p.papel
              FROM usuario_sistema u JOIN usuario_papel p ON p.usuario_id = u.id
             WHERE u.organizacao_id = :id
            SQL);
        $admin->execute(['id' => $org]);
        $linha = $admin->fetch();
        self::assertNull($linha['email_verificado_em']);
        self::assertSame('administrador', $linha['papel']);

        self::assertCount(1, $this->emails);
        preg_match('/token=([0-9a-f]{64})/', $this->emails[0]['texto'], $m);
        self::assertSame(hash('sha256', $m[1]), $linha['verificacao_token_hash']);
    }

    public function testConfirmacaoDeEmailUsaOTokenUmaVezSo(): void
    {
        $this->cadastrar();
        preg_match('/token=([0-9a-f]{64})/', $this->emails[0]['texto'], $m);

        self::assertFalse($this->cadastro->confirmarEmail(str_repeat('0', 64)));
        self::assertTrue($this->cadastro->confirmarEmail($m[1]));
        self::assertFalse($this->cadastro->confirmarEmail($m[1]), 'O token não pode ser reutilizado.');
        self::assertNotNull($this->valor(
            "SELECT email_verificado_em FROM usuario_sistema WHERE email = :id",
            'admin@condominio.exemplo',
        ));
    }

    public function testCadastroValidaCnpjRepetidoEAceiteDosTermos(): void
    {
        $this->cadastrar();

        try {
            $this->cadastrar(['email' => 'outro@condominio.exemplo', 'aceite' => '']);
            self::fail('Cadastro inválido foi aceito.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('cnpj', $erro->erros);
            self::assertArrayHasKey('aceite', $erro->erros);
        }
    }

    public function testPagamentoVencimentoEDuplicidadeDoAviso(): void
    {
        $org = $this->cadastrar();
        $cobranca = (string) $this->valor('SELECT gateway_cobranca_id FROM fatura WHERE organizacao_id = :id', $org);
        $evento = fn (string $id, string $tipo): EventoPagamento => new EventoPagamento(
            $id,
            $tipo,
            null,
            new CobrancaGateway($cobranca, 49900, '2026-11-16', null),
        );

        self::assertSame(PagamentoServico::PROCESSADO, $this->pagamentos->processar(
            $evento('evt-1', EventoPagamento::COBRANCA_VENCIDA),
            'simulado',
        ));
        self::assertSame('inadimplente', $this->valor('SELECT status FROM assinatura WHERE organizacao_id = :id', $org));
        self::assertSame('2026-11-17', $this->valor(
            'SELECT inadimplente_desde::text FROM assinatura WHERE organizacao_id = :id',
            $org,
        ));

        self::assertSame(PagamentoServico::PROCESSADO, $this->pagamentos->processar(
            $evento('evt-2', EventoPagamento::PAGAMENTO_CONFIRMADO),
            'simulado',
        ));
        self::assertSame('ativa', $this->valor('SELECT status FROM assinatura WHERE organizacao_id = :id', $org));
        self::assertSame('paga', $this->valor('SELECT status FROM fatura WHERE organizacao_id = :id', $org));

        // O mesmo aviso reenviado não é aplicado de novo.
        self::assertSame(PagamentoServico::DUPLICADO, $this->pagamentos->processar(
            $evento('evt-1', EventoPagamento::COBRANCA_VENCIDA),
            'simulado',
        ));
        self::assertSame('ativa', $this->valor('SELECT status FROM assinatura WHERE organizacao_id = :id', $org));

        // A auditoria do pagamento fica na trilha do próprio cliente.
        self::assertSame(2, (int) $this->valor("SELECT count(*) FROM auditoria WHERE acao = 'pagamento' AND organizacao_id = :id", $org));
    }

    public function testAvisoDeCobrancaDesconhecidaNaoAlteraNada(): void
    {
        $resultado = $this->pagamentos->processar(new EventoPagamento(
            'evt-x',
            EventoPagamento::PAGAMENTO_CONFIRMADO,
            'assinatura-que-nao-existe',
            new CobrancaGateway('cobranca-que-nao-existe', 100, '2026-11-01', null),
        ), 'simulado');

        self::assertSame(PagamentoServico::DESCONHECIDO, $resultado);
    }

    public function testLimiteDoPlanoBloqueiaNovaCatraca(): void
    {
        // Plano básico: até 2 catracas.
        $this->pdo->exec("UPDATE assinatura SET plano_id = (SELECT id FROM plano WHERE codigo = 'basico')");
        $empresa = $this->criarEmpresa();
        $limites = new LimitesPlano($this->assinaturas);

        foreach (['C1', 'C2'] as $i => $nome) {
            $limites->garantirCapacidade('catracas');
            $this->pdo->prepare(<<<'SQL'
                INSERT INTO catraca (empresa_id, nome, identificador_dispositivo, token_hash) VALUES (:e, :n, :n, :t)
                SQL)->execute(['e' => $empresa, 'n' => $nome, 't' => hash('sha256', $nome)]);
        }

        $this->expectException(ErroValidacao::class);
        $limites->garantirCapacidade('catracas');
    }

    public function testDowngradeSoSeOUsoCouberECancelamentoBloqueia(): void
    {
        $servico = new AssinaturaServico(
            $this->pdo,
            $this->assinaturas,
            new GatewaySimulado('token-teste'),
            new Auditoria($this->pdo, new Contexto('10.0.0.9')),
        );
        $empresa = $this->criarEmpresa();

        foreach (['C1', 'C2', 'C3'] as $nome) {
            $this->pdo->prepare(<<<'SQL'
                INSERT INTO catraca (empresa_id, nome, identificador_dispositivo, token_hash) VALUES (:e, :n, :n, :t)
                SQL)->execute(['e' => $empresa, 'n' => $nome, 't' => hash('sha256', $nome)]);
        }

        $basico = (string) $this->pdo->query("SELECT id FROM plano WHERE codigo = 'basico'")->fetchColumn();

        try {
            $servico->trocarPlano($basico);
            self::fail('Downgrade acima do limite foi aceito.');
        } catch (ErroValidacao $erro) {
            self::assertStringContainsString('não cabe', $erro->erros['plano']);
        }

        $servico->cancelar();
        self::assertFalse($servico->situacao()->catracasLiberadas());
    }

    /** @param array<string, string> $troca */
    private function cadastrar(array $troca = []): string
    {
        return $this->cadastro->cadastrar($troca + [
            'razao_social' => 'Condomínio Exemplo Ltda',
            'cnpj' => '11.444.777/0001-61',
            'responsavel_nome' => 'Síndica Exemplo',
            'email' => 'admin@condominio.exemplo',
            'senha' => self::SENHA,
            'confirmacao' => self::SENHA,
            'plano' => 'profissional',
            'aceite' => '1',
        ], '10.0.0.9', new DateTimeImmutable('2026-11-01'));
    }

    private function valor(string $sql, string $id): mixed
    {
        $consulta = $this->pdo->prepare($sql);
        $consulta->execute(['id' => $id]);

        return $consulta->fetchColumn();
    }
}
