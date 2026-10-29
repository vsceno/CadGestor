<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pessoa física. Um CPF é uma única pessoa dentro do cliente, que pode ter
 * vínculos com várias empresas. Nunca é excluída: é desativada e, depois do
 * prazo de retenção, anonimizada (Fase 8).
 *
 * @property string $id
 * @property string $nome
 * @property string $cpf
 * @property bool $ativo
 * @property Carbon|null $data_nascimento
 * @property Carbon|null $anonimizada_em
 */
#[Table('pessoa')]
class Pessoa extends Model
{
    use HasUuids;

    public const TIPOS_DOCUMENTO = [
        'rg' => 'RG',
        'cnh' => 'CNH',
        'passaporte' => 'Passaporte',
        'rnm' => 'RNM (estrangeiro)',
        'outro' => 'Outro',
    ];

    /** @var list<string> */
    protected $fillable = [
        'nome', 'cpf', 'documento_tipo', 'documento_numero', 'documento_orgao', 'documento_uf',
        'data_nascimento', 'email', 'telefone', 'ativo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'data_nascimento' => 'date', 'anonimizada_em' => 'datetime'];
    }

    /** @return HasMany<Vinculo, $this> */
    public function vinculos(): HasMany
    {
        return $this->hasMany(Vinculo::class, 'pessoa_id')->orderByDesc('data_inicio');
    }
}
