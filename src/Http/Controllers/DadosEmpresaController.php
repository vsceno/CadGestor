<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Dominio\DadosCadastraisCliente;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\DadosEmpresaServico;
use CadGestor\Servicos\EmpresaServico;

/**
 * Dados cadastrais da própria organização (endereço, inscrições, contatos),
 * mantidos pelo administrador do cliente. Ficam junto da assinatura porque
 * são usados na cobrança e na nota fiscal.
 */
final class DadosEmpresaController extends Controlador
{
    public function __construct(Sessao $sessao, private readonly DadosEmpresaServico $servico)
    {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function editar(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->formulario($this->servico->atual(), []);
    }

    /** @param array<string, string> $parametros */
    public function atualizar(Requisicao $requisicao, array $parametros): Resposta
    {
        $dados = [];

        foreach (DadosCadastraisCliente::CAMPOS_CONTATO as $campo) {
            $dados[$campo] = $requisicao->entrada($campo);
        }

        try {
            $this->servico->atualizar($dados);
        } catch (ErroValidacao $erro) {
            return $this->formulario($dados + $this->servico->atual(), $erro->erros, 422);
        }

        return $this->redirecionar('/assinatura', 'Dados da empresa atualizados.');
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(array $dados, array $erros, int $status = 200): Resposta
    {
        return $this->pagina('assinatura/empresa', [
            'titulo' => 'Dados da empresa',
            'dados' => $dados,
            'erros' => $erros,
            'ufs' => EmpresaServico::UFS,
        ], $status);
    }
}
