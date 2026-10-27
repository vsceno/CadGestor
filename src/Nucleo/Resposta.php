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
        return new self($corpo, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            // Páginas internas podem conter dados pessoais: nunca guardar em cache.
            'Cache-Control' => 'no-store',
        ]);
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

    /** Arquivo para download (ex.: CSV). */
    public static function download(string $conteudo, string $nomeArquivo, string $tipo): self
    {
        $nomeSeguro = preg_replace('/[^A-Za-z0-9._-]/', '_', $nomeArquivo) ?? 'arquivo';

        return new self($conteudo, 200, [
            'Content-Type' => $tipo,
            'Content-Disposition' => 'attachment; filename="' . $nomeSeguro . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function comCabecalho(string $nome, string $valor): self
    {
        $nova = clone $this;
        $nova->cabecalhos[$nome] = $valor;

        return $nova;
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

            foreach ($this->cabecalhos + $this->cabecalhosSeguranca() as $nome => $valor) {
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
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; "
                . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=()',
        ];
    }
}
