<x-layouts.app titulo="Empresas">
    <x-cabecalho-pagina titulo="Empresas" subtitulo="Empresas atendidas pelo cliente. Cada pessoa é vinculada a uma delas, com o perfil dela.">
        <x-slot:acoes>
            <x-botao variante="primaria" icone="adicionar" :href="route('empresas.criar')">Nova empresa</x-botao>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <x-cartao :preenchimento="false">
        <div class="p-5">
            <x-tabela id="empresas" legenda="Empresas atendidas" :vazia="$empresas->isEmpty()" :colunas="['cnpj' => 'CNPJ', 'municipio' => 'Município', 'catracas' => 'Catracas']">
                <x-slot:cabecalho>
                    <tr>
                        <x-tabela.coluna>Empresa</x-tabela.coluna>
                        <x-tabela.coluna coluna="cnpj">CNPJ</x-tabela.coluna>
                        <x-tabela.coluna coluna="municipio">Município</x-tabela.coluna>
                        <x-tabela.coluna coluna="catracas" numero>Catracas</x-tabela.coluna>
                        <x-tabela.coluna>Situação</x-tabela.coluna>
                        <x-tabela.coluna>Ações</x-tabela.coluna>
                    </tr>
                </x-slot:cabecalho>
                <x-slot:vazio>
                    <x-estado-vazio titulo="Nenhuma empresa cadastrada" descricao="Cadastre a empresa do cliente e as prestadoras cujas pessoas vão ter acesso.">
                        <x-slot:acao>
                            <x-botao variante="primaria" icone="adicionar" :href="route('empresas.criar')">Nova empresa</x-botao>
                        </x-slot:acao>
                    </x-estado-vazio>
                </x-slot:vazio>

                @foreach ($empresas as $empresa)
                    <tr>
                        <td>
                            <a href="{{ route('empresas.configurar', $empresa) }}" class="font-medium text-texto no-underline hover:text-primaria hover:underline">{{ $empresa->razao_social }}</a>
                            @if ($empresa->nome_fantasia)<span class="block text-xs text-suave">{{ $empresa->nome_fantasia }}</span>@endif
                        </td>
                        <td data-coluna="cnpj" class="numeros whitespace-nowrap text-suave">{{ \App\Support\Formato::cnpj($empresa->cnpj) }}</td>
                        <td data-coluna="municipio">{{ $empresa->municipio }}/{{ $empresa->uf }}</td>
                        <td data-coluna="catracas" class="numeros text-right">{{ \App\Support\Formato::numero($empresa->catracas_count) }}</td>
                        <td>
                            @if ($empresa->ativo)
                                <x-status tipo="ativo">Ativa</x-status>
                            @else
                                <x-status tipo="inativo">Desativada</x-status>
                            @endif
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                <x-botao variante="secundaria" tamanho="pequeno" icone="editar" :href="route('empresas.editar', $empresa)">Editar</x-botao>
                                <x-botao variante="fantasma" tamanho="pequeno" :href="route('empresas.configurar', $empresa)">Perfis e horário</x-botao>
                            </div>
                        </td>
                    </tr>
                @endforeach

                <x-slot:rodape>
                    <span>{{ $empresas->count() }} {{ $empresas->count() === 1 ? 'empresa' : 'empresas' }}</span>
                </x-slot:rodape>
            </x-tabela>
        </div>
    </x-cartao>
</x-layouts.app>
