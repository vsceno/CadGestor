<?php

namespace App\Models;

use App\Enums\Papel;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Usuário do sistema (tabela usuario_sistema).
 *
 * organizacao_id nulo = equipe da plataforma; preenchido = usuário daquele
 * cliente. Os papéis ficam em usuario_papel. A senha é Argon2id (config/hashing).
 *
 * @property string $id
 * @property string $nome
 * @property string $email
 * @property string|null $organizacao_id
 * @property bool $ativo
 * @property bool $deve_trocar_senha
 */
#[Table('usuario_sistema')]
class Usuario extends Authenticatable
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['nome', 'email', 'ativo', 'deve_trocar_senha'];

    /** @var list<string> */
    protected $hidden = ['senha_hash', 'verificacao_token_hash', 'redefinicao_token_hash'];

    /** O banco gera o id (uuidv7); o Laravel não precisa inventar um. */
    public function newUniqueId(): ?string
    {
        return null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'deve_trocar_senha' => 'boolean',
            'ultimo_login_em' => 'datetime',
            'email_verificado_em' => 'datetime',
            'credenciais_versao' => 'integer',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'senha_hash';
    }

    /** Sem "lembrar de mim": sessões sempre expiram. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function daPlataforma(): bool
    {
        return $this->organizacao_id === null;
    }

    /** @return list<Papel> */
    public function papeis(): array
    {
        $papeis = DB::table('usuario_papel')->where('usuario_id', $this->id)->pluck('papel')->all();
        $lista = array_values(array_filter(array_map(static fn (string $p): ?Papel => Papel::tryFrom($p), $papeis)));

        // Conta da plataforma só tem o papel de plataforma; conta de cliente nunca o tem.
        return $this->daPlataforma()
            ? [Papel::Plataforma]
            : array_values(array_filter($lista, static fn (Papel $p): bool => $p !== Papel::Plataforma));
    }

    /** Administrador do cliente tem todos os papéis do cliente. */
    public function temPapel(Papel ...$exigidos): bool
    {
        $papeis = $this->papeis();

        foreach ($exigidos as $papel) {
            if (in_array($papel, $papeis, true)) {
                return true;
            }

            if ($papel !== Papel::Plataforma && in_array(Papel::Administrador, $papeis, true)) {
                return true;
            }
        }

        return false;
    }
}
