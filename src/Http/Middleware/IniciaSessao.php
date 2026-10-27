<?php

declare(strict_types=1);

namespace CadGestor\Http\Middleware;

use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Nucleo\Visao;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Csrf;
use CadGestor\Seguranca\Sessao;

/**
 * Abre a sessão (só nas rotas web, nunca na API das catracas) e deixa
 * usuário, token CSRF e mensagens disponíveis para as views.
 */
final class IniciaSessao implements Middleware
{
    public function __construct(
        private readonly Sessao $sessao,
        private readonly Csrf $csrf,
        private readonly Autenticacao $autenticacao,
    ) {
    }

    public function processar(Requisicao $requisicao, callable $proximo): Resposta
    {
        $this->sessao->iniciar();

        if ($this->sessao->expirou()) {
            $this->sessao->flash('aviso', 'Sua sessão expirou por inatividade. Entre novamente.');
        }

        $sessao = $this->sessao;
        Visao::compartilhar([
            'usuario' => $this->autenticacao->usuario(),
            'csrf_token' => $this->csrf->token(),
            'caminho_atual' => $requisicao->caminho,
            'consumir_flash' => static fn (): array => $sessao->consumirFlash(),
        ]);

        return $proximo($requisicao);
    }
}
