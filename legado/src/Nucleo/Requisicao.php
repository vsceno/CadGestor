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
     * @param array<string, mixed>  $arquivos
     */
    public function __construct(
        public string $metodo,
        public string $caminho,
        public array $query = [],
        public array $corpo = [],
        public array $servidor = [],
        public string $corpoBruto = '',
        public array $arquivos = [],
    ) {
    }

    public static function daGlobal(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $caminho = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $servidor = array_map('strval', array_filter($_SERVER, 'is_scalar'));

        // Com mod_php o cabeçalho Authorization pode não chegar em $_SERVER.
        if (!isset($servidor['HTTP_AUTHORIZATION']) && function_exists('getallheaders')) {
            foreach (getallheaders() as $nome => $valor) {
                if (strcasecmp((string) $nome, 'Authorization') === 0) {
                    $servidor['HTTP_AUTHORIZATION'] = (string) $valor;
                }
            }
        }

        $corpoBruto = str_starts_with($servidor['CONTENT_TYPE'] ?? '', 'application/json')
            ? (string) file_get_contents('php://input', length: 1_048_576)
            : '';

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalizarCaminho($caminho),
            $_GET,
            $_POST,
            $servidor,
            $corpoBruto,
            $_FILES,
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

    public function cabecalho(string $nome): ?string
    {
        $chave = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));

        return $this->servidor[$chave] ?? null;
    }

    /** Valor textual do formulário (POST), sem espaços nas pontas. */
    public function entrada(string $nome, string $padrao = ''): string
    {
        $valor = $this->corpo[$nome] ?? $padrao;

        return is_string($valor) ? trim($valor) : $padrao;
    }

    /**
     * Lista de valores do formulário (ex.: checkboxes name="papeis[]").
     *
     * @return list<string>
     */
    public function entradaLista(string $nome): array
    {
        $valor = $this->corpo[$nome] ?? [];

        return is_array($valor) ? array_values(array_filter($valor, 'is_string')) : [];
    }

    /** Parâmetro da query string (GET). */
    public function consulta(string $nome, string $padrao = ''): string
    {
        $valor = $this->query[$nome] ?? $padrao;

        return is_string($valor) ? trim($valor) : $padrao;
    }

    /** @return array<string, mixed>|null corpo JSON decodificado, ou null se inválido */
    public function json(): ?array
    {
        if ($this->corpoBruto === '') {
            return null;
        }

        $dados = json_decode($this->corpoBruto, true, 16);

        return is_array($dados) ? $dados : null;
    }
}
