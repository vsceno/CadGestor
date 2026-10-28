<?php

namespace App\Auth;

use App\Models\Usuario;
use App\Support\ContextoBanco;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;

/**
 * Provedor de usuários que respeita o isolamento entre clientes (RLS).
 *
 * Antes do login não se sabe de qual cliente a pessoa é, e o RLS esconderia a
 * linha dela. Por isso:
 *   - login: a conta é localizada pela função cg_login_usuario (SECURITY DEFINER);
 *   - a cada requisição: o contexto do banco passa a ser o do próprio usuário
 *     e, em seguida, o do cliente dele (ou o modo plataforma).
 *
 * Conta inativa ou de cliente desativado não autentica, nem com sessão aberta.
 */
class ProvedorUsuarios extends EloquentUserProvider
{
    public function __construct(Hasher $hasher, private readonly ContextoBanco $contexto)
    {
        parent::__construct($hasher, Usuario::class);
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        if (! is_string($identifier) || ! preg_match('/^[0-9a-f-]{36}$/i', $identifier)) {
            return null;
        }

        // A própria conta se enxerga (política "id = cg_usuario_atual()").
        $this->contexto->definir(null, $identifier);
        $usuario = Usuario::query()->find($identifier);

        if (! $usuario instanceof Usuario || ! $usuario->ativo || ! $this->clienteAtivo($usuario)) {
            $this->contexto->limpar();

            return null;
        }

        $usuario->daPlataforma()
            ? $this->contexto->definir(null, $usuario->id, true)
            : $this->contexto->definir($usuario->organizacao_id, $usuario->id);

        return $usuario;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $email = mb_strtolower(trim((string) ($credentials['email'] ?? '')));

        if ($email === '') {
            return null;
        }

        $conta = DB::connection('pgsql')->selectOne('SELECT usuario_id FROM cg_login_usuario(?)', [$email]);

        return $conta === null ? null : $this->retrieveById((string) $conta->usuario_id);
    }

    /** Sem "lembrar de mim". */
    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void {}

    private function clienteAtivo(Usuario $usuario): bool
    {
        if ($usuario->daPlataforma()) {
            return true;
        }

        $this->contexto->definir($usuario->organizacao_id, $usuario->id);

        return (bool) DB::connection('pgsql')->scalar(
            'SELECT ativo FROM organizacao WHERE id = ?',
            [$usuario->organizacao_id],
        );
    }
}
