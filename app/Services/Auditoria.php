<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Trilha de auditoria: quem fez o quê, quando e de onde.
 *
 * Guarda só os campos que mudaram (minimização) e nunca grava segredos
 * (senha, hash, token, template biométrico). A tabela é só-acrescentar: o
 * papel da aplicação não consegue alterar nem apagar registros. Cada registro
 * fica na trilha do cliente do contexto atual (RLS).
 */
class Auditoria
{
    /** Campos que jamais entram na trilha. */
    private const CAMPOS_PROIBIDOS = [
        'senha', 'senha_hash', 'password', 'token', 'token_hash', 'remember_token', 'template',
        'template_cifrado', 'nonce', 'hash_integridade', 'verificacao_token_hash', 'redefinicao_token_hash',
    ];

    /**
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $depois
     */
    public function registrar(
        string $acao,
        ?string $tabela = null,
        ?string $registroId = null,
        ?array $antes = null,
        ?array $depois = null,
        ?string $usuarioId = null,
    ): void {
        [$antes, $depois] = self::diferenca($antes, $depois);

        DB::connection('pgsql')->insert(<<<'SQL'
            INSERT INTO auditoria (usuario_id, acao, tabela, registro_id, dados_antes, dados_depois, ip)
            VALUES (?, ?, ?, ?, ?, ?, CAST(? AS inet))
            SQL, [
            $usuarioId ?? Auth::id(),
            $acao,
            $tabela,
            $registroId,
            $antes === null ? null : json_encode($antes, JSON_UNESCAPED_UNICODE),
            $depois === null ? null : json_encode($depois, JSON_UNESCAPED_UNICODE),
            request()->ip(),
        ]);
    }

    /**
     * Reduz antes/depois aos campos alterados e remove campos proibidos.
     *
     * @param  array<string, mixed>|null  $antes
     * @param  array<string, mixed>|null  $depois
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    public static function diferenca(?array $antes, ?array $depois): array
    {
        $limpar = static function (?array $dados): ?array {
            if ($dados === null) {
                return null;
            }

            foreach ([...self::CAMPOS_PROIBIDOS, 'created_at', 'updated_at'] as $campo) {
                unset($dados[$campo]);
            }

            return $dados;
        };

        $antes = $limpar($antes);
        $depois = $limpar($depois);

        if ($antes === null || $depois === null) {
            return [$antes, $depois];
        }

        $alterados = array_keys(array_filter(
            $depois,
            static fn (mixed $valor, string $campo): bool => ! array_key_exists($campo, $antes) || $antes[$campo] !== $valor,
            ARRAY_FILTER_USE_BOTH,
        ));

        return [
            array_intersect_key($antes, array_flip($alterados)),
            array_intersect_key($depois, array_flip($alterados)),
        ];
    }
}
