<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Aceite do titular ao termo de consentimento. Revogar marca a data; o registro
 * fica, como prova do que foi aceito e de quando foi retirado.
 *
 * @property string $id
 * @property string $pessoa_id
 * @property string $versao_termo
 * @property string $canal
 * @property bool $titular_menor
 * @property Carbon $aceito_em
 * @property Carbon|null $revogado_em
 */
#[Table('consentimento')]
class Consentimento extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'pessoa_id', 'termo_id', 'finalidade', 'versao_termo', 'hash_termo', 'aceito_em', 'ip', 'canal',
        'titular_menor', 'responsavel_nome', 'responsavel_cpf', 'responsavel_parentesco', 'registrado_por',
    ];

    /** Dados do responsável legal ficam fora de qualquer serialização para tela ou API. */
    /** @var list<string> */
    protected $hidden = ['responsavel_nome', 'responsavel_cpf'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aceito_em' => 'datetime', 'revogado_em' => 'datetime', 'titular_menor' => 'boolean'];
    }

    /** @return BelongsTo<Pessoa, $this> */
    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id');
    }
}
