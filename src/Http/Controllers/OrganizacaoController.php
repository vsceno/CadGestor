<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Dominio\SituacaoAssinatura;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\OrganizacaoServico;
use DateTimeImmutable;

/**
 * Painel comercial da plataforma: clientes (organizações), plano, situação da
 * assinatura e faturas. A plataforma NÃO entra no ambiente do cliente: daqui
 * só se veem contagens e dados comerciais.
 */
final class OrganizacaoController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly OrganizacaoRepositorio $organizacoes,
        private readonly UsuarioRepositorio $usuarios,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly OrganizacaoServico $servico,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        $hoje = new DateTimeImmutable('today');
        $assinaturas = $this->assinaturas->resumoPorOrganizacao();
        $organizacoes = [];
        $indicadores = ['receita_mensal' => 0, 'ativas' => 0, 'teste' => 0, 'inadimplentes' => 0, 'canceladas' => 0];

        foreach ($this->organizacoes->listarComResumo() as $o) {
            $a = $assinaturas[$o['id']] ?? null;
            $o['assinatura'] = $a;
            $o['situacao'] = $a === null
                ? SituacaoAssinatura::semAssinatura()
                : SituacaoAssinatura::calcular($a['status'], $a['teste_ate'], $a['inadimplente_desde'], $hoje);
            $organizacoes[] = $o;

            match ($a['status'] ?? null) {
                'ativa' => $indicadores['ativas']++,
                'teste' => $indicadores['teste']++,
                'inadimplente' => $indicadores['inadimplentes']++,
                'cancelada' => $indicadores['canceladas']++,
                default => null,
            };

            if (in_array($a['status'] ?? null, ['ativa', 'inadimplente'], true) && $o['ativo']) {
                $indicadores['receita_mensal'] += (int) $a['preco_centavos'];
            }
        }

        return $this->pagina('organizacoes/index', [
            'titulo' => 'Clientes',
            'organizacoes' => $organizacoes,
            'indicadores' => $indicadores,
        ]);
    }

    /** @param array<string, string> $parametros */
    public function novo(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->formulario(null, ['nome' => '', 'plano_id' => ''], []);
    }

    /** @param array<string, string> $parametros */
    public function criar(Requisicao $requisicao, array $parametros): Resposta
    {
        $dados = ['nome' => $requisicao->entrada('nome'), 'plano_id' => $requisicao->entrada('plano_id')];

        try {
            $id = $this->servico->criar($dados['nome'], $dados['plano_id']);
        } catch (ErroValidacao $erro) {
            return $this->formulario(null, $dados, $erro->erros, 422);
        }

        return $this->redirecionar(
            '/organizacoes/' . $id,
            'Cliente criado com a assinatura ativa. Agora cadastre o administrador dele.',
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

        return $this->redirecionar('/organizacoes/' . $parametros['id'], 'Cliente atualizado.');
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
            ? 'Cliente reativado.'
            : 'Cliente bloqueado: os usuários dele não entram e as catracas são recusadas.');
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
     * @param array<string, string> $novoAdmin
     * @param array<string, string> $erros
     */
    private function detalhe(string $id, array $novoAdmin, array $erros, int $status = 200): Resposta
    {
        $organizacao = $this->organizacoes->buscar($id);

        if ($organizacao === null) {
            return $this->naoEncontrado();
        }

        $assinatura = $this->assinaturas->daOrganizacao($id);

        return $this->pagina('organizacoes/detalhe', [
            'titulo' => (string) $organizacao['nome'],
            'organizacao' => $organizacao,
            'assinatura' => $assinatura,
            'situacao' => $assinatura === null ? SituacaoAssinatura::semAssinatura() : SituacaoAssinatura::calcular(
                (string) $assinatura['status'],
                $assinatura['teste_ate'],
                $assinatura['inadimplente_desde'],
                new DateTimeImmutable('today'),
            ),
            'faturas' => $this->assinaturas->faturas($id),
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
            'titulo' => $id === null ? 'Novo cliente (venda manual)' : 'Editar cliente',
            'id' => $id,
            'dados' => $dados,
            'erros' => $erros,
            'planos' => $this->assinaturas->planos(),
        ], $status);
    }
}
