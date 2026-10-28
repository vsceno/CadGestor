<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Plano vendido pela plataforma (catálogo único). Todos leem; só a
 * plataforma altera (RLS).
 *
 * @property string $id
 * @property string $codigo
 * @property string $nome
 * @property string|null $descricao
 * @property int $preco_centavos
 * @property int $limite_pessoas
 * @property int $limite_catracas
 * @property bool $disponivel_venda
 * @property int $ordem
 */
#[Table('plano')]
class Plano extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['codigo', 'nome', 'descricao', 'preco_centavos', 'limite_pessoas', 'limite_catracas', 'disponivel_venda', 'ordem'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'preco_centavos' => 'integer',
            'limite_pessoas' => 'integer',
            'limite_catracas' => 'integer',
            'disponivel_venda' => 'boolean',
            'ordem' => 'integer',
        ];
    }
}
