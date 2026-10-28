<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\CatracaRepositorio;
use CadGestor\Repositorios\RegraAcessoRepositorio;
use CadGestor\Repositorios\VinculoRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\RegraAcessoServico;
use CadGestor\Servicos\VinculoServico;

/**
 * Vínculos da pessoa com empresas e suas regras de acesso próprias.
 */
final class VinculoController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly VinculoRepositorio $vinculos,
        private readonly VinculoServico $servico,
        private readonly RegraAcessoRepositorio $regras,
        private readonly RegraAcessoServico $regrasServico,
        private readonly CatracaRepositorio $catracas,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function criar(Requisicao $requisicao, array $parametros): Resposta
    {
        $destino = "/pessoas/{$parametros['id']}#vinculos";

        try {
            $this->servico->criar(
                $parametros['id'],
                $requisicao->entrada('empresa_perfil'),
                $requisicao->entrada('data_inicio'),
                $requisicao->entrada('data_fim'),
            );
        } catch (ErroValidacao $erro) {
            return $this->redirecionar($destino, implode(' ', $erro->erros), 'erro');
        }

        return $this->redirecionar($destino, 'Vínculo adicionado.');
    }

    /** @param array<string, string> $parametros */
    public function alterar(Requisicao $requisicao, array $parametros): Resposta
    {
        $vinculo = $this->vinculos->buscar($parametros['id']);

        if ($vinculo === null) {
            return $this->naoEncontrado();
        }

        $destino = "/pessoas/{$vinculo['pessoa_id']}#vinculos";

        try {
            match ($requisicao->entrada('acao')) {
                'suspender' => $this->servico->suspender($parametros['id']),
                'reativar' => $this->servico->reativar($parametros['id']),
                'encerrar' => $this->servico->encerrar(
                    $parametros['id'],
                    $requisicao->entrada('motivo'),
                    $requisicao->entrada('data_fim'),
                ),
                'periodo' => $this->servico->alterarPeriodo(
                    $parametros['id'],
                    $requisicao->entrada('data_inicio'),
                    $requisicao->entrada('data_fim'),
                ),
                default => throw ErroValidacao::campo('acao', 'Ação inválida.'),
            };
        } catch (ErroValidacao $erro) {
            return $this->redirecionar($destino, implode(' ', $erro->erros), 'erro');
        }

        return $this->redirecionar($destino, 'Vínculo atualizado.');
    }

    /** @param array<string, string> $parametros */
    public function regras(Requisicao $requisicao, array $parametros): Resposta
    {
        $vinculo = $this->vinculos->buscar($parametros['id']);

        if ($vinculo === null) {
            return $this->naoEncontrado();
        }

        return $this->pagina('vinculos/regras', [
            'titulo' => 'Regras do vínculo',
            'vinculo' => $vinculo,
            'regras' => $this->regras->doVinculo($parametros['id']),
            'catracas' => $this->catracas->daEmpresa((string) $vinculo['empresa_id']),
        ]);
    }

    /** @param array<string, string> $parametros */
    public function salvarRegras(Requisicao $requisicao, array $parametros): Resposta
    {
        $vinculo = $this->vinculos->buscar($parametros['id']);

        if ($vinculo === null) {
            return $this->naoEncontrado();
        }

        try {
            $this->regrasServico->definirDoVinculo(
                (string) $vinculo['empresa_id'],
                $parametros['id'],
                $requisicao->entradaLista('dia'),
                $requisicao->entradaLista('inicio'),
                $requisicao->entradaLista('fim'),
                $requisicao->entradaLista('catraca'),
            );
        } catch (ErroValidacao $erro) {
            return $this->redirecionar("/vinculos/{$parametros['id']}/regras", implode(' ', $erro->erros), 'erro');
        }

        return $this->redirecionar("/vinculos/{$parametros['id']}/regras", 'Regras do vínculo salvas.');
    }
}
