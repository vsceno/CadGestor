<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Operação pendente para uma catraca: cadastrar ou remover o template de uma
 * biometria. A catraca busca, executa e informa o resultado.
 *
 * @property string $id
 * @property string $catraca_id
 * @property string $operacao
 * @property string $status
 */
#[Table('fila_sincronizacao')]
class FilaSincronizacao extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['catraca_id', 'pessoa_id', 'biometria_id', 'operacao'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['agendado_para' => 'datetime', 'processado_em' => 'datetime', 'tentativas' => 'integer'];
    }
}
