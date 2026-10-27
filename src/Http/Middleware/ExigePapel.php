<?php

declare(strict_types=1);

namespace CadGestor\Http\Middleware;

use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Nucleo\Visao;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Papel;

/**
 * Exige que o usuário tenha pelo menos um dos papéis informados
 * (o administrador sempre passa). Deve vir depois de ExigeAutenticacao.
 */
final class ExigePapel implements Middleware
{
    /** @var list<Papel> */
    private readonly array $papeis;

    public function __construct(private readonly Autenticacao $autenticacao, Papel ...$papeis)
    {
        $this->papeis = array_values($papeis);
    }

    public function processar(Requisicao $requisicao, callable $proximo): Resposta
    {
        $usuario = $this->autenticacao->usuario();

        if ($usuario === null || !$usuario->temPapel(...$this->papeis)) {
            return Resposta::html(Visao::renderizar('erros/403', ['titulo' => 'Acesso negado']), 403);
        }

        return $proximo($requisicao);
    }
}
