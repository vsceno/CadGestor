<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Paginacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\CatracaRepositorio;
use CadGestor\Repositorios\EmpresaRepositorio;
use CadGestor\Repositorios\PerfilRepositorio;
use CadGestor\Repositorios\RegraAcessoRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\EmpresaServico;

/**
 * Cadastro de empresas, perfis aceitos e horários padrão.
 */
final class EmpresaController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly EmpresaRepositorio $empresas,
        private readonly PerfilRepositorio $perfis,
        private readonly CatracaRepositorio $catracas,
        private readonly RegraAcessoRepositorio $regras,
        private readonly EmpresaServico $servico,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        $busca = mb_substr($requisicao->consulta('busca'), 0, 100);
        $situacao = $requisicao->consulta('situacao', 'ativas');
        $ativo = match ($situacao) {
            'inativas' => false,
            'todas' => null,
            default => true,
        };

        [, $total] = $this->empresas->listar($busca, $ativo, 1, 0);
        $paginacao = Paginacao::daRequisicao($requisicao, $total);
        [$linhas] = $this->empresas->listar($busca, $ativo, $paginacao->porPagina, $paginacao->deslocamento());

        return $this->pagina('empresas/index', [
            'titulo' => 'Empresas',
            'empresas' => $linhas,
            'busca' => $busca,
            'situacao' => $situacao,
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

        return $this->redirecionar("/empresas/{$id}", 'Empresa cadastrada. Configure os perfis aceitos e os horários.');
    }

    /** @param array<string, string> $parametros */
    public function ver(Requisicao $requisicao, array $parametros): Resposta
    {
        $empresa = $this->empresas->buscar($parametros['id']);

        if ($empresa === null) {
            return $this->naoEncontrado();
        }

        return $this->pagina('empresas/detalhe', [
            'titulo' => (string) $empresa['razao_social'],
            'empresa' => $empresa,
            'perfis' => $this->perfis->listar(apenasAtivos: true),
            'perfisAceitos' => $this->empresas->perfisAceitos($parametros['id']),
            'horarios' => $this->empresas->horarios($parametros['id']),
            'catracas' => $this->catracas->daEmpresa($parametros['id']),
            'totalRegras' => $this->regras->contarDaEmpresa($parametros['id']),
            'erros' => [],
        ]);
    }

    /** @param array<string, string> $parametros */
    public function editar(Requisicao $requisicao, array $parametros): Resposta
    {
        $empresa = $this->empresas->buscar($parametros['id']);

        return $empresa === null ? $this->naoEncontrado() : $this->formulario($parametros['id'], $empresa, []);
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

        return $this->redirecionar("/empresas/{$parametros['id']}", 'Dados da empresa atualizados.');
    }

    /** @param array<string, string> $parametros */
    public function alterarSituacao(Requisicao $requisicao, array $parametros): Resposta
    {
        $ativar = $requisicao->entrada('ativo') === '1';
        $this->servico->definirAtivo($parametros['id'], $ativar);

        return $this->redirecionar(
            "/empresas/{$parametros['id']}",
            $ativar ? 'Empresa reativada.' : 'Empresa inativada. Suas catracas deixam de liberar acessos.',
        );
    }

    /** @param array<string, string> $parametros */
    public function salvarPerfis(Requisicao $requisicao, array $parametros): Resposta
    {
        if ($this->empresas->buscar($parametros['id']) === null) {
            return $this->naoEncontrado();
        }

        $this->servico->definirPerfis($parametros['id'], $requisicao->entradaLista('perfis'));

        return $this->redirecionar("/empresas/{$parametros['id']}#perfis", 'Perfis aceitos atualizados.');
    }

    /** @param array<string, string> $parametros */
    public function salvarHorarios(Requisicao $requisicao, array $parametros): Resposta
    {
        if ($this->empresas->buscar($parametros['id']) === null) {
            return $this->naoEncontrado();
        }

        try {
            $this->servico->definirHorarios(
                $parametros['id'],
                $requisicao->entradaLista('dia'),
                $requisicao->entradaLista('inicio'),
                $requisicao->entradaLista('fim'),
            );
        } catch (ErroValidacao $erro) {
            $this->sessao->flash('erro', $erro->erros['horarios'] ?? $erro->getMessage());

            return Resposta::redirecionar("/empresas/{$parametros['id']}#horarios");
        }

        return $this->redirecionar("/empresas/{$parametros['id']}#horarios", 'Horários padrão atualizados.');
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(?string $id, array $dados, array $erros, int $status = 200): Resposta
    {
        return $this->pagina('empresas/formulario', [
            'titulo' => $id === null ? 'Nova empresa' : 'Editar empresa',
            'id' => $id,
            'dados' => $dados,
            'erros' => $erros,
            'ufs' => EmpresaServico::UFS,
        ], $status);
    }

    /** @return array<string, string> */
    private function entrada(Requisicao $requisicao): array
    {
        $dados = [];

        foreach (EmpresaRepositorio::CAMPOS as $campo) {
            $dados[$campo] = $requisicao->entrada($campo);
        }

        return $dados;
    }
}
