<?php

declare(strict_types=1);

namespace CadGestor\Banco;

use InvalidArgumentException;
use PDO;

/**
 * Informa ao PostgreSQL de qual organização (e de qual usuário) é a requisição.
 *
 * As políticas de Row-Level Security (migração 0010) leem esses valores: é
 * como o crachá que a recepção entrega na entrada do prédio, e que só abre as
 * portas do andar da empresa do visitante. Sem crachá, nenhuma porta abre.
 *
 * Os valores ficam na sessão da conexão (set_config com is_local = false) e
 * valem até o fim da requisição, que usa sempre a mesma conexão.
 */
final class ContextoBanco
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private ?string $organizacaoId = null;
    private ?string $usuarioId = null;
    private bool $plataforma = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param bool $plataforma modo plataforma: enxerga organizações e usuários,
     *                         mas nenhuma linha de dados das organizações
     */
    public function definir(?string $organizacaoId, ?string $usuarioId = null, bool $plataforma = false): void
    {
        foreach ([$organizacaoId, $usuarioId] as $id) {
            if ($id !== null && preg_match(self::UUID, $id) !== 1) {
                throw new InvalidArgumentException('Identificador de contexto inválido.');
            }
        }

        $this->pdo->prepare(<<<'SQL'
            SELECT set_config('app.organizacao_id', :organizacao, false),
                   set_config('app.usuario_id', :usuario, false),
                   set_config('app.plataforma', :plataforma, false)
            SQL)->execute([
            'organizacao' => $organizacaoId ?? '',
            'usuario' => $usuarioId ?? '',
            'plataforma' => $plataforma ? 'on' : 'off',
        ]);

        $this->organizacaoId = $organizacaoId;
        $this->usuarioId = $usuarioId;
        $this->plataforma = $plataforma;
    }

    public function limpar(): void
    {
        $this->definir(null);
    }

    public function organizacaoId(): ?string
    {
        return $this->organizacaoId;
    }

    /**
     * Executa $acao dentro de uma organização (ex.: a plataforma criando o
     * administrador de um cliente) e depois restaura o contexto anterior.
     *
     * @template T
     * @param callable(): T $acao
     * @return T
     */
    public function dentroDe(string $organizacaoId, callable $acao): mixed
    {
        [$organizacao, $usuario, $plataforma] = [$this->organizacaoId, $this->usuarioId, $this->plataforma];
        $this->definir($organizacaoId, $usuario);

        try {
            return $acao();
        } finally {
            $this->definir($organizacao, $usuario, $plataforma);
        }
    }
}
