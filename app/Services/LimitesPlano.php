<?php

namespace App\Services;

use App\Enums\Papel;
use App\Mail\LimiteDoPlano;
use App\Models\Assinatura;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Limites do plano (pessoas e catracas ativas) do cliente do contexto.
 *
 * Decisão de produto: ao atingir o limite, o CADASTRO PARA até o cliente
 * trocar de plano, e o cliente é notificado (aviso na tela a partir de 90%
 * do limite e e-mail aos administradores ao atingir o limite).
 */
class LimitesPlano
{
    public const PERCENTUAL_AVISO = 90;

    private const RECURSOS = [
        'pessoas' => ['limite_pessoas', 'pessoas ativas'],
        'catracas' => ['limite_catracas', 'catracas ativas'],
    ];

    public function assinatura(): ?Assinatura
    {
        return Assinatura::query()->with('plano')->whereRaw('organizacao_id = cg_org_atual()')->first();
    }

    /** @return array{pessoas: int, catracas: int} */
    public function uso(): array
    {
        $linha = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM pessoa WHERE ativo AND anonimizada_em IS NULL AND organizacao_id = cg_org_atual()) AS pessoas,
                   (SELECT count(*) FROM catraca WHERE ativo AND organizacao_id = cg_org_atual()) AS catracas
            SQL);

        return ['pessoas' => (int) $linha->pessoas, 'catracas' => (int) $linha->catracas];
    }

    /**
     * Antes de cadastrar (ou reativar) uma pessoa ou catraca.
     *
     * @param  'pessoas'|'catracas'  $recurso
     *
     * @throws ValidationException
     */
    public function garantirCapacidade(string $recurso): void
    {
        [$coluna, $rotulo] = self::RECURSOS[$recurso];
        $assinatura = $this->assinatura();

        if ($assinatura === null) {
            throw ValidationException::withMessages(['plano' => 'Cliente sem assinatura ativa. Fale com o comercial do CadGestor.']);
        }

        $limite = (int) $assinatura->plano->{$coluna};

        if ($this->uso()[$recurso] >= $limite) {
            $this->notificarLimiteAtingido($assinatura, $recurso, $limite);

            throw ValidationException::withMessages(['plano' => sprintf(
                'O plano %s permite até %d %s e esse limite foi atingido. Para cadastrar mais, troque de plano em "Minha assinatura".',
                $assinatura->plano->nome,
                $limite,
                $rotulo,
            )]);
        }
    }

    /**
     * Avisos para a faixa do topo quando o uso passa de 90% do limite.
     *
     * @return list<string>
     */
    public function avisos(): array
    {
        $assinatura = $this->assinatura();

        if ($assinatura === null) {
            return [];
        }

        $uso = $this->uso();
        $avisos = [];

        foreach (self::RECURSOS as $recurso => [$coluna, $rotulo]) {
            $limite = (int) $assinatura->plano->{$coluna};

            if ($limite > 0 && $limite * self::PERCENTUAL_AVISO <= $uso[$recurso] * 100) {
                $avisos[] = $uso[$recurso] >= $limite
                    ? "Limite do plano atingido: {$uso[$recurso]} de {$limite} {$rotulo}. Novos cadastros estão bloqueados até a troca de plano."
                    : "Você está usando {$uso[$recurso]} de {$limite} {$rotulo} do seu plano.";
            }
        }

        return $avisos;
    }

    /** E-mail aos administradores do cliente, no máximo uma vez por dia para cada recurso. */
    private function notificarLimiteAtingido(Assinatura $assinatura, string $recurso, int $limite): void
    {
        $chave = "limite_avisado:{$assinatura->organizacao_id}:{$recurso}:".now()->toDateString();

        if (! Cache::add($chave, true, now()->endOfDay())) {
            return;
        }

        $emails = DB::connection('pgsql')->table('usuario_sistema as u')
            ->join('usuario_papel as p', 'p.usuario_id', '=', 'u.id')
            ->whereRaw('u.organizacao_id = cg_org_atual()')
            ->where('u.ativo', true)
            ->where('p.papel', Papel::Administrador->value)
            ->pluck('u.email');

        foreach ($emails as $email) {
            Mail::to($email)->send(new LimiteDoPlano($assinatura->plano->nome, self::RECURSOS[$recurso][1], $limite));
        }
    }
}
