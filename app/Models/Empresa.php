<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empresa atendida pelo cliente: quem tem pessoas com acesso (a própria
 * empresa do cliente, prestadoras, escolas...). Cada uma tem os perfis que
 * aceita e um horário padrão de funcionamento.
 *
 * @property string $id
 * @property string $razao_social
 * @property string $cnpj
 * @property bool $ativo
 */
#[Table('empresa')]
class Empresa extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'razao_social', 'nome_fantasia', 'cnpj', 'cep', 'logradouro', 'numero', 'complemento', 'bairro',
        'municipio', 'uf', 'responsavel_nome', 'responsavel_cargo', 'responsavel_email', 'responsavel_telefone', 'ativo',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    /** @return HasMany<Catraca, $this> */
    public function catracas(): HasMany
    {
        return $this->hasMany(Catraca::class, 'empresa_id')->orderBy('nome');
    }

    /** @return HasMany<EmpresaHorario, $this> */
    public function horarios(): HasMany
    {
        return $this->hasMany(EmpresaHorario::class, 'empresa_id')->orderBy('dia_semana')->orderBy('hora_inicio');
    }

    /** @return BelongsToMany<PerfilVinculo, $this> */
    public function perfis(): BelongsToMany
    {
        return $this->belongsToMany(PerfilVinculo::class, 'empresa_perfil', 'empresa_id', 'perfil_id');
    }
}
