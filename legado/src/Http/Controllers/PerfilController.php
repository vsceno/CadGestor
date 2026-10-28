<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\PerfilRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\PerfilServico;

/**
 * Perfis de vínculo configuráveis (somente administrador).
 */
final class PerfilController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly PerfilRepositorio $perfis,
        private readonly PerfilServico $servico,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->pagina('perfis/index', [
            'titulo' => 'Perfis de vínculo',
            'perfis' => $this->perfis->listar(),
            'basesLegais' => PerfilServico::BASES_LEGAIS,
        ]);
    }

    /** @param array<string, string> $parametros */
    public function novo(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->formulario(null, ['base_legal' => 'consentimento', 'ativo' => true], []);
    }

    /** @param array<string, string> $parametros */
    public function criar(Requisicao $requisicao, array $parametros): Resposta
    {
        $entrada = $this->entrada($requisicao);

        try {
            $this->servico->criar($entrada);
        } catch (ErroValidacao $erro) {
            return $this->formulario(null, $entrada, $erro->erros, 422);
        }

        return $this->redirecionar('/perfis', 'Perfil criado.');
    }

    /** @param array<string, string> $parametros */
    public function editar(Requisicao $requisicao, array $parametros): Resposta
    {
        $perfil = $this->perfis->buscar($parametros['id']);

        return $perfil === null ? $this->naoEncontrado() : $this->formulario($parametros['id'], $perfil, []);
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

        return $this->redirecionar('/perfis', 'Perfil atualizado.');
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(?string $id, array $dados, array $erros, int $status = 200): Resposta
    {
        return $this->pagina('perfis/formulario', [
            'titulo' => $id === null ? 'Novo perfil' : 'Editar perfil',
            'id' => $id,
            'dados' => $dados,
            'erros' => $erros,
            'basesLegais' => PerfilServico::BASES_LEGAIS,
        ], $status);
    }

    /** @return array<string, string> */
    private function entrada(Requisicao $requisicao): array
    {
        return [
            'nome' => $requisicao->entrada('nome'),
            'descricao' => $requisicao->entrada('descricao'),
            'base_legal' => $requisicao->entrada('base_legal'),
            'base_legal_validada' => $requisicao->entrada('base_legal_validada'),
            'ativo' => $requisicao->entrada('ativo'),
        ];
    }
}
