<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\LimitadorTentativas;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\UsuarioServico;

/**
 * Login, logout e troca da própria senha.
 */
final class AutenticacaoController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly Autenticacao $autenticacao,
        private readonly UsuarioServico $usuarios,
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

        $mensagem = $resultado === Autenticacao::BLOQUEADO
            ? 'Muitas tentativas sem sucesso. Aguarde ' . LimitadorTentativas::JANELA_MINUTOS
                . ' minutos e tente novamente.'
            : 'E-mail ou senha inválidos.';

        return $this->pagina('autenticacao/login', [
            'titulo' => 'Entrar',
            'email' => $email,
            'erroLogin' => $mensagem,
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

        // Novo ID de sessão após mudança de credencial.
        $this->sessao->regenerar();
        $this->autenticacao->recarregar();

        return $this->redirecionar('/painel', 'Senha alterada com sucesso.');
    }
}
