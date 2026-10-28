<?php

declare(strict_types=1);

namespace CadGestor\Http\Middleware;

use CadGestor\Dominio\SituacaoAssinatura;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Nucleo\Visao;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\AssinaturaServico;

/**
 * Aplica a situação da assinatura às telas do cliente (régua em
 * SituacaoAssinatura): aviso, somente leitura ou só "Minha assinatura".
 *
 * Usuários da plataforma não passam por aqui (não têm organização).
 */
final class VerificaAssinatura implements Middleware
{
    /** Sempre liberados: regularizar, sair e trocar a senha. */
    private const LIVRES = ['/assinatura', '/sair', '/senha', '/pagamento-simulado'];

    public function __construct(
        private readonly Autenticacao $autenticacao,
        private readonly AssinaturaServico $assinaturas,
        private readonly Sessao $sessao,
    ) {
    }

    public function processar(Requisicao $requisicao, callable $proximo): Resposta
    {
        $usuario = $this->autenticacao->usuario();

        if ($usuario === null || $usuario->organizacaoId === null) {
            return $proximo($requisicao);
        }

        $situacao = $this->assinaturas->situacao();
        Visao::compartilhar(['situacao_assinatura' => $situacao]);

        if ($this->livre($requisicao->caminho) || $situacao->nivel === SituacaoAssinatura::NORMAL) {
            return $proximo($requisicao);
        }

        if ($situacao->permiteUsarSistema() && $requisicao->metodo === 'GET') {
            return $proximo($requisicao);
        }

        $this->sessao->flash('erro', (string) $situacao->aviso);

        return Resposta::redirecionar('/assinatura');
    }

    private function livre(string $caminho): bool
    {
        foreach (self::LIVRES as $livre) {
            if ($caminho === $livre || str_starts_with($caminho, $livre . '/')) {
                return true;
            }
        }

        return false;
    }
}
