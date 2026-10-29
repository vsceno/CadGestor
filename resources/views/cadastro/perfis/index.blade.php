@php $podeEditar = auth()->user()->temPapel(\App\Enums\Papel::Administrador); @endphp

<x-layouts.app titulo="Perfis de acesso">
    <x-cabecalho-pagina titulo="Perfis de acesso"
        subtitulo="Funcionário, aluno, prestador... Cada perfil tem uma base legal que define como os dados das pessoas com esse perfil podem ser tratados.">
        @if ($podeEditar)
            <x-slot:acoes>
                <x-botao variante="primaria" icone="adicionar" :href="route('perfis.criar')">Novo perfil</x-botao>
            </x-slot:acoes>
        @endif
    </x-cabecalho-pagina>

    <x-cartao :preenchimento="false">
        <div class="p-5">
            <x-tabela id="perfis" legenda="Perfis de acesso" :vazia="$perfis->isEmpty()" :colunas="['base' => 'Base legal', 'validacao' => 'Validação']">
                <x-slot:cabecalho>
                    <tr>
                        <x-tabela.coluna>Perfil</x-tabela.coluna>
                        <x-tabela.coluna coluna="base">Base legal</x-tabela.coluna>
                        <x-tabela.coluna coluna="validacao">Validação</x-tabela.coluna>
                        <x-tabela.coluna>Situação</x-tabela.coluna>
                        @if ($podeEditar)
                            <x-tabela.coluna>Ações</x-tabela.coluna>
                        @endif
                    </tr>
                </x-slot:cabecalho>
                <x-slot:vazio>
                    <x-estado-vazio titulo="Nenhum perfil cadastrado" descricao="Crie o primeiro perfil de acesso do cliente." />
                </x-slot:vazio>

                @foreach ($perfis as $perfil)
                    <tr>
                        <td>
                            <span class="font-medium text-texto">{{ $perfil->nome }}</span>
                            @if ($perfil->descricao)<span class="block text-xs text-suave">{{ $perfil->descricao }}</span>@endif
                        </td>
                        <td data-coluna="base" class="text-sm">{{ \App\Models\PerfilVinculo::BASES_LEGAIS[$perfil->base_legal] ?? $perfil->base_legal }}</td>
                        <td data-coluna="validacao">
                            @if ($perfil->base_legal_validada)
                                <x-status tipo="ativo">Validada</x-status>
                            @else
                                <x-status tipo="atencao">Aguardando validação</x-status>
                            @endif
                        </td>
                        <td>
                            @if ($perfil->ativo)
                                <x-status tipo="ativo">Ativo</x-status>
                            @else
                                <x-status tipo="inativo">Inativo</x-status>
                            @endif
                        </td>
                        @if ($podeEditar)
                            <td>
                                <x-botao variante="secundaria" tamanho="pequeno" icone="editar" :href="route('perfis.editar', $perfil)">Editar</x-botao>
                            </td>
                        @endif
                    </tr>
                @endforeach

                <x-slot:rodape>
                    <span>{{ $perfis->count() }} {{ $perfis->count() === 1 ? 'perfil' : 'perfis' }}</span>
                </x-slot:rodape>
            </x-tabela>
        </div>
    </x-cartao>
</x-layouts.app>
