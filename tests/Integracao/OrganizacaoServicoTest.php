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
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\OrganizacaoServico;
use CadGestor\Servicos\UsuarioServico;

/**
 * Cadastro de organizações pela plataforma.
 */
final class OrganizacaoServicoTest extends BancoTestCase
{
    private OrganizacaoServico $servico;
    private ContextoBanco $contextoBanco;

    protected function setUp(): void
    {
        parent::setUp();
        $auditoria = new Auditoria($this->pdo, new Contexto('10.0.0.1'));
        $this->contextoBanco = new ContextoBanco($this->pdo);
        $this->servico = new OrganizacaoServico(
            $this->pdo,
            new OrganizacaoRepositorio($this->pdo),
            new AssinaturaRepositorio($this->pdo),
            new UsuarioServico($this->pdo, new UsuarioRepositorio($this->pdo), new HashSenha(), $auditoria),
            $this->contextoBanco,
            $auditoria,
        );

        // Modo plataforma: fora de qualquer organização.
        $this->contextoBanco->definir(null, null, true);
    }

    public function testOrganizacaoNasceComPerfisPadraoETermoModelo(): void
    {
        $id = $this->servico->criar('  Condomínio   Solar ', $this->plano('basico'));

        self::assertSame('Condomínio Solar', $this->valor('SELECT nome FROM organizacao WHERE id = :id', $id));
        self::assertSame(4, (int) $this->valor('SELECT count(*) FROM perfil_vinculo WHERE organizacao_id = :id', $id));
        self::assertSame('1.0', $this->valor('SELECT versao FROM termo_consentimento WHERE organizacao_id = :id', $id));
        self::assertSame('ativa', $this->valor('SELECT status FROM assinatura WHERE organizacao_id = :id', $id));
        self::assertNull(
            $this->valor("SELECT organizacao_id FROM auditoria WHERE registro_id = :id AND acao = 'criar'", $id),
            'Criação de organização é ação da plataforma.',
        );
    }

    public function testNomeDuplicadoERecusado(): void
    {
        $this->servico->criar('Condomínio Solar', $this->plano('basico'));

        $this->expectException(ErroValidacao::class);
        $this->servico->criar('condomínio solar', $this->plano('basico'));
    }

    public function testAdministradorECriadoDentroDaOrganizacao(): void
    {
        $id = $this->servico->criar('Condomínio Solar', $this->plano('basico'));
        $admin = $this->servico->criarAdministrador($id, 'Síndica Teste', 'sindica@exemplo.com');

        self::assertSame($id, $this->valor('SELECT organizacao_id FROM usuario_sistema WHERE id = :id', $admin['id']));
        self::assertSame('administrador', $this->valor('SELECT papel FROM usuario_papel WHERE usuario_id = :id', $admin['id']));
        self::assertSame($id, $this->valor(
            "SELECT organizacao_id FROM auditoria WHERE registro_id = :id AND acao = 'criar'",
            $admin['id'],
        ), 'O cliente vê na própria auditoria quem criou o administrador.');

        // Depois, o contexto volta ao modo plataforma.
        self::assertNull($this->contextoBanco->organizacaoId());
        self::assertSame('', $this->pdo->query("SELECT current_setting('app.organizacao_id')")->fetchColumn());
    }

    public function testDesativarOrganizacao(): void
    {
        $id = $this->servico->criar('Condomínio Solar', $this->plano('basico'));
        $this->servico->alterarSituacao($id, false);

        self::assertFalse($this->valor('SELECT ativo FROM organizacao WHERE id = :id', $id));
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
