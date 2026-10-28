<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Nucleo\Contexto;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Csrf;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Seguranca\LimitadorTentativas;
use CadGestor\Seguranca\Papel;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\RecuperacaoContaServico;

/**
 * "Esqueci minha senha", link de redefinição, sessões derrubadas na troca de
 * senha, desbloqueio do login e reenvio da confirmação de e-mail.
 */
final class RecuperacaoContaTest extends BancoTestCase
{
    private const SENHA = 'frase de teste bem longa';
    private const NOVA = 'outra frase longa e diferente';

    private RecuperacaoContaServico $servico;
    private UsuarioRepositorio $usuarios;
    private LimitadorTentativas $limitador;
    private HashSenha $hash;
    private CaixaDeEmails $emails;
    private string $usuarioId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuarios = new UsuarioRepositorio($this->pdo);
        $this->limitador = new LimitadorTentativas($this->pdo, 'segredo-de-teste');
        $this->hash = new HashSenha();
        $this->emails = new CaixaDeEmails();
        $this->servico = new RecuperacaoContaServico(
            $this->pdo,
            $this->usuarios,
            new OrganizacaoRepositorio($this->pdo),
            new ContextoBanco($this->pdo),
            $this->hash,
            $this->limitador,
            $this->emails,
            new Auditoria($this->pdo, new Contexto('10.0.0.1')),
            'http://cadgestor.test',
        );

