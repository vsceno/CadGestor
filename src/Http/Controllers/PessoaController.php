<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Paginacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\PessoaRepositorio;
use CadGestor\Repositorios\VinculoRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\EmpresaServico;
use CadGestor\Servicos\PessoaServico;
use Closure;

/**
 * Cadastro de pessoas. A busca por nome/CPF é feita por POST para que dados
 * pessoais nunca apareçam na URL (histórico do navegador, logs do servidor).
 */
final class PessoaController extends Controlador
{
    /**
     * @param (Closure(string): array<string, mixed>)|null $dadosLgpd painel de LGPD do detalhe (Fase 6)
     */
    public function __construct(
        Sessao $sessao,
        private readonly PessoaRepositorio $pessoas,
        private readonly VinculoRepositorio $vinculos,
        private readonly PessoaServico $servico,
        private readonly ?Closure $dadosLgpd = null,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        // Com busca (POST) mostramos até 100 resultados numa página só; a
        // paginação por link (GET) só vale para a listagem sem filtro.
        $busca = $requisicao->metodo === 'POST' ? mb_substr($requisicao->entrada('busca'), 0, 100) : '';

        [, $total] = $this->pessoas->pesquisar($busca, 1, 0);
        $paginacao = $busca === ''
            ? Paginacao::daRequisicao($requisicao, $total)
            : new Paginacao(1, $total, 100);
        [$linhas] = $this->pessoas->pesquisar($busca, $paginacao->porPagina, $paginacao->deslocamento());

        return $this->pagina('pessoas/index', [
            'titulo' => 'Pessoas',
            'pessoas' => $linhas,
            'busca' => $busca,
            'paginacao' => $paginacao,
        ]);
    }

    /** @param array<string, string> $parametros */
    public function novo(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->formulario(null, [], []);
    }

    /** @param array<string, string> $parametros */
    public function criar(Requisicao $requisicao, array $parametros): Resposta
    {
        $entrada = $this->entrada($requisicao);

        try {
            $id = $this->servico->criar($entrada);
        } catch (ErroValidacao $erro) {
            return $this->formulario(null, $entrada, $erro->erros, 422);
        }

        return $this->redirecionar("/pessoas/{$id}", 'Pessoa cadastrada. Adicione o vínculo com a empresa.');
    }

    /** @param array<string, string> $parametros */
    public function ver(Requisicao $requisicao, array $parametros): Resposta
    {
        $pessoa = $this->pessoas->buscar($parametros['id']);

        if ($pessoa === null) {
            return $this->naoEncontrado();
        }

        return $this->pagina('pessoas/detalhe', [
            'titulo' => (string) $pessoa['nome'],
            'pessoa' => $pessoa,
            'vinculos' => $this->vinculos->daPessoa($parametros['id']),
            'opcoesVinculo' => $this->vinculos->opcoesEmpresaPerfil(),
            'lgpd' => $this->dadosLgpd === null ? null : ($this->dadosLgpd)($parametros['id']),
        ]);
    }

    /** @param array<string, string> $parametros */
    public function editar(Requisicao $requisicao, array $parametros): Resposta
    {
        $pessoa = $this->pessoas->buscar($parametros['id']);

        if ($pessoa === null) {
            return $this->naoEncontrado();
        }

        if ($pessoa['anonimizada_em'] !== null) {
            return $this->redirecionar(
                "/pessoas/{$parametros['id']}",
                'Registro anonimizado não pode ser editado.',
                'erro',
            );
        }

        return $this->formulario($parametros['id'], $pessoa, []);
    }

    /** @param array<string, string> $parametros */
    public function atualizar(Requisicao $requisicao, array $parametros): Resposta
    {
        $entrada = $this->entrada($requisicao);

        try {
            $this->servico->atualizar($parametros['id'], $entrada);
        } catch (ErroValidacao $erro) {
            return $this->formulario($parametros['id'], $entrada, $erro->erros, 422);
        }

        return $this->redirecionar("/pessoas/{$parametros['id']}", 'Cadastro atualizado.');
    }

    /** @param array<string, string> $parametros */
    public function alterarSituacao(Requisicao $requisicao, array $parametros): Resposta
    {
        $ativar = $requisicao->entrada('ativo') === '1';

        try {
            $this->servico->definirAtivo($parametros['id'], $ativar);
        } catch (ErroValidacao $erro) {
            return $this->redirecionar("/pessoas/{$parametros['id']}", $erro->getMessage(), 'erro');
        }

        return $this->redirecionar(
            "/pessoas/{$parametros['id']}",
            $ativar ? 'Pessoa reativada.' : 'Pessoa inativada: o acesso é negado em todas as catracas.',
        );
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(?string $id, array $dados, array $erros, int $status = 200): Resposta
    {
        return $this->pagina('pessoas/formulario', [
            'titulo' => $id === null ? 'Nova pessoa' : 'Editar pessoa',
            'id' => $id,
            'dados' => $dados,
            'erros' => $erros,
            'tiposDocumento' => PessoaServico::TIPOS_DOCUMENTO,
            'ufs' => EmpresaServico::UFS,
        ], $status);
    }

    /** @return array<string, string> */
    private function entrada(Requisicao $requisicao): array
    {
        $dados = [];

        foreach (PessoaRepositorio::CAMPOS as $campo) {
            $dados[$campo] = $requisicao->entrada($campo);
        }

        return $dados;
    }
}
