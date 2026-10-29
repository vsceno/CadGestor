<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Versão publicada do termo de consentimento. Depois de publicada, a versão não
 * muda no banco (trigger): o titular aceita AQUELE texto.
 *
 * @property string $id
 * @property string $versao
 * @property string $texto
 * @property string $hash_texto
 */
#[Table('termo_consentimento')]
class TermoConsentimento extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['versao', 'finalidade', 'texto', 'hash_texto', 'vigente_desde', 'ativo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vigente_desde' => 'datetime', 'ativo' => 'boolean'];
    }
}
