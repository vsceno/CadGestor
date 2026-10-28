<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\OrganizacaoServico;

/**
 * Painel da plataforma: organizações (clientes), seus administradores e o
 * modo suporte (entrar numa organização).
 */
final class OrganizacaoController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly OrganizacaoRepositorio $organizacoes,
        private readonly UsuarioRepositorio $usuarios,
        private readonly OrganizacaoServico $servico,
        private readonly Autenticacao $autenticacao,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->pagina('organizacoes/index', [
            'titulo' => 'Organizações',
            'organizacoes' => $this->organizacoes->listarComResumo(),
        ]);
    }

    /** @param array<string, string> $parametros */
    public function novo(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->formulario(null, ['nome' => ''], []);
    }

    /** @param array<string, string> $parametros */
    public function criar(Requisicao $requisicao, array $parametros): Resposta
    {
        $nome = $requisicao->entrada('nome');

        try {
            $id = $this->servico->criar($nome);
        } catch (ErroValidacao $erro) {
            return $this->formulario(null, ['nome' => $nome], $erro->erros, 422);
        }

        return $this->redirecionar(
            '/organizacoes/' . $id,
            'Organização criada com os perfis padrão e o termo modelo. Agora cadastre o administrador dela.',
        );
    }

    /** @param array<string, string> $parametros */
    public function ver(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->detalhe($parametros['id'], ['nome' => '', 'email' => ''], []);
    }

    /** @param array<string, string> $parametros */
    public function editar(Requisicao $requisicao, array $parametros): Resposta
    {
        $organizacao = $this->organizacoes->buscar($parametros['id']);

        return $organizacao === null ? $this->naoEncontrado() : $this->formulario($parametros['id'], $organizacao, []);
    }

    /** @param array<string, string> $parametros */
    public function atualizar(Requisicao $requisicao, array $parametros): Resposta
    {
        $nome = $requisicao->entrada('nome');

        try {
            $this->servico->atualizar($parametros['id'], $nome);
        } catch (ErroValidacao $erro) {
            return isset($erro->erros['id'])
                ? $this->naoEncontrado()
                : $this->formulario($parametros['id'], ['nome' => $nome], $erro->erros, 422);
        }

        return $this->redirecionar('/organizacoes/' . $parametros['id'], 'Organização atualizada.');
    }

    /** @param array<string, string> $parametros */
    public function alterarSituacao(Requisicao $requisicao, array $parametros): Resposta
    {
        $ativo = $requisicao->entrada('ativo') === '1';

        try {
            $this->servico->alterarSituacao($parametros['id'], $ativo);
        } catch (ErroValidacao) {
            return $this->naoEncontrado();
        }

        return $this->redirecionar('/organizacoes/' . $parametros['id'], $ativo
            ? 'Organização reativada.'
            : 'Organização desativada: seus usuários não entram e suas catracas são recusadas.');
    }

    /** @param array<string, string> $parametros */
    public function criarAdministrador(Requisicao $requisicao, array $parametros): Resposta
    {
        $dados = ['nome' => $requisicao->entrada('nome'), 'email' => $requisicao->entrada('email')];

        try {
            $resultado = $this->servico->criarAdministrador($parametros['id'], $dados['nome'], $dados['email']);
        } catch (ErroValidacao $erro) {
            return isset($erro->erros['id'])
                ? $this->naoEncontrado()
                : $this->detalhe($parametros['id'], $dados, $erro->erros, 422);
        }

        return $this->pagina('usuarios/senha-gerada', [
            'titulo' => 'Administrador criado',
            'senha' => $resultado['senha'],
            'emailUsuario' => $dados['email'],
            'voltarPara' => '/organizacoes/' . $parametros['id'],
        ]);
    }

    /**
     * Modo suporte: passa a operar dentro da organização (registrado na
     * auditoria dela).
     *
     * @param array<string, string> $parametros
     */
    public function entrar(Requisicao $requisicao, array $parametros): Resposta
    {
        if (!$this->autenticacao->entrarNaOrganizacao($parametros['id'])) {
            return $this->naoEncontrado();
        }

        return $this->redirecionar('/painel', 'Modo suporte: você está dentro da organização. '
            . 'Tudo o que fizer fica registrado na auditoria dela.', 'aviso');
    }

    /** @param array<string, string> $parametros */
    public function sairDoSuporte(Requisicao $requisicao, array $parametros): Resposta
    {
        $this->autenticacao->sairDaOrganizacao();

        return $this->redirecionar('/organizacoes', 'Você saiu do modo suporte.');
    }

    /**
     * @param array<string, string> $novoAdmin
     * @param array<string, string> $erros
     */
    private function detalhe(string $id, array $novoAdmin, array $erros, int $status = 200): Resposta
    {
        $organizacao = $this->organizacoes->buscar($id);

        if ($organizacao === null) {
            return $this->naoEncontrado();
        }

        return $this->pagina('organizacoes/detalhe', [
            'titulo' => (string) $organizacao['nome'],
            'organizacao' => $organizacao,
            'usuarios' => $this->usuarios->listar($id),
            'novoAdmin' => $novoAdmin,
            'erros' => $erros,
        ], $status);
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(?string $id, array $dados, array $erros, int $status = 200): Resposta
    {
        return $this->pagina('organizacoes/formulario', [
            'titulo' => $id === null ? 'Nova organização' : 'Editar organização',
            'id' => $id,
            'dados' => $dados,
            'erros' => $erros,
        ], $status);
    }
}
