<x-layouts.app titulo="Portaria ao vivo">
    <x-cabecalho-pagina titulo="Portaria ao vivo"
        subtitulo="Situação das catracas e as últimas passagens. Atualize a página para ver as mais recentes.">
        <x-slot:acoes>
            <x-botao icone="conectado" :href="route('portaria')">Atualizar</x-botao>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    @if ($catracas->isEmpty())
        <x-estado-vazio icone="catraca" titulo="Nenhuma catraca ativa" descricao="Cadastre uma catraca para acompanhar a portaria aqui." />
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($catracas as $catraca)
                <x-cartao>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="text-base font-semibold">{{ $catraca->nome }}</h2>
                            <p class="text-sm text-suave">{{ $catraca->empresa->razao_social }}</p>
                        </div>
                        @if (! $catraca->ultimo_contato_em)
                            <x-status tipo="inativo">Ainda não conectou</x-status>
                        @elseif ($catraca->ultimo_contato_em->gte($limiteContato))
                            <x-status tipo="ativo">Conectada</x-status>
                        @else
                            <x-status tipo="atencao">Sem contato</x-status>
                        @endif
                    </div>
                    <p class="mt-3 text-xs text-suave">
                        Último contato:
                        {{ $catraca->ultimo_contato_em ? \App\Support\Formato::dataHora($catraca->ultimo_contato_em) : 'nenhum' }}
                    </p>
                </x-cartao>
            @endforeach
        </div>
    @endif

    <x-cartao :preenchimento="false">
        <div class="p-5">
            <x-tabela id="passagens" legenda="Últimas passagens" :vazia="$eventos->isEmpty()" :colunas="['catraca' => 'Catraca', 'origem' => 'Origem']">
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
                    <x-estado-vazio titulo="Nenhuma passagem ainda" descricao="As passagens aparecem aqui assim que as catracas começarem a registrar." />
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
                                <span class="block text-xs text-suave">{{ \App\Services\Acesso\ResultadoAcesso::ROTULOS[$evento->motivo_negacao] ?? 'motivo não informado' }}</span>
                            @endif
                        </td>
                        <td data-coluna="origem">{{ $evento->offline ? 'Sem servidor' : 'Online' }}</td>
                    </tr>
                @endforeach

                <x-slot:rodape>
                    <span>Últimas {{ $eventos->count() }} passagens</span>
                </x-slot:rodape>
            </x-tabela>
        </div>
    </x-cartao>
</x-layouts.app>
