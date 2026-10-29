<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Horário padrão de funcionamento da empresa: último nível da hierarquia de
 * regras (vínculo > perfil na empresa > horário padrão).
 *
 * @property string $empresa_id
 * @property int $dia_semana
 * @property string $hora_inicio
 * @property string $hora_fim
 */
#[Table('empresa_horario_padrao')]
class EmpresaHorario extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['empresa_id', 'dia_semana', 'hora_inicio', 'hora_fim'];
}
