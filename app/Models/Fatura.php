<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fatura (cobrança). O pagamento acontece no meio de pagamento: nenhum dado
 * de cartão passa pelo CadGestor.
 * status: pendente | paga | vencida | cancelada
 *
 * @property string $id
 * @property string $assinatura_id
 * @property int $valor_centavos
 * @property Carbon $vencimento
 * @property string $status
 */
#[Table('fatura')]
class Fatura extends Model
{
    use HasUuids;

    public const ROTULOS = ['pendente' => 'A pagar', 'paga' => 'Paga', 'vencida' => 'Vencida', 'cancelada' => 'Cancelada'];

    /** Tipo do <x-status> para cada situação. */
    public const TIPOS_STATUS = ['pendente' => 'atencao', 'paga' => 'ativo', 'vencida' => 'negado', 'cancelada' => 'inativo'];

    /** @var list<string> */
    protected $fillable = ['assinatura_id', 'gateway', 'gateway_cobranca_id', 'valor_centavos', 'vencimento', 'status', 'link_pagamento'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vencimento' => 'date', 'pago_em' => 'datetime', 'valor_centavos' => 'integer'];
    }
}
