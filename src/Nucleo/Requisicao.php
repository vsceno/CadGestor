<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

/**
 * Representação imutável da requisição HTTP recebida.
 */
final readonly class Requisicao
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $corpo
     * @param array<string, string> $servidor
     */
    public function __construct(
        public string $metodo,
        public string $caminho,
        public array $query = [],
        public array $corpo = [],
        public array $servidor = [],
    ) {
    }

    public static function daGlobal(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $caminho = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizarCaminho($caminho),
            $_GET,
            $_POST,
            array_map('strval', array_filter($_SERVER, 'is_scalar')),
        );
    }

    public static function normalizarCaminho(string $caminho): string
    {
        $caminho = '/' . trim(rawurldecode($caminho), '/');

        return $caminho === '/' ? '/' : rtrim($caminho, '/');
    }

    public function ip(): string
    {
        // Atrás de proxy reverso isto deve ser revisto (X-Forwarded-For confiável).
        return $this->servidor['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
