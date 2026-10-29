<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cada tentativa de passagem na catraca. Registro imutável: a aplicação só insere.
 *
 * @property string $id
 * @property string $catraca_id
 * @property string $resultado
 * @property string|null $motivo_negacao
 * @property Carbon $data_hora
 */
#[Table('evento_acesso')]
class EventoAcesso extends Model
{
    use HasUuids;

    /** Não há updated_at: o evento nunca é alterado. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'catraca_id', 'empresa_id', 'pessoa_id', 'vinculo_id', 'perfil_id', 'data_hora',
        'tipo_identificacao', 'resultado', 'motivo_negacao', 'score', 'offline',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data_hora' => 'datetime', 'offline' => 'boolean'];
    }

    /** @return BelongsTo<Catraca, $this> */
    public function catraca(): BelongsTo
    {
        return $this->belongsTo(Catraca::class, 'catraca_id');
    }

    /** @return BelongsTo<Pessoa, $this> */
    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id');
    }

    /** @return BelongsTo<PerfilVinculo, $this> */
    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilVinculo::class, 'perfil_id');
    }
}
