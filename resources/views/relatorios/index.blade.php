<x-layouts.app titulo="Relatórios">
    <x-cabecalho-pagina titulo="Relatórios" subtitulo="Passagens do período: totais, dia a dia e os motivos das negações." />

    <x-cartao>
        <form method="POST" action="{{ route('relatorios.busca') }}" class="flex flex-wrap items-end gap-2" role="search">
            @csrf
            <div class="w-44">
                <x-campo nome="inicio" tipo="date" rotulo="De" :valor="$filtros['inicio']->toDateString()" sem-opcional />
            </div>
            <div class="w-44">
                <x-campo nome="fim" tipo="date" rotulo="Até" :valor="$filtros['fim']->toDateString()" sem-opcional />
            </div>
            <x-botao variante="primaria" type="submit" icone="filtro">Gerar</x-botao>
        </form>
    </x-cartao>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-indicador tipo="destaque" rotulo="Passagens" :valor="\App\Support\Formato::numero($totais['passagens'])" icone="portaria" />
        <x-indicador rotulo="Liberadas" :valor="\App\Support\Formato::numero($totais['liberadas'])" icone="sucesso" />
        <x-indicador rotulo="Negadas" :valor="\App\Support\Formato::numero($totais['negadas'])" icone="erro" :tipo="$totais['negadas'] > 0 ? 'perigo' : 'normal'" />
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-cartao>
            <x-grafico-barras titulo="Passagens por dia" :dados="$porDia" />
        </x-cartao>
        <x-cartao>
            <x-grafico-barras titulo="Motivos das negações" :dados="$motivos" />
        </x-cartao>
    </div>

    @if (auth()->user()->temPapel(\App\Enums\Papel::Administrador))
        <x-cartao>
            <form method="POST" action="{{ route('relatorios.exportar') }}" class="flex flex-col gap-3">
                @csrf
                <input type="hidden" name="inicio" value="{{ $filtros['inicio']->toDateString() }}">
                <input type="hidden" name="fim" value="{{ $filtros['fim']->toDateString() }}">
                <div>
                    <h2 class="text-lg">Exportar as passagens</h2>
                    <p class="text-sm text-suave">
                        O arquivo traz os nomes das pessoas. A exportação fica registrada na auditoria, com o período e a quantidade.
                    </p>
                </div>
                <div class="flex justify-end">
                    <x-botao variante="secundaria" type="submit" icone="baixar">Baixar CSV do período</x-botao>
                </div>
            </form>
        </x-cartao>
    @endif
</x-layouts.app>
