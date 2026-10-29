<x-layouts.app :titulo="$pessoa->nome">
    <x-migalhas :itens="[['rotulo' => 'Pessoas', 'href' => route('pessoas.index')], ['rotulo' => $pessoa->nome]]" />
    <x-cabecalho-pagina :titulo="$pessoa->nome"
        :subtitulo="$pessoa->ativo
            ? 'Pessoa ativa. Vincule a empresa e o perfil dela abaixo.'
            : 'Pessoa desativada. Ela sai da contagem do plano e não recebe vínculos novos.'">
        <x-slot:acoes>
            <x-botao icone="editar" :href="route('pessoas.editar', $pessoa)">Editar</x-botao>
            @if ($pessoa->ativo)
                <x-botao variante="perigo" icone="inativo" data-abrir-modal="desativar-pessoa">Desativar</x-botao>
            @else
                <form method="POST" action="{{ route('pessoas.situacao', $pessoa) }}">
                    @csrf
                    <input type="hidden" name="ativo" value="1">
                    <x-botao variante="primaria" type="submit" icone="marcado">Reativar</x-botao>
                </form>
            @endif
        </x-slot:acoes>
    </x-cabecalho-pagina>

    @error('plano')<x-alerta tipo="atencao">{{ $message }}</x-alerta>@enderror
    @error('pessoa')<x-alerta tipo="atencao">{{ $message }}</x-alerta>@enderror
    @error('status')<x-alerta tipo="atencao">{{ $message }}</x-alerta>@enderror

    <x-cartao class="max-w-4xl">
        <dl class="grid gap-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-suave">CPF</dt>
                <dd class="numeros mt-1">{{ \App\Support\Formato::cpfMascarado($pessoa->cpf) }}</dd>
            </div>
            <div>
                <dt class="text-suave">Situação</dt>
                <dd class="mt-1">
                    @if ($pessoa->ativo)
                        <x-status tipo="ativo">Ativa</x-status>
                    @else
                        <x-status tipo="inativo">Desativada</x-status>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-suave">Data de nascimento</dt>
                <dd class="mt-1">{{ $pessoa->data_nascimento ? \App\Support\Formato::data($pessoa->data_nascimento) : '—' }}</dd>
            </div>
            <div>
                <dt class="text-suave">Documento</dt>
                <dd class="mt-1">
                    @if ($pessoa->documento_tipo)
                        {{ \App\Models\Pessoa::TIPOS_DOCUMENTO[$pessoa->documento_tipo] ?? $pessoa->documento_tipo }}
                        {{ $pessoa->documento_numero }}
                        @if ($pessoa->documento_orgao || $pessoa->documento_uf)
                            ({{ trim(($pessoa->documento_orgao ?? '').' '.($pessoa->documento_uf ?? '')) }})
                        @endif
                    @else
                        —
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-suave">E-mail</dt>
                <dd class="mt-1 break-all">{{ $pessoa->email ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-suave">Telefone</dt>
                <dd class="mt-1">{{ $pessoa->telefone ? \App\Support\Formato::telefone($pessoa->telefone) : '—' }}</dd>
            </div>
        </dl>
    </x-cartao>

    <div class="flex max-w-4xl flex-col gap-6">
        @include('cadastro.pessoas._consentimento')

        <x-cartao :preenchimento="false">
            <div class="p-5">
                <div class="mb-4">
                    <h2 class="text-lg">Vínculos</h2>
                    <p class="text-sm text-suave">Cada vínculo liga a pessoa a uma empresa, num perfil, pelo período informado. Nenhum vínculo é apagado.</p>
                </div>
                <x-tabela id="vinculos" legenda="Vínculos da pessoa" :vazia="$pessoa->vinculos->isEmpty()" :colunas="['validade' => 'Validade']">
                    <x-slot:cabecalho>
                        <tr>
                            <x-tabela.coluna>Empresa</x-tabela.coluna>
                            <x-tabela.coluna>Perfil</x-tabela.coluna>
                            <x-tabela.coluna>Início</x-tabela.coluna>
                            <x-tabela.coluna coluna="validade">Validade</x-tabela.coluna>
                            <x-tabela.coluna>Situação</x-tabela.coluna>
                            <x-tabela.coluna>Ações</x-tabela.coluna>
                        </tr>
                    </x-slot:cabecalho>
                    <x-slot:vazio>
                        <x-estado-vazio titulo="Nenhum vínculo ainda" descricao="A pessoa só passa a ter acesso depois de vinculada a uma empresa e a um perfil." />
                    </x-slot:vazio>

                    @foreach ($pessoa->vinculos as $vinculo)
                        <tr>
                            <td class="font-medium text-texto">{{ $vinculo->empresa->razao_social }}</td>
                            <td>{{ $vinculo->perfil->nome }}</td>
                            <td class="whitespace-nowrap">{{ \App\Support\Formato::data($vinculo->data_inicio) }}</td>
                            <td data-coluna="validade" class="whitespace-nowrap">{{ $vinculo->data_fim ? \App\Support\Formato::data($vinculo->data_fim) : 'Sem prazo' }}</td>
                            <td>
                                @if ($vinculo->status === 'ativo')
                                    <x-status tipo="ativo">{{ \App\Models\Vinculo::STATUS['ativo'] }}</x-status>
                                @elseif ($vinculo->status === 'suspenso')
                                    <x-status tipo="atencao">{{ \App\Models\Vinculo::STATUS['suspenso'] }}</x-status>
                                @else
                                    <x-status tipo="inativo">{{ \App\Models\Vinculo::STATUS['encerrado'] }}</x-status>
                                @endif
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    @if ($vinculo->status !== 'encerrado')
                                        <x-botao variante="secundaria" tamanho="pequeno" icone="editar" :href="route('pessoas.vinculos.editar', [$pessoa, $vinculo])">Editar</x-botao>
                                        @if ($vinculo->status === 'ativo')
                                            <form method="POST" action="{{ route('pessoas.vinculos.suspender', [$pessoa, $vinculo]) }}">
                                                @csrf
                                                <x-botao variante="fantasma" tamanho="pequeno" type="submit" icone="bloquear">Suspender</x-botao>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('pessoas.vinculos.reativar', [$pessoa, $vinculo]) }}">
                                                @csrf
                                                <x-botao variante="fantasma" tamanho="pequeno" type="submit" icone="liberar">Reativar</x-botao>
                                            </form>
                                        @endif
                                    @else
                                        <span class="text-xs text-suave">Encerrado em {{ \App\Support\Formato::data($vinculo->encerrado_em) }}</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-tabela>
            </div>
        </x-cartao>

        <x-cartao>
            <h2 class="text-lg">Novo vínculo</h2>
            @if ($opcoes === [])
                <div class="mt-3">
                    <x-alerta tipo="atencao">
                        Nenhuma empresa ativa aceita perfis ainda. Escolha os perfis aceitos em
                        <a href="{{ route('empresas.index') }}" class="font-semibold underline">Empresas</a> antes de vincular pessoas.
                    </x-alerta>
                </div>
            @elseif (! $pessoa->ativo)
                <p class="mt-2 text-sm text-suave">Reative a pessoa para criar novos vínculos.</p>
            @else
                <form method="POST" action="{{ route('pessoas.vinculos.salvar', $pessoa) }}" class="mt-4 flex flex-col gap-4">
                    @csrf
                    <x-selecao nome="empresa_perfil" rotulo="Empresa e perfil" obrigatorio :opcoes="$opcoes" vazio="Selecione"
                        ajuda="Só aparecem as empresas ativas e os perfis que cada uma aceita." />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-campo nome="data_inicio" tipo="date" rotulo="Início" obrigatorio :valor="now()->toDateString()" />
                        <x-campo nome="data_fim" tipo="date" rotulo="Validade (fim)" :valor="null" ajuda="Deixe em branco para vínculo sem prazo." />
                    </div>
                    <div class="flex justify-end">
                        <x-botao variante="primaria" type="submit" icone="adicionar">Criar vínculo</x-botao>
                    </div>
                </form>
            @endif
        </x-cartao>
    </div>

    @if ($pessoa->ativo)
        <x-modal-confirmacao id="desativar-pessoa" titulo="Desativar esta pessoa?" :acao="route('pessoas.situacao', $pessoa)" confirmar="Desativar">
            <x-slot:campos>
                <input type="hidden" name="ativo" value="0">
            </x-slot:campos>
            A pessoa sai da contagem do plano e não recebe vínculos novos. O histórico é mantido e ela pode ser reativada depois.
        </x-modal-confirmacao>
    @endif
</x-layouts.app>
