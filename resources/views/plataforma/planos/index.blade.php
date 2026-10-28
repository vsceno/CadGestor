@php use App\Support\Formato; @endphp

<x-layouts.app titulo="Planos">
    <x-cabecalho-pagina titulo="Planos" subtitulo="Catálogo vendido aos clientes. Mudanças de preço valem a partir da próxima cobrança.">
        <x-slot:acoes>
            <x-botao variante="primaria" icone="adicionar" :href="route('plataforma.planos.criar')">Novo plano</x-botao>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    @if ($planos->isEmpty())
        <x-estado-vazio icone="planos" titulo="Nenhum plano cadastrado" descricao="Crie o primeiro plano para começar a vender.">
            <x-slot:acao><x-botao variante="primaria" icone="adicionar" :href="route('plataforma.planos.criar')">Novo plano</x-botao></x-slot:acao>
        </x-estado-vazio>
    @else
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($planos as $plano)
                @php $destaque = $plano->codigo === $maisContratado; @endphp
                <article @class([
                    'relative flex flex-col gap-4 rounded-cartao border bg-superficie p-6 shadow-cartao',
                    'border-primaria ring-2 ring-primaria/30' => $destaque,
                    'border-borda' => ! $destaque,
                ])>
                    @if ($destaque)
                        <span class="absolute -top-3 left-6 rounded-full bg-primaria px-3 py-0.5 text-xs font-semibold text-sobre-primaria">Mais contratado</span>
                    @endif
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg">{{ $plano->nome }}</h2>
                            <p class="text-xs text-suave">Código: {{ $plano->codigo }}</p>
                        </div>
                        <x-status :tipo="$plano->disponivel_venda ? 'ativo' : 'inativo'">{{ $plano->disponivel_venda ? 'À venda' : 'Fora de venda' }}</x-status>
                    </div>
                    <p class="numeros text-3xl font-bold">{{ Formato::moeda($plano->preco_centavos) }}<span class="text-base font-medium text-suave">/mês</span></p>
                    @if ($plano->descricao)<p class="text-sm text-suave">{{ $plano->descricao }}</p>@endif
                    <ul class="flex flex-col gap-2 text-sm">
                        <li class="flex items-center gap-2"><x-icone nome="pessoas" class="size-4 text-primaria" /> Até {{ Formato::numero($plano->limite_pessoas) }} pessoas ativas</li>
                        <li class="flex items-center gap-2"><x-icone nome="catraca" class="size-4 text-primaria" /> Até {{ $plano->limite_catracas }} catracas</li>
                        <li class="flex items-center gap-2"><x-icone nome="lgpd" class="size-4 text-primaria" /> Consentimento e biometria com LGPD</li>
                        <li class="flex items-center gap-2"><x-icone nome="clientes" class="size-4 text-primaria" /> {{ $porPlano[$plano->codigo] ?? 0 }} cliente(s) neste plano</li>
                    </ul>
                    <x-botao class="mt-auto" icone="editar" :href="route('plataforma.planos.editar', $plano)">Editar plano</x-botao>
                </article>
            @endforeach
        </div>
    @endif
</x-layouts.app>
