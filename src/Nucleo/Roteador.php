<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

use CadGestor\Http\Middleware\Middleware;

/**
 * Roteador simples: associa método + caminho a um manipulador, com
 * middlewares por rota ou por grupo.
 *
 * Parâmetros de rota usam a sintaxe {nome} e aceitam apenas UUID, para que
 * nenhum dado pessoal (CPF, nome) trafegue na URL.
 */
final class Roteador
{
    private const PADRAO_UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * @var array<string, array<string, array{
     *     manipulador: callable(Requisicao, array<string, string>): Resposta,
     *     middlewares: list<Middleware>
     * }>>
     */
    private array $rotas = [];

    /** @var list<list<Middleware>> */
    private array $pilhaGrupos = [];

    /**
     * @param callable(Requisicao, array<string, string>): Resposta $manipulador
     * @param list<Middleware> $middlewares
     */
    public function get(string $caminho, callable $manipulador, array $middlewares = []): void
    {
        $this->adicionar('GET', $caminho, $manipulador, $middlewares);
    }

    /**
     * @param callable(Requisicao, array<string, string>): Resposta $manipulador
     * @param list<Middleware> $middlewares
     */
    public function post(string $caminho, callable $manipulador, array $middlewares = []): void
    {
        $this->adicionar('POST', $caminho, $manipulador, $middlewares);
    }

    /**
     * Rotas definidas dentro do callback herdam os middlewares do grupo.
     *
     * @param list<Middleware> $middlewares
     * @param callable(self): void $definir
     */
    public function grupo(array $middlewares, callable $definir): void
    {
        $this->pilhaGrupos[] = $middlewares;

        try {
            $definir($this);
        } finally {
            array_pop($this->pilhaGrupos);
        }
    }

    /**
     * @param callable(Requisicao, array<string, string>): Resposta $manipulador
     * @param list<Middleware> $middlewares
     */
    public function adicionar(string $metodo, string $caminho, callable $manipulador, array $middlewares = []): void
    {
        $this->rotas[$this->compilar($caminho)][strtoupper($metodo)] = [
            'manipulador' => $manipulador,
            'middlewares' => array_merge(...$this->pilhaGrupos, ...[$middlewares]),
        ];
    }

    public function despachar(Requisicao $requisicao): Resposta
    {
        foreach ($this->rotas as $regex => $porMetodo) {
            if (preg_match($regex, $requisicao->caminho, $capturas) !== 1) {
                continue;
            }

            $metodo = $requisicao->metodo === 'HEAD' ? 'GET' : $requisicao->metodo;
            $rota = $porMetodo[$metodo] ?? null;

            if ($rota === null) {
                $resposta = Resposta::html(Visao::renderizar('erros/405', ['titulo' => 'Método não permitido']), 405);

                return $resposta->comCabecalho('Allow', implode(', ', array_keys($porMetodo)));
            }

            $parametros = array_filter($capturas, 'is_string', ARRAY_FILTER_USE_KEY);
            $manipulador = $rota['manipulador'];

            $pipeline = static fn (Requisicao $r): Resposta => $manipulador($r, $parametros);

            foreach (array_reverse($rota['middlewares']) as $middleware) {
                $proximo = $pipeline;
                $pipeline = static fn (Requisicao $r): Resposta => $middleware->processar($r, $proximo);
            }

            return $pipeline($requisicao);
        }

        return Resposta::html(Visao::renderizar('erros/404', ['titulo' => 'Página não encontrada']), 404);
    }

    private function compilar(string $caminho): string
    {
        $caminho = Requisicao::normalizarCaminho($caminho);
        $partes = preg_split('/(\{[a-z_][a-z0-9_]*\})/i', $caminho, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';

        foreach ($partes as $parte) {
            if (preg_match('/^\{([a-z_][a-z0-9_]*)\}$/i', $parte, $m) === 1) {
                $regex .= '(?P<' . $m[1] . '>' . self::PADRAO_UUID . ')';
                continue;
            }

            $regex .= preg_quote($parte, '#');
        }

        return '#^' . $regex . '$#';
    }
}
