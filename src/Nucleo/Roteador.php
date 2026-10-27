<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

use Closure;

/**
 * Roteador simples: associa método + caminho a um manipulador.
 *
 * Parâmetros de rota usam a sintaxe {nome} e aceitam apenas UUID, para que
 * nenhum dado pessoal (CPF, nome) trafegue na URL.
 */
final class Roteador
{
    private const PADRAO_UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /** @var array<string, array<string, callable(Requisicao, array<string, string>): Resposta>> */
    private array $rotas = [];

    /** @param callable(Requisicao, array<string, string>): Resposta $manipulador */
    public function get(string $caminho, callable $manipulador): void
    {
        $this->adicionar('GET', $caminho, $manipulador);
    }

    /** @param callable(Requisicao, array<string, string>): Resposta $manipulador */
    public function post(string $caminho, callable $manipulador): void
    {
        $this->adicionar('POST', $caminho, $manipulador);
    }

    /** @param callable(Requisicao, array<string, string>): Resposta $manipulador */
    public function adicionar(string $metodo, string $caminho, callable $manipulador): void
    {
        $this->rotas[$this->compilar($caminho)][strtoupper($metodo)] = $manipulador;
    }

    public function despachar(Requisicao $requisicao): Resposta
    {
        foreach ($this->rotas as $regex => $porMetodo) {
            if (preg_match($regex, $requisicao->caminho, $capturas) !== 1) {
                continue;
            }

            $metodo = $requisicao->metodo === 'HEAD' ? 'GET' : $requisicao->metodo;
            $manipulador = $porMetodo[$metodo] ?? null;

            if ($manipulador === null) {
                $resposta = Resposta::html(Visao::renderizar('erros/405'), 405);

                return new Resposta($resposta->corpo, 405, $resposta->cabecalhos() + [
                    'Allow' => implode(', ', array_keys($porMetodo)),
                ]);
            }

            $parametros = array_filter($capturas, 'is_string', ARRAY_FILTER_USE_KEY);

            return Closure::fromCallable($manipulador)($requisicao, $parametros);
        }

        return Resposta::html(Visao::renderizar('erros/404'), 404);
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
