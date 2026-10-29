<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Alerta para a equipe de segurança: negações, falhas de sincronização e similares.
 * Fica pendente até alguém tratar; o tratamento guarda quem, quando e a observação.
 *
 * @property string $id
 * @property string $tipo
 * @property string $descricao
 * @property Carbon $data_hora
 * @property Carbon|null $tratado_em
 */
#[Table('alerta')]
class Alerta extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['tipo', 'descricao', 'catraca_id', 'pessoa_id', 'evento_id', 'tratado_por', 'tratado_em', 'observacao'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data_hora' => 'datetime', 'tratado_em' => 'datetime'];
    }

    /** @return BelongsTo<Catraca, $this> */
    public function catraca(): BelongsTo
    {
        return $this->belongsTo(Catraca::class, 'catraca_id');
    }
}
