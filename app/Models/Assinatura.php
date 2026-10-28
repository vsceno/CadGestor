<?php

namespace App\Models;

use App\Support\SituacaoAssinatura;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Assinatura do cliente (uma por organização).
 * status: teste | ativa | inadimplente | cancelada
 *
 * @property string $id
 * @property string $organizacao_id
 * @property string $plano_id
 * @property string $status
 * @property Carbon|null $teste_ate
 * @property Carbon|null $inadimplente_desde
 * @property Plano $plano
 */
#[Table('assinatura')]
class Assinatura extends Model
{
    use HasUuids;

    public const ROTULOS = ['teste' => 'Teste grátis', 'ativa' => 'Ativa', 'inadimplente' => 'Em atraso', 'cancelada' => 'Cancelada'];

    /** @var list<string> */
    protected $fillable = ['organizacao_id', 'plano_id', 'status', 'teste_ate', 'inadimplente_desde'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['teste_ate' => 'date', 'inadimplente_desde' => 'date', 'cancelada_em' => 'datetime'];
    }

    /** @return BelongsTo<Plano, $this> */
    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class, 'plano_id');
    }

    public function situacao(): SituacaoAssinatura
    {
        return SituacaoAssinatura::calcular(
            $this->status,
            $this->teste_ate?->toDateString(),
            $this->inadimplente_desde?->toDateString(),
            now()->toImmutable(),
        );
    }

    public function rotuloStatus(): string
    {
        return self::ROTULOS[$this->status] ?? $this->status;
    }
}
