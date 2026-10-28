<?php

namespace App\Services\Plataforma;

use App\Models\Organizacao;
use App\Models\Plano;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Números da plataforma: clientes por situação, receita recorrente e
 * distribuição por plano. Só dados comerciais e contagens (nada do
 * ambiente dos clientes).
 */
class IndicadoresClientes
{
    /**
     * Lista de clientes com assinatura, plano, situação e contagens.
     *
     * @return Collection<int, Organizacao>
     */
    public function clientes(): Collection
    {
        $resumo = collect(DB::connection('pgsql')->select('SELECT * FROM cg_resumo_organizacoes()'))->keyBy('organizacao_id');

        return Organizacao::query()->with('assinatura.plano')->orderByDesc('ativo')->orderBy('nome')->get()
            ->each(function (Organizacao $cliente) use ($resumo): void {
                $contagem = $resumo->get($cliente->id);
                $cliente->setAttribute('total_pessoas', (int) ($contagem->pessoas ?? 0));
                $cliente->setAttribute('total_catracas', (int) ($contagem->catracas ?? 0));
                $cliente->setAttribute('total_usuarios', (int) ($contagem->usuarios ?? 0));
            });
    }

    /**
     * @param  Collection<int, Organizacao>  $clientes
     * @return array{ativos: int, teste: int, inadimplentes: int, cancelados: int, desativados: int, receita: int}
     */
    public function totais(Collection $clientes): array
    {
        $totais = ['ativos' => 0, 'teste' => 0, 'inadimplentes' => 0, 'cancelados' => 0, 'desativados' => 0, 'receita' => 0];

        foreach ($clientes as $cliente) {
            if (! $cliente->ativo) {
                $totais['desativados']++;

                continue;
            }

            $status = $cliente->assinatura?->status;
            match ($status) {
                'ativa' => $totais['ativos']++,
                'teste' => $totais['teste']++,
                'inadimplente' => $totais['inadimplentes']++,
                'cancelada' => $totais['cancelados']++,
                default => null,
            };

            if (in_array($status, ['ativa', 'inadimplente'], true)) {
                $totais['receita'] += (int) $cliente->assinatura->plano->preco_centavos;
            }
        }

        return $totais;
    }

    /**
     * Clientes ativos por plano (para o gráfico e para o destaque do plano mais contratado).
     *
     * @return Collection<string, int> código do plano => quantidade
     */
    public function porPlano(): Collection
    {
        return collect(DB::connection('pgsql')->select(<<<'SQL'
            SELECT p.codigo, count(a.id) AS total
              FROM plano p
              LEFT JOIN assinatura a ON a.plano_id = p.id AND a.status IN ('teste', 'ativa', 'inadimplente')
              LEFT JOIN organizacao o ON o.id = a.organizacao_id AND o.ativo
             WHERE a.id IS NULL OR o.id IS NOT NULL
             GROUP BY p.codigo, p.ordem
             ORDER BY p.ordem
            SQL))->mapWithKeys(fn (object $linha): array => [$linha->codigo => (int) $linha->total]);
    }

    public function planoMaisContratado(): ?string
    {
        $porPlano = $this->porPlano()->filter(fn (int $total): bool => $total > 0)->sortDesc();

        return $porPlano->keys()->first();
    }

    /** @return Collection<int, Plano> */
    public function planos(): Collection
    {
        return Plano::query()->orderBy('ordem')->orderBy('preco_centavos')->get();
    }
}
