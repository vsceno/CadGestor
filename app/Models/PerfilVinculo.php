<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Perfil de vínculo (Funcionário, Aluno, Faxineiro...). Define a base legal
 * do tratamento de dados das pessoas com esse perfil (LGPD, art. 11).
 *
 * @property string $id
 * @property string $nome
 * @property string $base_legal
 * @property bool $base_legal_validada
 * @property bool $ativo
 */
#[Table('perfil_vinculo')]
class PerfilVinculo extends Model
{
    use HasUuids;

    public const BASES_LEGAIS = [
        'consentimento' => 'Consentimento específico do titular (art. 11, I)',
        'obrigacao_legal' => 'Cumprimento de obrigação legal ou regulatória (art. 11, II, a)',
        'exercicio_regular_direitos' => 'Exercício regular de direitos em contrato (art. 11, II, d)',
        'prevencao_fraude_seguranca' => 'Prevenção à fraude e segurança do titular (art. 11, II, g)',
    ];

    /** @var list<string> */
    protected $fillable = ['nome', 'descricao', 'base_legal', 'base_legal_validada', 'ativo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['base_legal_validada' => 'boolean', 'ativo' => 'boolean'];
    }
}
