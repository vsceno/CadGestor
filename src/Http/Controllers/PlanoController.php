<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\PlanoServico;

/**
 * Catálogo de planos (somente a plataforma).
 */
final class PlanoController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly PlanoServico $servico,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->pagina('planos/index', ['titulo' => 'Planos', 'planos' => $this->assinaturas->planos()]);
    }

    /** @param array<string, string> $parametros */
    public function novo(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->formulario(null, ['disponivel_venda' => true], []);
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

        return $this->redirecionar('/planos', 'Plano criado.');
    }

    /** @param array<string, string> $parametros */
    public function editar(Requisicao $requisicao, array $parametros): Resposta
    {
        $plano = $this->assinaturas->plano($parametros['id']);

        if ($plano === null) {
            return $this->naoEncontrado();
        }

        $plano['preco'] = number_format((int) $plano['preco_centavos'] / 100, 2, ',', '.');

        return $this->formulario($parametros['id'], $plano, []);
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

        return $this->redirecionar('/planos', 'Plano atualizado.');
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(?string $id, array $dados, array $erros, int $status = 200): Resposta
    {
        return $this->pagina('planos/formulario', [
            'titulo' => $id === null ? 'Novo plano' : 'Editar plano',
            'id' => $id,
            'dados' => $dados,
            'erros' => $erros,
        ], $status);
    }

    /** @return array<string, string> */
    private function entrada(Requisicao $requisicao): array
    {
        $campos = [
            'codigo', 'nome', 'descricao', 'preco', 'limite_pessoas', 'limite_catracas', 'disponivel_venda', 'ordem',
        ];

        return array_combine($campos, array_map(static fn (string $c): string => $requisicao->entrada($c), $campos));
    }
}
