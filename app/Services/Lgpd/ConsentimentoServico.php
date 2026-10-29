<?php

namespace App\Services\Lgpd;

use App\Models\Consentimento;
use App\Models\Pessoa;
use App\Services\Auditoria;
use App\Support\Documento;
use App\Support\Idade;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aceite e revogação do consentimento. Uma pessoa tem no máximo um consentimento
 * ativo. Menor de 18 anos só entra com os dados e o aceite do responsável legal.
 */
class ConsentimentoServico
{
    /** Parentesco do responsável legal (LGPD, art. 14). */
    public const PARENTESCOS = [
        'mae' => 'Mãe',
        'pai' => 'Pai',
        'tutor' => 'Tutor(a) legal',
        'guardiao' => 'Guardião(ã) judicial',
        'outro' => 'Outro responsável legal',
    ];

    public function __construct(
        private readonly Auditoria $auditoria,
        private readonly TermoServico $termos,
        private readonly ExclusaoBiometria $exclusao,
    ) {}

    public function ativo(Pessoa $pessoa): ?Consentimento
    {
        return Consentimento::query()
            ->where('pessoa_id', $pessoa->id)
            ->whereNull('revogado_em')
            ->first();
    }

    /** @param  array<string, mixed>  $entrada */
    public function registrar(Pessoa $pessoa, array $entrada, string $ip): Consentimento
    {
        if ($pessoa->anonimizada_em !== null || ! $pessoa->ativo) {
            throw ValidationException::withMessages(['pessoa' => 'Pessoa inativa: o consentimento não pode ser registrado.']);
        }

        $termo = $this->termos->vigente()
            ?? throw ValidationException::withMessages(['termo' => 'Não há termo de consentimento publicado. Peça ao administrador.']);

        if ($this->ativo($pessoa) !== null) {
            throw ValidationException::withMessages(['termo' => 'A pessoa já possui consentimento ativo.']);
        }

        $faixa = Idade::faixa($pessoa->data_nascimento, now());

        if ($faixa === 'desconhecida') {
            throw ValidationException::withMessages([
                'data_nascimento' => 'Informe a data de nascimento antes de colher o consentimento: ela define se é preciso o responsável legal.',
            ]);
        }

        $erros = [];
        $canal = (string) ($entrada['canal'] ?? 'presencial');

        if (! in_array($canal, ['presencial', 'digital'], true)) {
            $erros['canal'] = 'Canal inválido.';
        }

        $menor = $faixa === 'menor';
        $responsavel = ['nome' => null, 'cpf' => null, 'parentesco' => null];

        if ($menor) {
            $responsavel = [
                'nome' => trim((string) ($entrada['responsavel_nome'] ?? '')),
                'cpf' => Documento::normalizarCpf((string) ($entrada['responsavel_cpf'] ?? '')),
                'parentesco' => (string) ($entrada['responsavel_parentesco'] ?? ''),
            ];

            if (mb_strlen((string) $responsavel['nome']) < 5 || ! str_contains((string) $responsavel['nome'], ' ')) {
                $erros['responsavel_nome'] = 'Informe o nome completo do responsável legal.';
            }

            if (! Documento::cpfValido($responsavel['cpf'])) {
                $erros['responsavel_cpf'] = 'CPF do responsável inválido.';
            } elseif ($responsavel['cpf'] === $pessoa->cpf) {
                $erros['responsavel_cpf'] = 'O responsável não pode ser o próprio titular.';
            }

            if (! array_key_exists((string) $responsavel['parentesco'], self::PARENTESCOS)) {
                $erros['responsavel_parentesco'] = 'Selecione o vínculo do responsável.';
            }

            if (($entrada['aceite_responsavel'] ?? '') !== '1') {
                $erros['aceite_responsavel'] = 'O responsável legal precisa declarar o aceite do termo.';
            }
        } elseif (($entrada['aceite_titular'] ?? '') !== '1') {
            $erros['aceite_titular'] = 'O titular precisa declarar que leu e aceita o termo.';
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        return DB::connection('pgsql')->transaction(function () use ($pessoa, $termo, $canal, $menor, $responsavel, $ip): Consentimento {
            $consentimento = Consentimento::query()->create([
                'pessoa_id' => $pessoa->id,
                'termo_id' => $termo->id,
                'finalidade' => TermoServico::FINALIDADE,
                'versao_termo' => $termo->versao,
                'hash_termo' => $termo->hash_texto,
                'aceito_em' => now(),
                'ip' => $ip,
                'canal' => $canal,
                'titular_menor' => $menor,
                'responsavel_nome' => $responsavel['nome'],
                'responsavel_cpf' => $responsavel['cpf'],
                'responsavel_parentesco' => $responsavel['parentesco'],
                'registrado_por' => Auth::id(),
            ]);

            // Sem os dados do responsável nem o CPF: a trilha guarda só o fato.
            $this->auditoria->registrar('consentimento_aceito', 'consentimento', $consentimento->id, null, [
                'pessoa_id' => $pessoa->id,
                'versao_termo' => $termo->versao,
                'titular_menor' => $menor,
                'canal' => $canal,
            ]);

            return $consentimento;
        });
    }

    /**
     * Revoga o consentimento ativo e apaga as biometrias. Devolve quantas foram apagadas.
     */
    public function revogar(Pessoa $pessoa, string $ip): int
    {
        $consentimento = $this->ativo($pessoa)
            ?? throw ValidationException::withMessages(['consentimento' => 'Não há consentimento ativo para revogar.']);

        return DB::connection('pgsql')->transaction(function () use ($pessoa, $consentimento, $ip): int {
            // Biometrias primeiro: o banco não aceita biometria ativa sem consentimento ativo.
            $excluidas = $this->exclusao->excluir($pessoa, 'revogacao_consentimento');

            $consentimento->forceFill(['revogado_em' => now(), 'revogacao_ip' => $ip])->save();

            $this->auditoria->registrar('consentimento_revogado', 'consentimento', $consentimento->id, null, [
                'pessoa_id' => $pessoa->id,
                'biometrias_excluidas' => count($excluidas),
            ]);

            return count($excluidas);
        });
    }
}
