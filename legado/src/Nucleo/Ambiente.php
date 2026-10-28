<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Leitura tipada das variáveis de ambiente (.env).
 *
 * O .env funciona como a "ficha de configuração" da instalação: cada máquina
 * tem a sua, e ela nunca vai para o repositório.
 */
final class Ambiente
{
    /** Variáveis sem as quais a aplicação não deve subir. */
    private const OBRIGATORIAS = [
        'APP_AMBIENTE',
        'APP_FUSO',
        'DB_HOST',
        'DB_PORTA',
        'DB_BANCO',
        'DB_USUARIO',
        'DB_SCHEMA',
    ];

    /** @param array<string, string> $valores */
    public function __construct(private readonly array $valores)
    {
    }

    /**
     * Carrega o arquivo .env do diretório informado e valida as obrigatórias.
     */
    public static function carregar(string $diretorio): self
    {
        $dotenv = Dotenv::createArrayBacked($diretorio);
        $valores = $dotenv->load();
        $dotenv->required(self::OBRIGATORIAS)->notEmpty();
        $dotenv->required('APP_AMBIENTE')->allowedValues(['desenvolvimento', 'teste', 'producao']);
        $dotenv->required('DB_PORTA')->isInteger();

        return new self(array_map(static fn (?string $v): string => $v ?? '', $valores));
    }

    public function texto(string $nome, ?string $padrao = null): string
    {
        $valor = $this->valores[$nome] ?? null;

        if ($valor === null || $valor === '') {
            if ($padrao === null) {
                throw new RuntimeException("Variável de ambiente ausente: {$nome}");
            }

            return $padrao;
        }

        return $valor;
    }

    public function inteiro(string $nome, ?int $padrao = null): int
    {
        $valor = $this->texto($nome, $padrao === null ? null : (string) $padrao);

        if (filter_var($valor, FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("Variável de ambiente não é inteira: {$nome}");
        }

        return (int) $valor;
    }

    public function booleano(string $nome, bool $padrao = false): bool
    {
        $valor = $this->texto($nome, $padrao ? 'true' : 'false');
        $convertido = filter_var($valor, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($convertido === null) {
            throw new RuntimeException("Variável de ambiente não é booleana: {$nome}");
        }

        return $convertido;
    }

    public function emProducao(): bool
    {
        return $this->texto('APP_AMBIENTE') === 'producao';
    }
}
