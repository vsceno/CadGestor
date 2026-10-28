<?php

declare(strict_types=1);

namespace CadGestor\Http\Middleware;

use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Nucleo\Visao;
use CadGestor\Seguranca\Csrf;

/**
 * Recusa qualquer envio de formulário (POST) sem o token CSRF correto.
 */
final class VerificaCsrf implements Middleware
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function processar(Requisicao $requisicao, callable $proximo): Resposta
    {
        if (in_array($requisicao->metodo, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $proximo($requisicao);
        }

        $token = $requisicao->corpo[Csrf::CAMPO] ?? $requisicao->cabecalho('X-CSRF-Token');

        if (!is_string($token) || !$this->csrf->validar($token)) {
            // 403 (e não o 419 de alguns frameworks): o Apache converte códigos fora do padrão em 500.
            return Resposta::html(Visao::renderizar('erros/419', ['titulo' => 'Formulário expirado']), 403);
        }

        return $proximo($requisicao);
    }
}
