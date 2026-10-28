@php
    use App\Models\Assinatura;
    use App\Support\Formato;

    $filtrando = implode('', $filtros) !== '';
    $statusOpcoes = Assinatura::ROTULOS + ['desativado' => 'Desativado'];
@endphp

<x-layouts.app titulo="Clientes">
    <x-cabecalho-pagina titulo="Clientes" subtitulo="Cadastro, plano e cobrança dos clientes. Dados do ambiente de cada cliente não aparecem aqui.">
        <x-slot:acoes>
            <x-botao icone="planos" :href="route('plataforma.planos.index')">Planos</x-botao>
            <x-botao variante="primaria" icone="adicionar" :href="route('plataforma.clientes.novo')">Novo cliente</x-botao>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-indicador tipo="destaque" rotulo="Receita recorrente mensal" :valor="Formato::moeda($totais['receita'])" icone="financeiro" />
        <x-indicador rotulo="Ativos" :valor="$totais['ativos']" icone="sucesso" />
        <x-indicador rotulo="Em teste grátis" :valor="$totais['teste']" icone="calendario" />
        <x-indicador rotulo="Inadimplentes" :valor="$totais['inadimplentes']" icone="atencao" :tipo="$totais['inadimplentes'] > 0 ? 'perigo' : 'normal'" />
    </div>

    <x-cartao :preenchimento="false">
        <div class="p-5">
            <x-tabela id="clientes" legenda="Clientes do CadGestor" :vazia="$clientes->isEmpty()" :colunas="['cnpj' => 'CNPJ', 'plano' => 'Plano', 'contagens' => 'Pessoas e catracas']">
                <x-slot:filtros>
                    {{-- POST: o nome ou CNPJ pesquisado não fica na URL nem no histórico. --}}
                    <form method="POST" action="{{ route('plataforma.clientes.busca') }}" class="flex flex-wrap items-end gap-2" role="search">
                        @csrf
                        <div class="min-w-56 flex-1">
                            <x-campo nome="termo" tipo="search" rotulo="Buscar" :valor="$filtros['termo']" maxlength="100" placeholder="Razão social, nome fantasia ou CNPJ" sem-opcional />
                        </div>
                        <x-selecao nome="status" rotulo="Situação" :opcoes="$statusOpcoes" vazio="Todas" :valor="$filtros['status']" sem-opcional />
                        <x-selecao nome="plano" rotulo="Plano" :opcoes="$planos->pluck('nome', 'codigo')->all()" vazio="Todos" :valor="$filtros['plano']" sem-opcional />
                        <x-botao variante="primaria" type="submit" icone="filtro">Filtrar</x-botao>
                        @if ($filtrando)
                            <x-botao variante="fantasma" :href="route('plataforma.clientes.index')">Limpar</x-botao>
                        @endif
                    </form>
                </x-slot:filtros>
                <x-slot:cabecalho>
                    <tr>
                        <x-tabela.coluna>Cliente</x-tabela.coluna>
                        <x-tabela.coluna coluna="cnpj">CNPJ</x-tabela.coluna>
                        <x-tabela.coluna coluna="plano">Plano</x-tabela.coluna>
                        <x-tabela.coluna>Situação</x-tabela.coluna>
                        <x-tabela.coluna coluna="contagens" numero>Pessoas</x-tabela.coluna>
                        <x-tabela.coluna coluna="contagens" numero>Catracas</x-tabela.coluna>
                    </tr>
                </x-slot:cabecalho>
                <x-slot:vazio>
                    <x-estado-vazio :titulo="$filtrando ? 'Nenhum cliente com esses filtros' : 'Nenhum cliente ainda'"
                        :descricao="$filtrando ? 'Mude a busca ou limpe os filtros.' : 'Cadastre o primeiro cliente pelo assistente de venda.'">
                        <x-slot:acao>
                            @if ($filtrando)
                                <x-botao :href="route('plataforma.clientes.index')">Limpar filtros</x-botao>
                            @else
                                <x-botao variante="primaria" icone="adicionar" :href="route('plataforma.clientes.novo')">Novo cliente</x-botao>
                            @endif
                        </x-slot:acao>
                    </x-estado-vazio>
                </x-slot:vazio>

                @foreach ($clientes as $cliente)
                    @php $assinatura = $cliente->assinatura; $situacao = $assinatura?->situacao(); @endphp
                    <tr>
                        <td>
                            <a href="{{ route('plataforma.clientes.show', $cliente) }}" class="font-medium text-texto no-underline hover:text-primaria hover:underline">{{ $cliente->nome }}</a>
                            @if ($cliente->nome_fantasia)<span class="block text-xs text-suave">{{ $cliente->nome_fantasia }}</span>@endif
                        </td>
                        <td data-coluna="cnpj" class="numeros whitespace-nowrap text-suave">{{ $cliente->cnpj ? Formato::cnpj($cliente->cnpj) : '—' }}</td>
                        <td data-coluna="plano">{{ $assinatura?->plano?->nome ?? '—' }}</td>
                        <td>
                            @if (! $cliente->ativo)
                                <x-status tipo="inativo">Desativado</x-status>
                            @elseif ($situacao)
                                <x-status :tipo="$assinatura->status === 'ativa' ? 'ativo' : $situacao->tipoStatus()">{{ $assinatura->rotuloStatus() }}</x-status>
                                @if ($situacao->nivel !== 'normal')<span class="mt-1 block text-xs text-suave">{{ $situacao->rotulo() }}</span>@endif
                            @else
                                <x-status tipo="atencao">Sem assinatura</x-status>
                            @endif
                        </td>
                        <td data-coluna="contagens" class="numeros text-right">{{ Formato::numero($cliente->total_pessoas) }}</td>
                        <td data-coluna="contagens" class="numeros text-right">{{ Formato::numero($cliente->total_catracas) }}</td>
                    </tr>
                @endforeach

                <x-slot:rodape>
                    <span>{{ $clientes->count() }} {{ $clientes->count() === 1 ? 'cliente' : 'clientes' }}{{ $filtrando ? ' com esses filtros' : '' }}</span>
                </x-slot:rodape>
            </x-tabela>
        </div>
    </x-cartao>
</x-layouts.app>
