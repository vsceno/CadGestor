@php use App\Support\Formato; @endphp

<x-layouts.app titulo="Painel da plataforma">
    <x-cabecalho-pagina titulo="Painel da plataforma"
        subtitulo="A plataforma controla os clientes: cadastro, plano e cobrança. O ambiente de cada cliente pertence a ele.">
        <x-slot:acoes>
            <x-botao variante="primaria" icone="adicionar" :href="route('plataforma.clientes.novo')">Novo cliente</x-botao>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-indicador tipo="destaque" rotulo="Receita recorrente mensal" :valor="Formato::moeda($totais['receita'])" icone="financeiro" />
        <x-indicador rotulo="Clientes ativos" :valor="$totais['ativos']" icone="clientes" :href="route('plataforma.clientes.index')" />
        <x-indicador rotulo="Em teste grátis" :valor="$totais['teste']" icone="calendario" />
        <x-indicador rotulo="Inadimplentes" :valor="$totais['inadimplentes']" icone="atencao" :tipo="$totais['inadimplentes'] > 0 ? 'perigo' : 'normal'" />
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-cartao titulo="Clientes por plano">
            <x-grafico-barras titulo="Clientes ativos em cada plano" :dados="$porPlano" />
        </x-cartao>

        <x-cartao titulo="Clientes mais recentes" :preenchimento="false">
            @if ($recentes->isEmpty())
                <x-estado-vazio titulo="Nenhum cliente ainda" descricao="Cadastre o primeiro cliente para começar.">
                    <x-slot:acao><x-botao variante="primaria" icone="adicionar" :href="route('plataforma.clientes.novo')">Novo cliente</x-botao></x-slot:acao>
                </x-estado-vazio>
            @else
                <ul class="divide-y divide-borda">
                    @foreach ($recentes as $cliente)
                        @php $situacao = $cliente->assinatura?->situacao(); @endphp
                        <li class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="min-w-0">
                                <a href="{{ route('plataforma.clientes.show', $cliente) }}" class="font-medium text-texto no-underline hover:text-primaria hover:underline">{{ $cliente->nome }}</a>
                                <p class="text-sm text-suave">{{ $cliente->assinatura?->plano?->nome ?? 'Sem plano' }} · desde {{ Formato::data($cliente->created_at) }}</p>
                            </div>
                            @if (! $cliente->ativo)
                                <x-status tipo="inativo">Desativado</x-status>
                            @elseif ($situacao)
                                <x-status :tipo="$situacao->tipoStatus()">{{ $cliente->assinatura->rotuloStatus() }}</x-status>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-cartao>
    </div>
</x-layouts.app>
