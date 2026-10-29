<?php

namespace App\Services\Lgpd;

use App\Models\TermoConsentimento;
use App\Services\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Termos de consentimento do cliente. Termo publicado não muda: uma alteração
 * vira uma nova versão, e cada aceite guarda a versão e o hash do texto aceito.
 */
class TermoServico
{
    public const FINALIDADE = 'controle_acesso_biometrico';

    private const TEXTO_MINIMO = 200;

    public function __construct(private readonly Auditoria $auditoria) {}

    public function vigente(): ?TermoConsentimento
    {
        return TermoConsentimento::query()
            ->where('finalidade', self::FINALIDADE)
            ->where('ativo', true)
            ->orderByDesc('vigente_desde')
            ->orderByDesc('created_at')
            ->first();
    }

    public function publicar(string $versao, string $texto): TermoConsentimento
    {
        $versao = trim($versao);
        $texto = trim(str_replace("\r\n", "\n", $texto));
        $erros = [];

        if (preg_match('/^[0-9A-Za-z.\-]{1,20}$/', $versao) !== 1) {
            $erros['versao'] = 'Versão com até 20 caracteres (ex.: 1.1).';
        } elseif (TermoConsentimento::query()->where('finalidade', self::FINALIDADE)->where('versao', $versao)->exists()) {
            $erros['versao'] = 'Esta versão já foi publicada. Termos publicados não mudam: use outro número.';
        }

        if (mb_strlen($texto) < self::TEXTO_MINIMO) {
            $erros['texto'] = 'O termo deve ter pelo menos '.self::TEXTO_MINIMO.' caracteres.';
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        return DB::connection('pgsql')->transaction(function () use ($versao, $texto): TermoConsentimento {
            $termo = TermoConsentimento::query()->create([
                'versao' => $versao,
                'finalidade' => self::FINALIDADE,
                'texto' => $texto,
                'hash_texto' => hash('sha256', $texto),
                'ativo' => true,
            ]);

            // Vigência pelo relógio do banco, com microssegundos: o Eloquent grava datas só
            // até o segundo, e um termo publicado no mesmo segundo do anterior perderia a vez.
            TermoConsentimento::query()->whereKey($termo->id)->update(['vigente_desde' => DB::raw('clock_timestamp()')]);
            $this->auditoria->registrar('criar', 'termo_consentimento', $termo->id, null, [
                'versao' => $versao,
                'hash_texto' => $termo->hash_texto,
            ]);

            return $termo;
        });
    }
}
