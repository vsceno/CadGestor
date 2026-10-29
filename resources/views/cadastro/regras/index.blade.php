<x-layouts.app titulo="Regras de acesso">
    <x-cabecalho-pagina titulo="Regras de acesso"
        subtitulo="Em quais dias, horários e catracas cada perfil passa em cada empresa. Sem regra própria, vale o horário padrão da empresa." />

    <x-cartao :preenchimento="false">
        <div class="p-5">
            <x-tabela id="regras" legenda="Regras por empresa e perfil" :vazia="$pares === []">
                <x-slot:cabecalho>
                    <tr>
                        <x-tabela.coluna>Empresa</x-tabela.coluna>
                        <x-tabela.coluna>Perfil</x-tabela.coluna>
                        <x-tabela.coluna numero>Regras</x-tabela.coluna>
                        <x-tabela.coluna>Ações</x-tabela.coluna>
                    </tr>
                </x-slot:cabecalho>
                <x-slot:vazio>
                    <x-estado-vazio titulo="Nenhum perfil aceito por empresa"
                        descricao="Para criar regras, escolha antes os perfis aceitos em cada empresa.">
                        <x-slot:acao>
                            <x-botao :href="route('empresas.index')">Ver empresas</x-botao>
                        </x-slot:acao>
                    </x-estado-vazio>
                </x-slot:vazio>

                @foreach ($pares as $par)
                    <tr>
                        <td class="font-medium text-texto">{{ $par['empresa']->razao_social }}</td>
                        <td>{{ $par['perfil']->nome }}</td>
                        <td class="numeros text-right">{{ $par['total'] > 0 ? \App\Support\Formato::numero($par['total']) : 'Usa o padrão' }}</td>
                        <td>
                            <x-botao variante="secundaria" tamanho="pequeno" icone="regras" :href="route('regras.editar', [$par['empresa'], $par['perfil']])">Editar regras</x-botao>
                        </td>
                    </tr>
                @endforeach

                <x-slot:rodape>
                    <span>{{ count($pares) }} {{ count($pares) === 1 ? 'combinação' : 'combinações' }} de empresa e perfil</span>
                </x-slot:rodape>
            </x-tabela>
        </div>
    </x-cartao>
</x-layouts.app>
