<x-layouts.app titulo="Eventos e alertas">
    <x-cabecalho-pagina titulo="Eventos e alertas"
        subtitulo="Passagens do período e os alertas que ainda precisam de tratamento." />

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="xl:col-span-2">
            <x-cartao :preenchimento="false">
                <div class="p-5">
                    <x-tabela id="eventos" legenda="Passagens do período" :vazia="$eventos->isEmpty()" :colunas="['catraca' => 'Catraca', 'origem' => 'Origem']">
                        <x-slot:filtros>
                            {{-- POST: a data, a catraca e o pessoa pesquisada não ficam na URL. --}}
                            <form method="POST" action="{{ route('eventos.busca') }}" class="flex flex-wrap items-end gap-2" role="search">
                                @csrf
                                <div class="w-40">
                                    <x-campo nome="inicio" tipo="date" rotulo="De" :valor="$filtros['inicio']->toDateString()" sem-opcional />
                                </div>
                                <div class="w-40">
                                    <x-campo nome="fim" tipo="date" rotulo="Até" :valor="$filtros['fim']->toDateString()" sem-opcional />
                                </div>
                                <div class="w-48">
                                    <x-selecao nome="catraca" rotulo="Catraca" :opcoes="$catracas->pluck('nome', 'id')->all()" vazio="Todas" :valor="$filtros['catraca']" sem-opcional />
                                </div>
                                <div class="w-40">
                                    <x-selecao nome="resultado" rotulo="Resultado" :opcoes="['liberado' => 'Liberado', 'negado' => 'Negado']" vazio="Todos" :valor="$filtros['resultado']" sem-opcional />
                                </div>
                                <x-botao variante="primaria" type="submit" icone="filtro">Filtrar</x-botao>
                            </form>
                        </x-slot:filtros>

                        <x-slot:cabecalho>
                            <tr>
                                <x-tabela.coluna>Quando</x-tabela.coluna>
                                <x-tabela.coluna coluna="catraca">Catraca</x-tabela.coluna>
                                <x-tabela.coluna>Pessoa</x-tabela.coluna>
                                <x-tabela.coluna>Resultado</x-tabela.coluna>
                                <x-tabela.coluna coluna="origem">Origem</x-tabela.coluna>
                            </tr>
                        </x-slot:cabecalho>
                        <x-slot:vazio>
                            <x-estado-vazio titulo="Nenhuma passagem neste período" descricao="Mude as datas ou os filtros." />
                        </x-slot:vazio>

                        @foreach ($eventos as $evento)
                            <tr>
                                <td class="whitespace-nowrap">{{ \App\Support\Formato::dataHora($evento->data_hora) }}</td>
                                <td data-coluna="catraca">{{ $evento->catraca->nome }}</td>
                                <td>{{ $evento->pessoa?->nome ?? 'Não identificada' }}</td>
                                <td>
                                    @if ($evento->resultado === 'liberado')
                                        <x-status tipo="ativo">Liberado</x-status>
                                    @else
                                        <x-status tipo="negado">Negado</x-status>
                                        <span class="block text-xs text-suave">{{ $rotulos[$evento->motivo_negacao] ?? 'motivo não informado' }}</span>
                                    @endif
                                </td>
                                <td data-coluna="origem">{{ $evento->offline ? 'Sem servidor' : 'Online' }}</td>
                            </tr>
                        @endforeach

                        <x-slot:rodape>
                            <span>{{ $eventos->count() }} {{ $eventos->count() === 1 ? 'passagem' : 'passagens' }}{{ $eventos->count() >= 300 ? ' (as 300 mais recentes)' : '' }}</span>
                        </x-slot:rodape>
                    </x-tabela>
                </div>
            </x-cartao>
        </div>

        <div>
            <x-cartao>
                <div class="flex flex-col gap-4">
                    <h2 class="text-lg">Alertas pendentes</h2>

                    @forelse ($alertas as $alerta)
                        <article class="flex flex-col gap-3 rounded-controle border border-borda p-4">
                            <div>
                                <p class="text-sm font-medium">{{ $alerta->descricao }}</p>
                                <p class="text-xs text-suave">
                                    {{ $alerta->catraca?->nome ?? 'Sem catraca' }} · {{ \App\Support\Formato::dataHora($alerta->data_hora) }}
                                </p>
                            </div>
                            <form method="POST" action="{{ route('alertas.tratar', $alerta) }}" class="flex flex-col gap-2">
                                @csrf
                                <x-campo :id="'observacao-'.$alerta->id" nome="observacao" rotulo="Observação" sem-opcional maxlength="500"
                                    ajuda="O que foi feito. Fica registrado com o seu nome." />
                                <div class="flex justify-end">
                                    <x-botao variante="secundaria" tamanho="pequeno" type="submit" icone="marcado">Marcar como tratado</x-botao>
                                </div>
                            </form>
                        </article>
                    @empty
                        <x-estado-vazio icone="alertas" titulo="Nenhum alerta pendente" descricao="Os alertas de negação e de falha aparecem aqui." />
                    @endforelse
                </div>
            </x-cartao>
        </div>
    </div>
</x-layouts.app>
