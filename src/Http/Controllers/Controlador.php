<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\Resposta;
use CadGestor\Nucleo\Visao;
use CadGestor\Seguranca\Sessao;

/**
 * Base dos controllers web: renderização de páginas e redirecionamento com
 * mensagem (padrão Post/Redirect/Get, que evita reenvio ao atualizar a página).
 */
abstract class Controlador
{
    public function __construct(protected readonly Sessao $sessao)
    {
    }

    /** @param array<string, mixed> $dados */
    protected function pagina(string $template, array $dados = [], int $status = 200): Resposta
    {
        return Resposta::html(Visao::renderizar($template, $dados), $status);
    }

    protected function redirecionar(string $destino, ?string $mensagem = null, string $tipo = 'sucesso'): Resposta
    {
        if ($mensagem !== null) {
            $this->sessao->flash($tipo, $mensagem);
        }

        return Resposta::redirecionar($destino);
    }

    protected function naoEncontrado(): Resposta
    {
        return $this->pagina('erros/404', ['titulo' => 'Página não encontrada'], 404);
    }
}
