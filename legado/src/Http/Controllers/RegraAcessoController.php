<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\CatracaRepositorio;
use CadGestor\Repositorios\EmpresaRepositorio;
use CadGestor\Repositorios\RegraAcessoRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\RegraAcessoServico;

/**
 * Matriz de acesso perfil x catraca x horário de uma empresa.
 */
final class RegraAcessoController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly EmpresaRepositorio $empresas,
        private readonly CatracaRepositorio $catracas,
        private readonly RegraAcessoRepositorio $regras,
        private readonly RegraAcessoServico $servico,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        $empresa = $this->empresas->buscar($parametros['id']);

        if ($empresa === null) {
            return $this->naoEncontrado();
        }

        return $this->pagina('regras/empresa', [
            'titulo' => 'Regras de acesso',
            'empresa' => $empresa,
            'perfis' => $this->empresas->perfisAceitosComNome($parametros['id']),
            'catracas' => $this->catracas->daEmpresa($parametros['id']),
            'regrasPorPerfil' => $this->regras->porPerfilDaEmpresa($parametros['id']),
            'horariosPadrao' => $this->empresas->horarios($parametros['id']),
        ]);
    }

    /** @param array<string, string> $parametros */
    public function salvarDoPerfil(Requisicao $requisicao, array $parametros): Resposta
    {
        $destino = "/empresas/{$parametros['id']}/regras#perfil-{$parametros['perfil']}";

        try {
            $this->servico->definirDoPerfil(
                $parametros['id'],
                $parametros['perfil'],
                $requisicao->entradaLista('dia'),
                $requisicao->entradaLista('inicio'),
                $requisicao->entradaLista('fim'),
                $requisicao->entradaLista('catraca'),
            );
        } catch (ErroValidacao $erro) {
            return $this->redirecionar($destino, $erro->erros['regras'] ?? $erro->getMessage(), 'erro');
        }

        return $this->redirecionar($destino, 'Regras do perfil atualizadas.');
    }
}
