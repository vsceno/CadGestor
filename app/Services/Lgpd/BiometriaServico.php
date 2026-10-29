<?php

namespace App\Services\Lgpd;

use App\Models\Consentimento;
use App\Models\Pessoa;
use App\Services\Auditoria;
use App\Services\Biometria\CifradorBiometria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Cadastro e leitura de templates biométricos. Sem consentimento ativo nada é
 * gravado (também travado por trigger no banco). A exclusão fica em
 * ExclusaoBiometria, que não precisa da chave de cifra.
 */
class BiometriaServico
{
    public const TIPOS = ['facial', 'digital'];

    private const TAMANHO_MINIMO = 16;

    private const TAMANHO_MAXIMO = 65536;

    public function __construct(
        private readonly CifradorBiometria $cifrador,
        private readonly Auditoria $auditoria,
        private readonly ExclusaoBiometria $exclusao,
    ) {}

    /**
     * Cadastra (ou substitui) um template. Usado pela API das catracas: o template
     * chega do dispositivo já extraído, nunca a imagem bruta.
     */
    public function cadastrar(Pessoa $pessoa, string $tipo, ?int $dedo, string $template): string
    {
        if ($pessoa->anonimizada_em !== null || ! $pessoa->ativo) {
            throw ValidationException::withMessages(['pessoa' => 'Pessoa inativa: a biometria não pode ser cadastrada.']);
        }

        if (! in_array($tipo, self::TIPOS, true)) {
            throw ValidationException::withMessages(['tipo' => 'Tipo de biometria inválido.']);
        }

        if ($tipo === 'digital' && ($dedo === null || $dedo < 1 || $dedo > 10)) {
            throw ValidationException::withMessages(['dedo' => 'Informe o dedo (1 a 10).']);
        }

        $dedo = $tipo === 'facial' ? null : $dedo;
        $tamanho = strlen($template);

        if ($tamanho < self::TAMANHO_MINIMO || $tamanho > self::TAMANHO_MAXIMO) {
            throw ValidationException::withMessages(['template' => 'Template biométrico com tamanho inválido.']);
        }

        $consentimento = $this->consentimentoAtivo($pessoa);

        return DB::connection('pgsql')->transaction(function () use ($pessoa, $consentimento, $tipo, $dedo, $template): string {
            // Uma biometria ativa por pessoa, tipo e dedo: a nova substitui a anterior.
            $this->exclusao->excluir($pessoa, 'substituicao', $tipo, $dedo);

            $cifra = $this->cifrador->cifrar($template, CifradorBiometria::dadosAssociados($pessoa->id, $tipo, $dedo));
            $hash = $this->cifrador->hashIntegridade($template);

            $id = (string) DB::connection('pgsql')->scalar(<<<'SQL'
                INSERT INTO biometria (pessoa_id, consentimento_id, tipo, dedo, template_cifrado, nonce, versao_chave, hash_integridade)
                VALUES (?, ?, ?, ?, decode(?, 'hex'), decode(?, 'hex'), ?, ?)
                RETURNING id
                SQL, [
                $pessoa->id, $consentimento->id, $tipo, $dedo,
                bin2hex($cifra['cifrado']), bin2hex($cifra['nonce']), $cifra['versao'], $hash,
            ]);

            // Registra só o tipo e o dedo: nada do template.
            $this->auditoria->registrar('biometria_cadastrada', 'biometria', $id, null, [
                'pessoa_id' => $pessoa->id,
                'tipo' => $tipo,
                'dedo' => $dedo,
            ]);

            return $id;
        });
    }

    /**
     * Template em claro, só para envio à catraca. Nunca vai para tela nem para a auditoria.
     */
    public function template(string $biometriaId): string
    {
        $linha = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT pessoa_id, tipo, dedo, encode(template_cifrado, 'hex') AS cifrado,
                   encode(nonce, 'hex') AS nonce, versao_chave
              FROM biometria
             WHERE id = ? AND ativo
            SQL, [$biometriaId]);

        if ($linha === null) {
            throw new RuntimeException('Biometria ativa não encontrada.');
        }

        return $this->cifrador->decifrar(
            (string) hex2bin($linha->cifrado),
            (string) hex2bin($linha->nonce),
            (int) $linha->versao_chave,
            CifradorBiometria::dadosAssociados($linha->pessoa_id, $linha->tipo, $linha->dedo === null ? null : (int) $linha->dedo),
        );
    }

    private function consentimentoAtivo(Pessoa $pessoa): Consentimento
    {
        return Consentimento::query()
            ->where('pessoa_id', $pessoa->id)
            ->whereNull('revogado_em')
            ->first()
            ?? throw ValidationException::withMessages(['consentimento' => 'Biometria exige consentimento ativo do titular.']);
    }
}
