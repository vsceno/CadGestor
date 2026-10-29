<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Regra de acesso de um perfil numa empresa: em qual catraca (ou em todas),
 * em qual dia e em qual horário as pessoas desse perfil passam.
 *
 * @property string $empresa_id
 * @property string $perfil_id
 * @property string|null $catraca_id null = todas as catracas da empresa
 * @property int $dia_semana
 * @property string $hora_inicio
 * @property string $hora_fim
 */
#[Table('regra_acesso')]
class RegraAcesso extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['empresa_id', 'perfil_id', 'catraca_id', 'dia_semana', 'hora_inicio', 'hora_fim', 'ativo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }
}
