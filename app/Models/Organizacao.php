<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cliente do CadGestor (organização). Nunca é excluído: só desativado.
 * Pelo RLS, a plataforma enxerga todos; um usuário de cliente, só o dele.
 *
 * @property string $id
 * @property string $nome razão social
 * @property string|null $cnpj
 * @property bool $ativo
 */
#[Table('organizacao')]
class Organizacao extends Model
{
    use HasUuids;

    public const CAMPOS_CONTATO = [
        'nome_fantasia', 'inscricao_municipal', 'inscricao_estadual', 'cep', 'logradouro', 'numero', 'complemento',
        'bairro', 'municipio', 'uf', 'telefone', 'email_financeiro', 'contato_nome', 'contato_telefone',
    ];

    /** @var list<string> */
    protected $fillable = ['nome', 'cnpj', ...self::CAMPOS_CONTATO];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'termos_aceitos_em' => 'datetime'];
    }

    /** @return HasOne<Assinatura, $this> */
    public function assinatura(): HasOne
    {
        return $this->hasOne(Assinatura::class, 'organizacao_id');
    }

    /** @return HasMany<Fatura, $this> */
    public function faturas(): HasMany
    {
        return $this->hasMany(Fatura::class, 'organizacao_id')->orderByDesc('vencimento');
    }

    /** CNPJ, endereço, telefone e e-mail financeiro: necessários para a nota fiscal. */
    public function cadastroCompleto(): bool
    {
        foreach (['cnpj', 'cep', 'logradouro', 'numero', 'bairro', 'municipio', 'uf', 'telefone', 'email_financeiro'] as $campo) {
            if (blank($this->{$campo})) {
                return false;
            }
        }

        return true;
    }
}
