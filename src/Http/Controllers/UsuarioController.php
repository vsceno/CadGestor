<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\UsuarioServico;

/**
 * Administração dos usuários do sistema (somente administrador).
 */
final class UsuarioController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly UsuarioRepositorio $repositorio,
        private readonly UsuarioServico $servico,
        private readonly Autenticacao $autenticacao,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->pagina('usuarios/index', [
            'titulo' => 'Usuários do sistema',
            'usuarios' => $this->repositorio->listar(),
        ]);
    }

    /** @param array<string, string> $parametros */
    public function novo(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->formulario(null, ['nome' => '', 'email' => '', 'papeis' => [], 'ativo' => true], []);
    }

    /** @param array<string, string> $parametros */
    public function criar(Requisicao $requisicao, array $parametros): Resposta
    {
        $dados = $this->dadosDoFormulario($requisicao);

        try {
            $resultado = $this->servico->criar($dados['nome'], $dados['email'], $dados['papeis']);
        } catch (ErroValidacao $erro) {
            return $this->formulario(null, $dados, $erro->erros, 422);
        }

        return $this->senhaGerada($resultado['senha'], $dados['email'], 'Usuário criado');
    }

    /** @param array<string, string> $parametros */
    public function editar(Requisicao $requisicao, array $parametros): Resposta
    {
        $usuario = $this->repositorio->buscarPorId($parametros['id']);

        if ($usuario === null) {
            return $this->naoEncontrado();
        }

        return $this->formulario($parametros['id'], $usuario, []);
    }

    /** @param array<string, string> $parametros */
    public function atualizar(Requisicao $requisicao, array $parametros): Resposta
    {
        $dados = $this->dadosDoFormulario($requisicao);

        try {
            $this->servico->atualizar(
                $parametros['id'],
                $dados['nome'],
                $dados['email'],
                $dados['papeis'],
                $dados['ativo'],
                (string) $this->autenticacao->usuario()?->id,
            );
        } catch (ErroValidacao $erro) {
            return $this->formulario($parametros['id'], $dados, $erro->erros, 422);
        }

        return $this->redirecionar('/usuarios', 'Usuário atualizado.');
    }

    /** @param array<string, string> $parametros */
    public function redefinirSenha(Requisicao $requisicao, array $parametros): Resposta
    {
        $usuario = $this->repositorio->buscarPorId($parametros['id']);

        if ($usuario === null) {
            return $this->naoEncontrado();
        }

        $senha = $this->servico->redefinirSenha($parametros['id']);

        return $this->senhaGerada($senha, (string) $usuario['email'], 'Senha redefinida');
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(?string $id, array $dados, array $erros, int $status = 200): Resposta
    {
        return $this->pagina('usuarios/formulario', [
            'titulo' => $id === null ? 'Novo usuário' : 'Editar usuário',
            'id' => $id,
            'dados' => $dados,
            'erros' => $erros,
        ], $status);
    }

    /** A senha é exibida nesta resposta e nunca mais (não vai para URL nem sessão). */
    private function senhaGerada(string $senha, string $email, string $titulo): Resposta
    {
        return $this->pagina('usuarios/senha-gerada', [
            'titulo' => $titulo,
            'senha' => $senha,
            'emailUsuario' => $email,
        ]);
    }

    /** @return array{nome: string, email: string, papeis: list<string>, ativo: bool} */
    private function dadosDoFormulario(Requisicao $requisicao): array
    {
        return [
            'nome' => $requisicao->entrada('nome'),
            'email' => $requisicao->entrada('email'),
            'papeis' => $requisicao->entradaLista('papeis'),
            'ativo' => $requisicao->entrada('ativo') === '1',
        ];
    }
}
