<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Informa ao PostgreSQL de qual cliente (organização) e de qual usuário é a
 * requisição. As políticas de Row-Level Security leem esses valores.
 *
 * É como o crachá que a recepção entrega na entrada do prédio: ele só abre as
 * portas do andar da empresa do visitante. Sem crachá, nenhuma porta abre.
 *
 * Os valores ficam na sessão da conexão (set_config com is_local = false) e
 * valem até o fim da requisição, que usa sempre a mesma conexão.
 */
class ContextoBanco
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private ?string $organizacaoId = null;

    private ?string $usuarioId = null;

    private bool $plataforma = false;

    /**
     * @param  bool  $plataforma  modo plataforma: enxerga os clientes (organizações) e seus
     *                            usuários, mas nenhuma linha de dados do ambiente deles
     */
    public function definir(?string $organizacaoId, ?string $usuarioId = null, bool $plataforma = false): void
    {
        foreach ([$organizacaoId, $usuarioId] as $id) {
            if ($id !== null && preg_match(self::UUID, $id) !== 1) {
                throw new InvalidArgumentException('Identificador de contexto inválido.');
            }
        }

        DB::connection('pgsql')->select(<<<'SQL'
            SELECT set_config('app.organizacao_id', ?, false),
                   set_config('app.usuario_id', ?, false),
                   set_config('app.plataforma', ?, false)
            SQL, [$organizacaoId ?? '', $usuarioId ?? '', $plataforma ? 'on' : 'off']);

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

    public function usuarioId(): ?string
    {
        return $this->usuarioId;
    }

    public function emModoPlataforma(): bool
    {
        return $this->plataforma;
    }

    /**
     * Executa $acao dentro de uma organização (ex.: a plataforma cadastrando o
     * primeiro administrador de um cliente) e depois restaura o contexto anterior.
     *
     * @template T
     *
     * @param  callable(): T  $acao
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
