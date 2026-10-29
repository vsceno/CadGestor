<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Alerta para a equipe de segurança: negações, falhas de sincronização e similares.
 *
 * @property string $id
 * @property string $tipo
 * @property string $descricao
 * @property Carbon $data_hora
 */
#[Table('alerta')]
class Alerta extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['tipo', 'descricao', 'catraca_id', 'pessoa_id', 'evento_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data_hora' => 'datetime'];
    }
}
