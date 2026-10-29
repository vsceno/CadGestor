<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Catraca do cliente (uma ou mais). O token de conexão do equipamento fica
 * só como hash: o texto aparece uma única vez, logo após ser gerado.
 *
 * @property string $id
 * @property string $empresa_id
 * @property string $nome
 * @property string $identificador_dispositivo
 * @property bool $ativo
 */
#[Table('catraca')]
class Catraca extends Model
{
    use HasUuids;

    public const TIPOS_IDENTIFICACAO = [
        'digital' => 'Biometria digital',
        'facial' => 'Reconhecimento facial',
        'digital_facial' => 'Digital e facial',
    ];

    public const MODOS_FALHA = [
        'bloquear' => 'Bloquear: sem servidor, ninguém passa',
        'liberar' => 'Liberar: sem servidor, a catraca libera',
    ];

    /** @var list<string> */
    protected $fillable = [
        'empresa_id', 'nome', 'localizacao', 'identificador_dispositivo', 'tipo_identificacao', 'modo_falha',
        'fabricante', 'modelo', 'ativo', 'token_hash',
    ];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'ultimo_contato_em' => 'datetime'];
    }

    /** @return BelongsTo<Empresa, $this> */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
