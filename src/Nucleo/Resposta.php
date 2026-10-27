<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

/**
 * Resposta HTTP montada pelos controllers e enviada pelo index.php.
 */
final class Resposta
{
    /** @param array<string, string> $cabecalhos */
    public function __construct(
        public readonly string $corpo = '',
        public readonly int $status = 200,
        private array $cabecalhos = [],
    ) {
    }

    public static function html(string $corpo, int $status = 200): self
    {
        return new self($corpo, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** @param array<mixed> $dados */
    public static function json(array $dados, int $status = 200): self
    {
        $corpo = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new self($corpo, $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function redirecionar(string $destino, int $status = 303): self
    {
        return new self('', $status, ['Location' => $destino]);
    }

    /** @return array<string, string> */
    public function cabecalhos(): array
    {
        return $this->cabecalhos;
    }

    public function enviar(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->cabecalhosSeguranca() + $this->cabecalhos as $nome => $valor) {
                header("{$nome}: {$valor}");
            }
        }

        echo $this->corpo;
    }

    /**
     * Cabeçalhos aplicados a toda resposta; podem ser sobrescritos pelo controller.
     *
     * @return array<string, string>
     */
    private function cabecalhosSeguranca(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; "
                . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        ];
    }
}
