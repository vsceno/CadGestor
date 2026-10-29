<x-layouts.app titulo="Auditoria">
    <x-cabecalho-pagina titulo="Auditoria"
        subtitulo="Quem fez o quê e quando nas áreas do cliente. Os registros não podem ser alterados nem apagados." />

    <x-cartao :preenchimento="false">
        <div class="p-5">
            <x-tabela id="auditoria" legenda="Registros de auditoria" :vazia="$registros->isEmpty()" :colunas="['tabela' => 'Tabela', 'alteracoes' => 'Alterações']">
                <x-slot:filtros>
                    <form method="POST" action="{{ route('auditoria.busca') }}" class="flex flex-wrap items-end gap-2" role="search">
                        @csrf
                        <div class="w-56">
                            <x-selecao nome="acao" rotulo="Ação" :opcoes="array_combine($acoes, $acoes)" vazio="Todas" :valor="$filtros['acao']" sem-opcional />
                        </div>
                        <div class="w-56">
                            <x-campo nome="tabela" rotulo="Tabela" maxlength="60" :valor="$filtros['tabela']" placeholder="ex.: pessoa" sem-opcional />
                        </div>
                        <x-botao variante="primaria" type="submit" icone="filtro">Filtrar</x-botao>
                    </form>
                </x-slot:filtros>

                <x-slot:cabecalho>
                    <tr>
                        <x-tabela.coluna>Quando</x-tabela.coluna>
                        <x-tabela.coluna>Quem</x-tabela.coluna>
                        <x-tabela.coluna>Ação</x-tabela.coluna>
                        <x-tabela.coluna coluna="tabela">Tabela</x-tabela.coluna>
                        <x-tabela.coluna coluna="alteracoes">Alterações</x-tabela.coluna>
                    </tr>
                </x-slot:cabecalho>
                <x-slot:vazio>
                    <x-estado-vazio titulo="Nenhum registro com estes filtros" descricao="Mude a ação ou a tabela." />
                </x-slot:vazio>

                @foreach ($registros as $registro)
                    <tr>
                        <td class="whitespace-nowrap">{{ \App\Support\Formato::dataHora($registro->data_hora) }}</td>
                        <td>{{ $registro->usuario ?? 'Sistema' }}</td>
                        <td class="font-medium">{{ $registro->acao }}</td>
                        <td data-coluna="tabela" class="numeros text-suave">{{ $registro->tabela ?? '—' }}</td>
                        <td data-coluna="alteracoes" class="max-w-md">
                            @if ($registro->dados_depois)
                                <code class="block break-all text-xs">{{ \Illuminate\Support\Str::limit($registro->dados_depois, 180) }}</code>
                            @else
                                <span class="text-suave">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach

                <x-slot:rodape>
                    <span>Últimos {{ $registros->count() }} registros</span>
                </x-slot:rodape>
            </x-tabela>
        </div>
    </x-cartao>
</x-layouts.app>
