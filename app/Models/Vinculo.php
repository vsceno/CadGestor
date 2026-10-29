<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Vínculo de uma pessoa com uma empresa, num perfil, com validade. Nunca é
 * apagado: ao encerrar, a data de fim e o motivo ficam no histórico.
 *
 * @property string $id
 * @property string $pessoa_id
 * @property string $empresa_id
 * @property string $perfil_id
 * @property Carbon $data_inicio
 * @property Carbon|null $data_fim
 * @property string $status
 */
#[Table('vinculo')]
class Vinculo extends Model
{
    use HasUuids;

    public const STATUS = [
        'ativo' => 'Ativo',
        'suspenso' => 'Suspenso',
        'encerrado' => 'Encerrado',
    ];

    /** @var list<string> */
    protected $fillable = [
        'pessoa_id', 'empresa_id', 'perfil_id', 'data_inicio', 'data_fim', 'status',
        'motivo_encerramento', 'encerrado_em',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['data_inicio' => 'date', 'data_fim' => 'date', 'encerrado_em' => 'datetime'];
    }

    /** @return BelongsTo<Pessoa, $this> */
    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id');
    }

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    /** @return BelongsTo<PerfilVinculo, $this> */
    public function perfil(): BelongsTo
    {
        return $this->belongsTo(PerfilVinculo::class, 'perfil_id');
    }
}
