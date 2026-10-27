<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Seguranca\HashSenha;
use CadGestor\Servicos\CriacaoAdministradorInicial;
use DomainException;

final class CriacaoAdministradorInicialTest extends BancoTestCase
{
    private CriacaoAdministradorInicial $servico;

    protected function setUp(): void
    {
        parent::setUp();
        // O serviço controla a própria transação, então este teste não usa a
        // transação da base: a limpeza é feita explicitamente no tearDown.
        $this->pdo->rollBack();
        $this->limpar();
        $this->servico = new CriacaoAdministradorInicial($this->pdo, new HashSenha());
    }

    protected function tearDown(): void
    {
        $this->limpar();
        parent::tearDown();
    }

    private function limpar(): void
    {
        $this->pdo->exec("DELETE FROM auditoria WHERE acao = 'seed'");
        $this->pdo->exec('DELETE FROM usuario_sistema');
    }

    public function testCriaAdministradorComSenhaArgon2idETrocaObrigatoria(): void
    {
        $resultado = $this->servico->criar('  Maria   Souza ', 'Maria@Exemplo.COM');

        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT u.nome, u.email, u.senha_hash, u.deve_trocar_senha, p.papel
              FROM usuario_sistema u JOIN usuario_papel p ON p.usuario_id = u.id
             WHERE u.id = :id
            SQL);
        $consulta->execute(['id' => $resultado['id']]);
        $usuario = $consulta->fetch();

        self::assertSame('Maria Souza', $usuario['nome']);
        self::assertSame('maria@exemplo.com', $usuario['email']);
        self::assertTrue($usuario['deve_trocar_senha']);
        self::assertSame('administrador', $usuario['papel']);
        self::assertStringStartsWith('$argon2id$', $usuario['senha_hash']);
        self::assertTrue(password_verify($resultado['senha'], $usuario['senha_hash']));
        self::assertGreaterThanOrEqual(HashSenha::TAMANHO_MINIMO, strlen($resultado['senha']));
    }

    public function testRegistraAuditoriaSemDadosPessoais(): void
    {
        $resultado = $this->servico->criar('Maria Souza', 'maria@exemplo.com');

        $consulta = $this->pdo->prepare(
            "SELECT dados_depois::text FROM auditoria WHERE registro_id = :id AND acao = 'seed'",
        );
        $consulta->execute(['id' => $resultado['id']]);
        $dados = (string) $consulta->fetchColumn();

        self::assertStringContainsString('administrador', $dados);
        self::assertStringNotContainsString('maria', $dados);
    }

    public function testRecusaSegundoAdministradorAtivo(): void
    {
        $this->servico->criar('Maria Souza', 'maria@exemplo.com');

        $this->expectException(DomainException::class);
        $this->servico->criar('João Lima', 'joao@exemplo.com');
    }

    public function testRecusaEmailInvalido(): void
    {
        $this->expectException(DomainException::class);
        $this->servico->criar('Maria Souza', 'nao-e-email');
    }

    public function testRecusaNomeCurto(): void
    {
        $this->expectException(DomainException::class);
        $this->servico->criar('M', 'maria@exemplo.com');
    }
}
