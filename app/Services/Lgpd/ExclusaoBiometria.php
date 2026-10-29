<?php

namespace App\Services\Lgpd;

use App\Models\Biometria;
use App\Models\Pessoa;
use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Exclusão de biometrias. Não usa a chave de cifra: apagar não exige decifrar,
 * então revogar o consentimento funciona mesmo sem o arquivo de chaves.
 */
class ExclusaoBiometria
{
    public const MOTIVOS = ['revogacao_consentimento', 'retencao_vencida', 'substituicao', 'solicitacao_titular'];

    public function __construct(private readonly Auditoria $auditoria) {}

    /**
     * Apaga o template de fato (não só marca) e agenda a remoção nas catracas das
     * empresas onde a pessoa tem vínculo.
     *
     * @return list<string> ids das biometrias excluídas
     */
    public function excluir(Pessoa $pessoa, string $motivo, ?string $tipo = null, ?int $dedo = null): array
    {
        if (! in_array($motivo, self::MOTIVOS, true)) {
            throw new InvalidArgumentException('Motivo de exclusão de biometria inválido.');
        }

        $ativas = Biometria::query()
            ->where('pessoa_id', $pessoa->id)
            ->where('ativo', true)
            ->when($tipo !== null, fn ($consulta) => $consulta->where('tipo', $tipo))
            ->when($dedo !== null, fn ($consulta) => $consulta->where('dedo', $dedo))
            ->pluck('id')
            ->all();

        foreach ($ativas as $id) {
            DB::connection('pgsql')->update(<<<'SQL'
                UPDATE biometria
                   SET ativo = false, template_cifrado = NULL, nonce = NULL, hash_integridade = NULL,
                       excluido_em = now(), motivo_exclusao = ?
                 WHERE id = ?
                SQL, [$motivo, $id]);

            DB::connection('pgsql')->insert(<<<'SQL'
                INSERT INTO fila_sincronizacao (catraca_id, pessoa_id, biometria_id, operacao)
                SELECT c.id, ?, ?, 'remover'
                  FROM catraca c
                 WHERE c.empresa_id IN (SELECT v.empresa_id FROM vinculo v WHERE v.pessoa_id = ?)
                SQL, [$pessoa->id, $id, $pessoa->id]);

            $this->auditoria->registrar('biometria_excluida', 'biometria', $id, null, ['motivo' => $motivo]);
        }

        return $ativas;
    }
}
