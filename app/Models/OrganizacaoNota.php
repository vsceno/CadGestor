<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Anotação interna da plataforma sobre um cliente (o cliente não vê).
 * Só-acrescentar: não se edita nem se apaga.
 */
#[Table('organizacao_nota')]
class OrganizacaoNota extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['organizacao_id', 'autor_id', 'texto'];
}
