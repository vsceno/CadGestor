<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Nucleo\Contexto;
use CadGestor\Repositorios\OrganizacaoRepositorio;
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
            new OrganizacaoRepositorio($this->pdo),
            new ContextoBanco($this->pdo),
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
    public function testLoginGuardaAOrganizacaoNaSessao(): void
    {
        $this->autenticacao->entrar('operador@exemplo.com', self::SENHA);

        self::assertSame(self::organizacaoTeste(), $this->sessao->obter('organizacao_id'));
        self::assertSame(self::organizacaoTeste(), $this->autenticacao->usuario()?->organizacaoId);
        self::assertSame('Organização de teste', $this->autenticacao->usuario()?->organizacaoNome);
    }

    public function testOrganizacaoDesativadaBloqueiaLoginESessaoAberta(): void
    {
        $this->autenticacao->entrar('operador@exemplo.com', self::SENHA);
        $this->pdo->prepare('UPDATE organizacao SET ativo = false WHERE id = :id')
            ->execute(['id' => self::organizacaoTeste()]);
        $this->autenticacao->recarregar();

        self::assertNull($this->autenticacao->usuario(), 'Sessão aberta cai quando a organização é suspensa.');
        self::assertSame(Autenticacao::FALHA, $this->autenticacao->entrar('operador@exemplo.com', self::SENHA));
    }

    public function testUsuarioDaPlataformaEntraForaDeOrganizacaoEUsaModoSuporte(): void
    {
        $id = $this->inserir(<<<'SQL'
            INSERT INTO usuario_sistema (organizacao_id, nome, email, senha_hash)
            VALUES (NULL, 'Plataforma Teste', 'plataforma@exemplo.com', :hash)
            RETURNING id
            SQL, ['hash' => (new HashSenha())->gerar(self::SENHA)]);
        $this->pdo->prepare("INSERT INTO usuario_papel (usuario_id, papel) VALUES (:id, 'plataforma')")
            ->execute(['id' => $id]);

        self::assertSame(Autenticacao::OK, $this->autenticacao->entrar('plataforma@exemplo.com', self::SENHA));
        $usuario = $this->autenticacao->usuario();
        self::assertTrue($usuario?->emModoPlataforma());
        self::assertSame([Papel::Plataforma], $usuario->papeis);

        self::assertTrue($this->autenticacao->entrarNaOrganizacao(self::organizacaoTeste()));
        $usuario = $this->autenticacao->usuario();
        self::assertTrue($usuario?->emModoSuporte());
        self::assertSame([Papel::Administrador], $usuario->papeis);

        // A entrada fica na auditoria da própria organização, visível ao cliente.
        $consulta = $this->pdo->prepare(
            "SELECT organizacao_id FROM auditoria WHERE acao = 'acesso_suporte' AND usuario_id = :u"
        );
        $consulta->execute(['u' => $id]);
        self::assertSame(self::organizacaoTeste(), $consulta->fetchColumn());

        $this->autenticacao->sairDaOrganizacao();
        self::assertTrue($this->autenticacao->usuario()?->emModoPlataforma());
    }

    public function testUsuarioDeOrganizacaoNaoEntraEmOutraOrganizacao(): void
    {
        $outra = $this->criarOrganizacao('Outra Organização');
        $this->autenticacao->entrar('operador@exemplo.com', self::SENHA);

        self::assertFalse($this->autenticacao->entrarNaOrganizacao($outra));

        // Sessão adulterada para outra organização: o usuário é desconectado.
        $this->sessao->definir('organizacao_id', $outra);
        $this->autenticacao->recarregar();
        self::assertNull($this->autenticacao->usuario());
    }
}
