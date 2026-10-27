<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Nucleo\Contexto;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\UsuarioServico;

final class UsuarioServicoTest extends BancoTestCase
{
    private UsuarioServico $servico;
    private UsuarioRepositorio $usuarios;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuarios = new UsuarioRepositorio($this->pdo);
        $this->servico = new UsuarioServico(
            $this->pdo,
            $this->usuarios,
            new HashSenha(),
            new Auditoria($this->pdo, new Contexto('127.0.0.1')),
        );
    }

    public function testCriaComSenhaProvisoriaEPapeis(): void
    {
        $resultado = $this->servico->criar('Ana Lima', 'ana@exemplo.com', ['consulta', 'cadastro_pessoa', 'invalido']);
        $usuario = $this->usuarios->buscarPorId($resultado['id']);

        self::assertTrue($usuario['deve_trocar_senha']);
        self::assertSame(['cadastro_pessoa', 'consulta'], $usuario['papeis']);
        self::assertTrue(password_verify($resultado['senha'], $usuario['senha_hash']));
    }

    public function testExigePeloMenosUmPapelEEmailUnico(): void
    {
        $this->servico->criar('Ana Lima', 'ana@exemplo.com', ['consulta']);

        try {
            $this->servico->criar('Outra Ana', 'ANA@exemplo.com', []);
            self::fail('Deveria recusar.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('email', $erro->erros);
            self::assertArrayHasKey('papeis', $erro->erros);
        }
    }

    public function testNaoPermiteRemoverOProprioAcessoDeAdministrador(): void
    {
        $admin = $this->servico->criar('Admin Um', 'admin1@exemplo.com', ['administrador'])['id'];
        $this->servico->criar('Admin Dois', 'admin2@exemplo.com', ['administrador']);

        $this->expectException(ErroValidacao::class);
        $this->servico->atualizar($admin, 'Admin Um', 'admin1@exemplo.com', ['consulta'], true, $admin);
    }

    public function testMantemPeloMenosUmAdministradorAtivo(): void
    {
        $this->pdo->exec("UPDATE usuario_sistema SET ativo = false");
        $admin = $this->servico->criar('Admin Único', 'unico@exemplo.com', ['administrador'])['id'];
        $editor = $this->servico->criar('Editor', 'editor@exemplo.com', ['consulta'])['id'];

        $this->expectException(ErroValidacao::class);
        $this->servico->atualizar($admin, 'Admin Único', 'unico@exemplo.com', ['administrador'], false, $editor);
    }

    public function testTrocaDeSenhaExigeSenhaAtualEPolitica(): void
    {
        $criado = $this->servico->criar('Ana Lima', 'ana@exemplo.com', ['consulta']);

        $casos = [
            ['errada', 'uma frase longa e boa', 'uma frase longa e boa', 'senha_atual'],
            [$criado['senha'], 'curta', 'curta', 'nova_senha'],
            [$criado['senha'], 'uma frase longa e boa', 'outra coisa qualquer', 'confirmacao'],
        ];

        foreach ($casos as [$atual, $nova, $confirmacao, $campo]) {
            try {
                $this->servico->trocarPropriaSenha($criado['id'], $atual, $nova, $confirmacao);
                self::fail("Deveria recusar ({$campo}).");
            } catch (ErroValidacao $erro) {
                self::assertArrayHasKey($campo, $erro->erros);
            }
        }

        $nova = 'uma frase longa e boa';
        $this->servico->trocarPropriaSenha($criado['id'], $criado['senha'], $nova, $nova);
        self::assertFalse($this->usuarios->buscarPorId($criado['id'])['deve_trocar_senha']);
    }
}
