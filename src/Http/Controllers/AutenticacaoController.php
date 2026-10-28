<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\LimitadorTentativas;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\RecuperacaoContaServico;
use CadGestor\Servicos\UsuarioServico;

/**
 * Login, logout, troca da própria senha e recuperação de acesso
 * ("esqueci minha senha" e reenvio da confirmação de e-mail).
 */
final class AutenticacaoController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly Autenticacao $autenticacao,
        private readonly UsuarioServico $usuarios,
        private readonly RecuperacaoContaServico $recuperacao,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function formulario(Requisicao $requisicao, array $parametros): Resposta
    {
        if ($this->autenticacao->usuario() !== null) {
            return Resposta::redirecionar('/painel');
        }

        return $this->pagina('autenticacao/login', ['titulo' => 'Entrar', 'email' => '']);
    }

    /** @param array<string, string> $parametros */
    public function entrar(Requisicao $requisicao, array $parametros): Resposta
    {
        $email = $requisicao->entrada('email');
        $resultado = $this->autenticacao->entrar($email, (string) ($requisicao->corpo['senha'] ?? ''));

        if ($resultado === Autenticacao::OK) {
            return Resposta::redirecionar('/painel');
        }

        $mensagem = match ($resultado) {
            Autenticacao::BLOQUEADO => 'Muitas tentativas sem sucesso. Aguarde ' . LimitadorTentativas::JANELA_MINUTOS
                . ' minutos e tente novamente.',
            Autenticacao::EMAIL_NAO_CONFIRMADO => 'Confirme seu e-mail pelo link que enviamos antes de entrar.',
            default => 'E-mail ou senha inválidos.',
        };

        return $this->pagina('autenticacao/login', [
            'titulo' => 'Entrar',
            'email' => $email,
            'erroLogin' => $mensagem,
            'podeReenviar' => $resultado === Autenticacao::EMAIL_NAO_CONFIRMADO,
        ], $resultado === Autenticacao::BLOQUEADO ? 429 : 401);
    }

    /** @param array<string, string> $parametros */
    public function sair(Requisicao $requisicao, array $parametros): Resposta
    {
        $this->autenticacao->sair();

        return $this->redirecionar('/login', 'Você saiu do sistema.');
    }

    /** @param array<string, string> $parametros */
    public function formularioSenha(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->pagina('autenticacao/senha', ['titulo' => 'Trocar senha', 'erros' => []]);
    }

    /** @param array<string, string> $parametros */
    public function trocarSenha(Requisicao $requisicao, array $parametros): Resposta
    {
        $usuario = $this->autenticacao->usuario();

        try {
            $this->usuarios->trocarPropriaSenha(
                (string) $usuario?->id,
                (string) ($requisicao->corpo['senha_atual'] ?? ''),
                (string) ($requisicao->corpo['nova_senha'] ?? ''),
                (string) ($requisicao->corpo['confirmacao'] ?? ''),
            );
        } catch (ErroValidacao $erro) {
            return $this->pagina('autenticacao/senha', ['titulo' => 'Trocar senha', 'erros' => $erro->erros], 422);
        }

        // Novo ID de sessão após mudança de credencial; as outras sessões abertas caem.
        $this->sessao->regenerar();
        $this->autenticacao->manterSessaoAposTrocarSenha();

        return $this->redirecionar(
            '/painel',
            'Senha alterada com sucesso. Sessões abertas em outros aparelhos foram encerradas.',
        );
    }

    // --- Recuperação de acesso -------------------------------------------------

    /** @param array<string, string> $parametros */
    public function formularioEsqueci(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->pagina('autenticacao/esqueci', ['titulo' => 'Esqueci minha senha', 'enviado' => false]);
    }

    /**
     * A resposta é sempre a mesma, exista ou não o e-mail: assim a tela não
     * serve para descobrir quem tem conta.
     *
     * @param array<string, string> $parametros
     */
    public function solicitarRedefinicao(Requisicao $requisicao, array $parametros): Resposta
    {
        if (!$this->recuperacao->solicitarRedefinicao($requisicao->entrada('email'), $requisicao->ip())) {
            return $this->pagina('autenticacao/esqueci', [
                'titulo' => 'Esqueci minha senha',
                'enviado' => false,
                'erro' => 'Muitos pedidos em pouco tempo. Aguarde uma hora e tente novamente.',
            ], 429);
        }

        return $this->pagina('autenticacao/esqueci', ['titulo' => 'Esqueci minha senha', 'enviado' => true]);
    }

    /**
     * Abre o formulário a partir do link do e-mail. O token sai da URL e
     * segue num campo oculto do formulário (não fica no histórico de
     * páginas seguintes nem é repassado a outros endereços).
     *
     * @param array<string, string> $parametros
     */
    public function formularioRedefinir(Requisicao $requisicao, array $parametros): Resposta
    {
        $token = $requisicao->consulta('token');

        if (!$this->recuperacao->linkValido($token)) {
            return $this->pagina('autenticacao/redefinir', ['titulo' => 'Link inválido', 'token' => null], 400);
        }

        return $this->pagina('autenticacao/redefinir', [
            'titulo' => 'Criar nova senha',
            'token' => $token,
            'erros' => [],
        ]);
    }

    /** @param array<string, string> $parametros */
    public function redefinirSenha(Requisicao $requisicao, array $parametros): Resposta
    {
        $token = $requisicao->entrada('token');

        try {
            $this->recuperacao->redefinir(
                $token,
                (string) ($requisicao->corpo['senha'] ?? ''),
                (string) ($requisicao->corpo['confirmacao'] ?? ''),
                $requisicao->ip(),
            );
        } catch (ErroValidacao $erro) {
            if (isset($erro->erros['token'])) {
                return $this->pagina('autenticacao/redefinir', ['titulo' => 'Link inválido', 'token' => null], 400);
            }

            return $this->pagina('autenticacao/redefinir', [
                'titulo' => 'Criar nova senha',
                'token' => $token,
                'erros' => $erro->erros,
            ], 422);
        }

        // Quem estava logado neste navegador sai: a sessão antiga não vale mais.
        $this->autenticacao->sair();

        return $this->redirecionar('/login', 'Senha criada. Entre com o seu e-mail e a nova senha.');
    }

    /** @param array<string, string> $parametros */
    public function reenviarConfirmacao(Requisicao $requisicao, array $parametros): Resposta
    {
        $usuarioId = $this->autenticacao->contaAguardandoConfirmacao();

        if ($usuarioId === null) {
            return $this->redirecionar('/login', 'Entre com e-mail e senha para pedir um novo link.', 'aviso');
        }

        if (!$this->recuperacao->reenviarConfirmacaoPeloLogin($usuarioId, $requisicao->ip())) {
            return $this->redirecionar('/login', 'Limite de reenvios atingido. Tente novamente em uma hora.', 'erro');
        }

        $this->autenticacao->esquecerContaAguardandoConfirmacao();

        return $this->redirecionar('/login', 'Enviamos um novo link de confirmação para o seu e-mail.');
    }
}
