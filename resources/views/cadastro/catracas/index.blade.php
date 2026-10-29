<x-layouts.app titulo="Catracas">
    <x-cabecalho-pagina titulo="Catracas" subtitulo="Equipamentos do cliente. Cada catraca tem o próprio token de conexão.">
        <x-slot:acoes>
            <x-botao variante="primaria" icone="adicionar" :href="route('catracas.criar')">Nova catraca</x-botao>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <x-cartao :preenchimento="false">
        <div class="p-5">
            <x-tabela id="catracas" legenda="Catracas do cliente" :vazia="$catracas->isEmpty()" :colunas="['empresa' => 'Empresa', 'tipo' => 'Identificação', 'contato' => 'Último contato']">
                <x-slot:cabecalho>
                    <tr>
                        <x-tabela.coluna>Catraca</x-tabela.coluna>
                        <x-tabela.coluna coluna="empresa">Empresa</x-tabela.coluna>
                        <x-tabela.coluna>Identificador</x-tabela.coluna>
                        <x-tabela.coluna coluna="tipo">Identificação</x-tabela.coluna>
                        <x-tabela.coluna>Situação</x-tabela.coluna>
                        <x-tabela.coluna coluna="contato">Último contato</x-tabela.coluna>
                    </tr>
                </x-slot:cabecalho>
                <x-slot:vazio>
                    <x-estado-vazio titulo="Nenhuma catraca cadastrada" descricao="Cadastre a primeira catraca e conecte o equipamento com o token gerado.">
                        <x-slot:acao>
                            <x-botao variante="primaria" icone="adicionar" :href="route('catracas.criar')">Nova catraca</x-botao>
                        </x-slot:acao>
                    </x-estado-vazio>
                </x-slot:vazio>

                @foreach ($catracas as $catraca)
                    <tr>
                        <td>
                            <a href="{{ route('catracas.show', $catraca) }}" class="font-medium text-texto no-underline hover:text-primaria hover:underline">{{ $catraca->nome }}</a>
                            @if ($catraca->localizacao)<span class="block text-xs text-suave">{{ $catraca->localizacao }}</span>@endif
                        </td>
                        <td data-coluna="empresa">{{ $catraca->empresa->razao_social }}</td>
                        <td class="numeros text-suave">{{ $catraca->identificador_dispositivo }}</td>
                        <td data-coluna="tipo">{{ \App\Models\Catraca::TIPOS_IDENTIFICACAO[$catraca->tipo_identificacao] ?? $catraca->tipo_identificacao }}</td>
                        <td>
                            @if ($catraca->ativo)
                                <x-status tipo="ativo">Ativa</x-status>
                            @else
                                <x-status tipo="inativo">Desativada</x-status>
                            @endif
                        </td>
                        <td data-coluna="contato" class="whitespace-nowrap">
                            {{ $catraca->ultimo_contato_em ? \App\Support\Formato::dataHora($catraca->ultimo_contato_em) : 'Ainda não conectou' }}
                        </td>
                    </tr>
                @endforeach

                <x-slot:rodape>
                    <span>{{ $catracas->count() }} {{ $catracas->count() === 1 ? 'catraca' : 'catracas' }}</span>
                </x-slot:rodape>
            </x-tabela>
        </div>
    </x-cartao>
</x-layouts.app>
