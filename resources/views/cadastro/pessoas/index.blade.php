<x-layouts.app titulo="Pessoas">
    <x-cabecalho-pagina titulo="Pessoas"
        subtitulo="Cada CPF é uma pessoa só, que pode ter vínculos com mais de uma empresa. O CPF aparece mascarado.">
        <x-slot:acoes>
            <x-botao variante="primaria" icone="adicionar" :href="route('pessoas.criar')">Nova pessoa</x-botao>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <x-cartao :preenchimento="false">
        <div class="p-5">
            <x-tabela id="pessoas" legenda="Pessoas do cliente" :vazia="$pessoas->isEmpty()" :colunas="['cpf' => 'CPF', 'vinculos' => 'Vínculos ativos']">
                <x-slot:filtros>
                    {{-- POST: o nome ou o CPF pesquisado não fica na URL nem no histórico. --}}
                    <form method="POST" action="{{ route('pessoas.busca') }}" class="flex flex-wrap items-end gap-2" role="search">
                        @csrf
                        <div class="min-w-56 flex-1">
                            <x-campo nome="termo" tipo="search" rotulo="Buscar" :valor="$termo" maxlength="100" placeholder="Nome ou CPF" sem-opcional />
                        </div>
                        <x-botao variante="primaria" type="submit" icone="filtro">Buscar</x-botao>
                        @if ($termo !== '')
                            <x-botao variante="fantasma" :href="route('pessoas.index')">Limpar</x-botao>
                        @endif
                    </form>
                </x-slot:filtros>

                <x-slot:cabecalho>
                    <tr>
                        <x-tabela.coluna>Nome</x-tabela.coluna>
                        <x-tabela.coluna coluna="cpf">CPF</x-tabela.coluna>
                        <x-tabela.coluna coluna="vinculos" numero>Vínculos ativos</x-tabela.coluna>
                        <x-tabela.coluna>Situação</x-tabela.coluna>
                    </tr>
                </x-slot:cabecalho>

                <x-slot:vazio>
                    @if ($termo !== '')
                        <x-estado-vazio titulo="Nenhuma pessoa com esta busca" descricao="Confira o nome ou o CPF digitado.">
                            <x-slot:acao>
                                <x-botao :href="route('pessoas.index')">Limpar busca</x-botao>
                            </x-slot:acao>
                        </x-estado-vazio>
                    @else
                        <x-estado-vazio titulo="Nenhuma pessoa cadastrada" descricao="Cadastre a primeira pessoa e vincule a empresa e o perfil dela.">
                            <x-slot:acao>
                                <x-botao variante="primaria" icone="adicionar" :href="route('pessoas.criar')">Nova pessoa</x-botao>
                            </x-slot:acao>
                        </x-estado-vazio>
                    @endif
                </x-slot:vazio>

                @foreach ($pessoas as $pessoa)
                    <tr>
                        <td>
                            <a href="{{ route('pessoas.show', $pessoa) }}" class="font-medium text-texto no-underline hover:text-primaria hover:underline">{{ $pessoa->nome }}</a>
                        </td>
                        <td data-coluna="cpf" class="numeros whitespace-nowrap text-suave">{{ \App\Support\Formato::cpfMascarado($pessoa->cpf) }}</td>
                        <td data-coluna="vinculos" class="numeros text-right">{{ \App\Support\Formato::numero($pessoa->vinculos_ativos) }}</td>
                        <td>
                            @if ($pessoa->ativo)
                                <x-status tipo="ativo">Ativa</x-status>
                            @else
                                <x-status tipo="inativo">Desativada</x-status>
                            @endif
                        </td>
                    </tr>
                @endforeach

                <x-slot:rodape>
                    <span>{{ $pessoas->count() }} {{ $pessoas->count() === 1 ? 'pessoa' : 'pessoas' }}{{ $termo !== '' ? ' encontradas' : '' }}</span>
                    @if ($pessoas->count() === \App\Http\Controllers\Cadastro\PessoaController::LIMITE_LISTA)
                        <span>Mostrando as primeiras {{ $pessoas->count() }}. Refine a busca para ver outras.</span>
                    @endif
                </x-slot:rodape>
            </x-tabela>
        </div>
    </x-cartao>
</x-layouts.app>
