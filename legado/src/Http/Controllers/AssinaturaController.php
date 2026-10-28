<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Dominio\DadosCadastraisCliente;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\AssinaturaServico;
use CadGestor\Servicos\DadosEmpresaServico;

/**
 * "Minha assinatura": plano, uso, faturas, troca de plano, cancelamento e
 * exportação dos dados. Abre mesmo com o sistema suspenso, para o cliente
 * conseguir regularizar ou levar os dados embora.
 */
final class AssinaturaController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly AssinaturaServico $servico,
        private readonly DadosEmpresaServico $dadosEmpresa,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->pagina('assinatura/index', [
            'titulo' => 'Minha assinatura',
            'assinatura' => $this->assinaturas->daOrganizacao(),
            'situacao' => $this->servico->situacao(),
            'uso' => $this->assinaturas->uso(),
            'faturas' => $this->assinaturas->faturas(),
            'planos' => $this->assinaturas->planos(true),
            'cadastroCompleto' => DadosCadastraisCliente::completo($this->dadosEmpresa->atual()),
        ]);
    }

    /** @param array<string, string> $parametros */
    public function trocarPlano(Requisicao $requisicao, array $parametros): Resposta
    {
        try {
            $this->servico->trocarPlano($requisicao->entrada('plano_id'));
        } catch (ErroValidacao $erro) {
            return $this->redirecionar('/assinatura', implode(' ', $erro->erros), 'erro');
        }

        return $this->redirecionar('/assinatura', 'Plano alterado. O novo valor vale a partir da próxima fatura.');
    }

    /** @param array<string, string> $parametros */
    public function cancelar(Requisicao $requisicao, array $parametros): Resposta
    {
        if ($requisicao->entrada('confirmacao') !== 'CANCELAR') {
            return $this->redirecionar(
                '/assinatura',
                'Para cancelar, digite CANCELAR no campo de confirmação.',
                'erro',
            );
        }

        try {
            $this->servico->cancelar();
        } catch (ErroValidacao $erro) {
            return $this->redirecionar('/assinatura', implode(' ', $erro->erros), 'erro');
        }

        return $this->redirecionar(
            '/assinatura',
            'Assinatura cancelada. As catracas param de liberar acessos. Exporte os seus dados abaixo.',
            'aviso',
        );
    }

    /** @param array<string, string> $parametros */
    public function exportar(Requisicao $requisicao, array $parametros): Resposta
    {
        return Resposta::download(
            $this->servico->exportarCadastros(),
            'cadgestor-cadastros-' . date('Y-m-d') . '.csv',
            'text/csv; charset=UTF-8',
        );
    }
}
