<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Registro de biometria. O template é guardado cifrado e NUNCA é carregado
 * pelos modelos de tela: a gravação e a leitura do template são feitas pelo
 * BiometriaServico, com SQL explícito em hexadecimal.
 *
 * @property string $id
 * @property string $pessoa_id
 * @property string $tipo
 * @property int|null $dedo
 * @property bool $ativo
 * @property Carbon|null $excluido_em
 */
#[Table('biometria')]
class Biometria extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $hidden = ['template_cifrado', 'nonce', 'hash_integridade'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'excluido_em' => 'datetime', 'dedo' => 'integer'];
    }

    /** @return BelongsTo<Pessoa, $this> */
    public function pessoa(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'pessoa_id');
    }
}
