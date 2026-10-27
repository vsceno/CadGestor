<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Nucleo\Contexto;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Csrf;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Seguranca\LimitadorTentativas;
use CadGestor\Seguranca\Papel;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\Auditoria;

final class AutenticacaoTest extends BancoTestCase
{
    private const SENHA = 'frase de teste bem longa';

    private Sessao $sessao;
    private Autenticacao $autenticacao;
    private UsuarioRepositorio $usuarios;
    private string $usuarioId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessao = Sessao::emMemoria();
        $this->usuarios = new UsuarioRepositorio($this->pdo);
        $contexto = new Contexto('10.0.0.1');
        $hash = new HashSenha();

        $this->autenticacao = new Autenticacao(
            $this->usuarios,
            $this->sessao,
            new Csrf($this->sessao),
            $hash,
            new LimitadorTentativas($this->pdo, 'segredo-de-teste'),
            new Auditoria($this->pdo, $contexto),
            $contexto,
        );

        $this->usuarioId = $this->usuarios->inserir(
            'Operador Teste',
            'operador@exemplo.com',
            $hash->gerar(self::SENHA),
            false,
        );
        $this->usuarios->definirPapeis($this->usuarioId, [Papel::CadastroPessoa]);
    }

    public function testLoginComSenhaCorretaAbreSessao(): void
    {
        self::assertSame(Autenticacao::OK, $this->autenticacao->entrar('OPERADOR@exemplo.com', self::SENHA));
        self::assertSame($this->usuarioId, $this->sessao->obter('usuario_id'));

        $usuario = $this->autenticacao->usuario();
        self::assertNotNull($usuario);
        self::assertSame([Papel::CadastroPessoa], $usuario->papeis);
    }

    public function testSenhaErradaEEmailInexistenteDaoAMesmaResposta(): void
    {
        self::assertSame(Autenticacao::FALHA, $this->autenticacao->entrar('operador@exemplo.com', 'errada'));
        self::assertSame(Autenticacao::FALHA, $this->autenticacao->entrar('ninguem@exemplo.com', self::SENHA));
        self::assertNull($this->sessao->obter('usuario_id'));
    }

    public function testUsuarioInativoNaoEntra(): void
    {
        $this->usuarios->atualizar($this->usuarioId, 'Operador Teste', 'operador@exemplo.com', false);

        self::assertSame(Autenticacao::FALHA, $this->autenticacao->entrar('operador@exemplo.com', self::SENHA));
    }

    public function testBloqueiaAposCincoFalhasMesmoComSenhaCorreta(): void
    {
        for ($i = 0; $i < LimitadorTentativas::MAXIMO_POR_EMAIL; $i++) {
            $this->autenticacao->entrar('operador@exemplo.com', 'errada');
        }

        self::assertSame(Autenticacao::BLOQUEADO, $this->autenticacao->entrar('operador@exemplo.com', self::SENHA));
    }

    public function testTentativasGuardamHmacENaoOEmail(): void
    {
        $this->autenticacao->entrar('operador@exemplo.com', 'errada');

        $hash = (string) $this->pdo->query('SELECT email_hash FROM tentativa_login LIMIT 1')->fetchColumn();
        self::assertSame(64, strlen($hash));
        self::assertStringNotContainsString('operador', $hash);
        self::assertNotSame(hash('sha256', 'operador@exemplo.com'), $hash);
    }

    public function testUsuarioDesativadoPerdeASessaoNaProximaRequisicao(): void
    {
        $this->autenticacao->entrar('operador@exemplo.com', self::SENHA);
        $this->usuarios->atualizar($this->usuarioId, 'Operador Teste', 'operador@exemplo.com', false);
        $this->autenticacao->recarregar();

        self::assertNull($this->autenticacao->usuario());
    }

    public function testLoginERegistradoNaAuditoria(): void
    {
        $this->autenticacao->entrar('operador@exemplo.com', self::SENHA);

        $acoes = $this->pdo->query("SELECT acao FROM auditoria WHERE tabela = 'usuario_sistema' ORDER BY data_hora")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('login', $acoes);
    }
}
