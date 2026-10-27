<?php

declare(strict_types=1);

namespace CadGestor\Http\Middleware;

use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Seguranca\Autenticacao;

/**
 * Exige usuário logado. Quem ainda usa senha provisória só acessa a troca de
 * senha e o logout.
 */
final class ExigeAutenticacao implements Middleware
{
    private const LIVRES_COM_SENHA_PROVISORIA = ['/senha', '/sair'];

    public function __construct(private readonly Autenticacao $autenticacao)
    {
    }

    public function processar(Requisicao $requisicao, callable $proximo): Resposta
    {
        $usuario = $this->autenticacao->usuario();

        if ($usuario === null) {
            return Resposta::redirecionar('/login');
        }

        if ($usuario->deveTrocarSenha && !in_array($requisicao->caminho, self::LIVRES_COM_SENHA_PROVISORIA, true)) {
            return Resposta::redirecionar('/senha');
        }

        return $proximo($requisicao);
    }
}
