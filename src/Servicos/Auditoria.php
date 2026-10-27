<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Nucleo\Contexto;
use PDO;

/**
 * Trilha de auditoria: registra quem fez o quê, quando e de onde.
 *
 * Guarda só os campos que mudaram (minimização) e nunca grava segredos
 * (hash de senha, token, template biométrico).
 */
final class Auditoria
{
    /** Campos que jamais entram na trilha. */
    private const CAMPOS_PROIBIDOS = [
        'senha', 'senha_hash', 'token', 'token_hash', 'template', 'template_cifrado', 'nonce', 'hash_integridade',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Contexto $contexto,
    ) {
    }

    /**
     * @param array<string, mixed>|null $antes
     * @param array<string, mixed>|null $depois
     */
    public function registrar(
        string $acao,
        ?string $tabela = null,
        ?string $registroId = null,
        ?array $antes = null,
        ?array $depois = null,
    ): void {
        [$antes, $depois] = self::diferenca($antes, $depois);

        $this->pdo->prepare(<<<'SQL'
            INSERT INTO auditoria (usuario_id, acao, tabela, registro_id, dados_antes, dados_depois, ip)
            VALUES (:usuario, :acao, :tabela, :registro, :antes, :depois, CAST(:ip AS inet))
            SQL)->execute([
            'usuario' => $this->contexto->usuarioId,
            'acao' => $acao,
            'tabela' => $tabela,
            'registro' => $registroId,
            'antes' => $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
            'depois' => $depois === null ? null : json_encode($depois, JSON_UNESCAPED_UNICODE),
            'ip' => $this->contexto->ip,
        ]);
    }

    /**
     * Reduz antes/depois aos campos alterados e remove campos proibidos.
     *
     * @param array<string, mixed>|null $antes
     * @param array<string, mixed>|null $depois
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    public static function diferenca(?array $antes, ?array $depois): array
    {
        $limpar = static function (?array $dados): ?array {
            if ($dados === null) {
                return null;
            }

            foreach (self::CAMPOS_PROIBIDOS as $campo) {
                unset($dados[$campo]);
            }

            unset($dados['created_at'], $dados['updated_at']);

            return $dados;
        };

        $antes = $limpar($antes);
        $depois = $limpar($depois);

        if ($antes === null || $depois === null) {
            return [$antes, $depois];
        }

        $alterados = array_keys(array_filter(
            $depois,
            static fn (mixed $valor, string $campo): bool => !array_key_exists($campo, $antes)
                || $antes[$campo] !== $valor,
            ARRAY_FILTER_USE_BOTH,
        ));

        return [
            array_intersect_key($antes, array_flip($alterados)),
            array_intersect_key($depois, array_flip($alterados)),
        ];
    }
}