        $this->usuarioId = $this->usuarios->inserir('Operador Teste', 'operador@exemplo.com', $this->hash->gerar(self::SENHA), false);
        $this->usuarios->definirPapeis($this->usuarioId, [Papel::Administrador]);
    }

    public function testEmailInexistenteNaoEnviaNadaEARespostaEAMesma(): void
    {
        self::assertTrue($this->servico->solicitarRedefinicao('ninguem@exemplo.com', '10.0.0.1'));
        self::assertTrue($this->servico->solicitarRedefinicao('nao-e-email', '10.0.0.1'));
        self::assertSame([], $this->emails->enviados);
    }

    public function testLinkGuardaSoOHashEUsaUmaVezSo(): void
    {
        self::assertTrue($this->servico->solicitarRedefinicao(' OPERADOR@exemplo.com ', '10.0.0.1'));
        $token = $this->emails->ultimoToken('redefinir-senha');
        self::assertNotNull($token);
        self::assertSame('operador@exemplo.com', $this->emails->enviados[0]['para']);
        self::assertSame(hash('sha256', $token), $this->coluna('redefinicao_token_hash'));
        self::assertTrue($this->servico->linkValido($token));

        $this->servico->redefinir($token, self::NOVA, self::NOVA, '10.0.0.1');

        self::assertTrue($this->hash->verificar(self::NOVA, (string) $this->coluna('senha_hash')));
        self::assertNull($this->coluna('redefinicao_token_hash'));
        self::assertSame(2, (int) $this->coluna('credenciais_versao'));
        self::assertFalse($this->servico->linkValido($token), 'O link não pode ser reutilizado.');

        $this->expectException(ErroValidacao::class);
        $this->servico->redefinir($token, 'mais uma frase longa qualquer', 'mais uma frase longa qualquer', '10.0.0.1');
    }

    public function testLinkExpiradoOuDeUsuarioInativoNaoVale(): void
    {
        $this->servico->solicitarRedefinicao('operador@exemplo.com', '10.0.0.1');
        $token = (string) $this->emails->ultimoToken('redefinir-senha');
        $this->pdo->prepare("UPDATE usuario_sistema SET redefinicao_expira_em = now() - interval '1 minute' WHERE id = :id")
            ->execute(['id' => $this->usuarioId]);
        self::assertFalse($this->servico->linkValido($token));

        $this->servico->solicitarRedefinicao('operador@exemplo.com', '10.0.0.2');
        $token = (string) $this->emails->ultimoToken('redefinir-senha');
        $this->usuarios->definirAtivo($this->usuarioId, false);
        self::assertFalse($this->servico->linkValido($token));
    }

    public function testNovaSenhaPassaPelaPoliticaDeSenha(): void
    {
        $this->servico->solicitarRedefinicao('operador@exemplo.com', '10.0.0.1');
        $token = (string) $this->emails->ultimoToken('redefinir-senha');

        try {
            $this->servico->redefinir($token, 'curta', 'curta', '10.0.0.1');
            self::fail('Senha curta deveria ser recusada.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('senha', $erro->erros);
        }

        try {
            $this->servico->redefinir($token, self::NOVA, 'outra coisa', '10.0.0.1');
            self::fail('Confirmação diferente deveria ser recusada.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('confirmacao', $erro->erros);
        }

        self::assertTrue($this->servico->linkValido($token), 'Erro de digitação não gasta o link.');
    }

    public function testPedidosSaoLimitadosPorEmail(): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($this->servico->solicitarRedefinicao('operador@exemplo.com', '10.0.0.' . $i));
        }

        self::assertFalse($this->servico->solicitarRedefinicao('operador@exemplo.com', '10.0.0.9'));
        self::assertCount(3, $this->emails->enviados);
    }

    public function testTrocaDeSenhaDerrubaSessoesAbertas(): void
    {
        $sessaoA = Sessao::emMemoria();
        $sessaoB = Sessao::emMemoria();
        $autenticacaoA = $this->autenticacao($sessaoA);
        $autenticacaoB = $this->autenticacao($sessaoB);
        self::assertSame(Autenticacao::OK, $autenticacaoA->entrar('operador@exemplo.com', self::SENHA));
        self::assertSame(Autenticacao::OK, $autenticacaoB->entrar('operador@exemplo.com', self::SENHA));

        // Senha redefinida pelo link: as duas sessões caem.
        $this->servico->solicitarRedefinicao('operador@exemplo.com', '10.0.0.1');
        $this->servico->redefinir((string) $this->emails->ultimoToken('redefinir-senha'), self::NOVA, self::NOVA, '10.0.0.1');
        $autenticacaoA->recarregar();
        $autenticacaoB->recarregar();
        self::assertNull($autenticacaoA->usuario());
        self::assertNull($autenticacaoB->usuario());

        // Troca pela própria tela: a sessão de quem trocou continua; a outra cai.
        $autenticacaoA = $this->autenticacao(Sessao::emMemoria());
        $sessaoB = Sessao::emMemoria();
        $autenticacaoB = $this->autenticacao($sessaoB);
        $autenticacaoA->entrar('operador@exemplo.com', self::NOVA);
        $autenticacaoB->entrar('operador@exemplo.com', self::NOVA);
        $this->usuarios->definirSenha($this->usuarioId, $this->hash->gerar(self::SENHA), false);
        $autenticacaoA->manterSessaoAposTrocarSenha();
        $autenticacaoB->recarregar();
        self::assertNotNull($autenticacaoA->usuario());
        self::assertNull($autenticacaoB->usuario());
    }

    public function testRedefinirPeloLinkLiberaLoginTravado(): void
    {
        $autenticacao = $this->autenticacao(Sessao::emMemoria());

        for ($i = 0; $i < LimitadorTentativas::MAXIMO_POR_EMAIL; $i++) {
            $autenticacao->entrar('operador@exemplo.com', 'errada');
        }

        self::assertTrue($this->limitador->emailBloqueado('operador@exemplo.com'));
        $this->servico->solicitarRedefinicao('operador@exemplo.com', '10.0.0.1');
        $this->servico->redefinir((string) $this->emails->ultimoToken('redefinir-senha'), self::NOVA, self::NOVA, '10.0.0.2');

        self::assertFalse($this->limitador->emailBloqueado('operador@exemplo.com'));
        self::assertSame(Autenticacao::OK, $autenticacao->entrar('operador@exemplo.com', self::NOVA));
    }

    public function testReenvioDaConfirmacaoSoParaQuemAcertouASenha(): void
    {
        $this->pdo->prepare('UPDATE usuario_sistema SET email_verificado_em = NULL WHERE id = :id')
            ->execute(['id' => $this->usuarioId]);
        $sessao = Sessao::emMemoria();
        $autenticacao = $this->autenticacao($sessao);

        self::assertSame(Autenticacao::FALHA, $autenticacao->entrar('operador@exemplo.com', 'errada'));
        self::assertNull($autenticacao->contaAguardandoConfirmacao(), 'Senha errada não libera o reenvio.');

        self::assertSame(Autenticacao::EMAIL_NAO_CONFIRMADO, $autenticacao->entrar('operador@exemplo.com', self::SENHA));
        $usuarioId = $autenticacao->contaAguardandoConfirmacao();
        self::assertSame($this->usuarioId, $usuarioId);

        self::assertTrue($this->servico->reenviarConfirmacaoPeloLogin($usuarioId, '10.0.0.1'));
        $token = $this->emails->ultimoToken('confirmar-email');
        self::assertNotNull($token);
        self::assertSame(hash('sha256', $token), $this->coluna('verificacao_token_hash'));
    }

    private function autenticacao(Sessao $sessao): Autenticacao
    {
        $contexto = new Contexto('10.0.0.1');

        return new Autenticacao(
            $this->usuarios,
            new OrganizacaoRepositorio($this->pdo),
            new ContextoBanco($this->pdo),
            $sessao,
            new Csrf($sessao),
            $this->hash,
            $this->limitador,
            new Auditoria($this->pdo, $contexto),
            $contexto,
        );
    }

    private function coluna(string $coluna): mixed
    {
        $consulta = $this->pdo->prepare("SELECT {$coluna} FROM usuario_sistema WHERE id = :id");
        $consulta->execute(['id' => $this->usuarioId]);

        return $consulta->fetchColumn();
    }
}
