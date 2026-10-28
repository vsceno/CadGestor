@php
    use App\Models\Fatura;
    use App\Services\Plataforma\GestaoClientes;
    use App\Support\Formato;

    $listaAbas = collect($abas)->map(fn (string $rotulo, string $chave): array => [
        'rotulo' => $rotulo,
        'href' => route('plataforma.clientes.show', [$cliente, $chave]),
        'ativa' => $aba === $chave,
    ])->values()->all();
    $dl = 'grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[12rem_1fr]';
@endphp

<x-layouts.app :titulo="$cliente->nome">
    <x-migalhas :itens="[['rotulo' => 'Clientes', 'href' => route('plataforma.clientes.index')], ['rotulo' => $cliente->nome]]" />

    <x-cabecalho-pagina :titulo="$cliente->nome"
        :subtitulo="collect([$cliente->nome_fantasia, $cliente->cnpj ? 'CNPJ '.Formato::cnpj($cliente->cnpj) : 'Sem CNPJ', 'cliente desde '.Formato::data($cliente->created_at)])->filter()->implode(' · ')">
        <x-slot:acoes>
            @if (! $cliente->ativo)
                <x-status tipo="inativo">Desativado</x-status>
            @elseif ($situacao)
                <x-status :tipo="$assinatura->status === 'ativa' ? 'ativo' : $situacao->tipoStatus()">{{ $assinatura->rotuloStatus() }}</x-status>
            @endif
            <x-botao icone="editar" :href="route('plataforma.clientes.editar', $cliente)">Editar cadastro</x-botao>
            @if ($cliente->ativo)
                <x-botao variante="perigo" icone="inativo" data-abrir-modal="modal-desativar">Desativar cliente</x-botao>
            @else
                <form method="POST" action="{{ route('plataforma.clientes.situacao', $cliente) }}">
                    @csrf
                    <input type="hidden" name="ativo" value="1">
                    <x-botao variante="primaria" type="submit" icone="liberar">Reativar cliente</x-botao>
                </form>
            @endif
        </x-slot:acoes>
    </x-cabecalho-pagina>

    @if (! $cliente->cadastroCompleto())
        <x-alerta tipo="atencao" titulo="Cadastro incompleto">
            Para emitir a nota fiscal são necessários CNPJ, endereço completo, telefone e e-mail financeiro.
            <a href="{{ route('plataforma.clientes.editar', $cliente) }}">Completar cadastro</a>
        </x-alerta>
    @endif

    <x-abas :abas="$listaAbas" rotulo="Seções do cliente" />

    @if ($aba === 'dados')
        <div class="grid gap-6 xl:grid-cols-[1fr_24rem]">
            <x-cartao titulo="Cadastro">
                <dl class="{{ $dl }}">
                    <dt class="text-suave">Razão social</dt><dd>{{ $cliente->nome }}</dd>
                    <dt class="text-suave">Nome fantasia</dt><dd>{{ $cliente->nome_fantasia ?: '—' }}</dd>
                    <dt class="text-suave">CNPJ</dt><dd class="numeros">{{ $cliente->cnpj ? Formato::cnpj($cliente->cnpj) : '—' }}</dd>
                    <dt class="text-suave">Inscrições</dt>
                    <dd>Municipal: {{ $cliente->inscricao_municipal ?: '—' }} · Estadual: {{ $cliente->inscricao_estadual ?: '—' }}</dd>
                    <dt class="text-suave">Endereço</dt>
                    <dd>
                        @if ($cliente->cep)
                            {{ $cliente->logradouro }}, {{ $cliente->numero }}{{ $cliente->complemento ? ' · '.$cliente->complemento : '' }}<br>
                            {{ $cliente->bairro }} · {{ $cliente->municipio }}/{{ $cliente->uf }} · CEP {{ Formato::cep($cliente->cep) }}
                        @else
                            —
                        @endif
                    </dd>
                    <dt class="text-suave">Telefone</dt><dd class="numeros">{{ $cliente->telefone ? Formato::telefone($cliente->telefone) : '—' }}</dd>
                    <dt class="text-suave">E-mail financeiro</dt><dd>{{ $cliente->email_financeiro ?: '—' }}</dd>
                    <dt class="text-suave">Responsável comercial</dt>
                    <dd>{{ $cliente->contato_nome ?: '—' }}{{ $cliente->contato_telefone ? ' · '.Formato::telefone($cliente->contato_telefone) : '' }}</dd>
                </dl>
            </x-cartao>

            <x-cartao titulo="Administradores do cliente" descricao="A plataforma nunca vê nem define senhas: ela envia um link para o e-mail da pessoa.">
                <div class="flex flex-col gap-4">
                    @forelse ($administradores as $admin)
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium">{{ $admin->nome }}</p>
                                <p class="truncate text-sm text-suave">{{ $admin->email }}</p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <x-status :tipo="$admin->ativo ? 'ativo' : 'inativo'">{{ $admin->ativo ? 'Ativo' : 'Inativo' }}</x-status>
                                    @if ($admin->email_verificado_em === null)<x-status tipo="atencao">E-mail não confirmado</x-status>@endif
                                    @if ($loginsTravados[$admin->id] ?? false)<x-status tipo="negado">Login travado</x-status>@endif
                                </div>
                            </div>
                            @if ($admin->ativo)
                                <form method="POST" action="{{ route('plataforma.clientes.link-senha', [$cliente, $admin->id]) }}">
                                    @csrf
                                    <x-botao tamanho="pequeno" type="submit" icone="bloquear">Enviar link de senha</x-botao>
                                </form>
                            @endif
                        </div>
                    @empty
                        <x-estado-vazio icone="usuarios" titulo="Nenhum administrador" descricao="Cadastre quem vai administrar o ambiente do cliente." />
                    @endforelse

                    <details class="rounded-controle border border-borda bg-superficie-2" @if ($errors->hasAny(['admin_nome', 'admin_email'])) open @endif>
                        <summary class="cursor-pointer px-4 py-3 text-sm font-semibold">Cadastrar novo administrador</summary>
                        <form method="POST" action="{{ route('plataforma.clientes.administradores', $cliente) }}" class="flex flex-col gap-3 px-4 pb-4">
                            @csrf
                            <x-campo nome="admin_nome" rotulo="Nome completo" obrigatorio maxlength="150" />
                            <x-campo nome="admin_email" tipo="email" rotulo="E-mail" obrigatorio maxlength="254" ajuda="A pessoa recebe um link para criar a própria senha." />
                            <x-botao variante="primaria" type="submit">Criar e enviar link</x-botao>
                        </form>
                    </details>
                </div>
            </x-cartao>
        </div>
    @elseif ($aba === 'plano')
        <div class="grid gap-6 xl:grid-cols-2">
            <x-cartao titulo="Plano atual">
                @if ($assinatura)
                    <div class="flex flex-col gap-4">
                        <div class="flex items-baseline justify-between rounded-controle bg-primaria-suave px-4 py-3">
                            <span class="font-semibold text-primaria">{{ $assinatura->plano->nome }}</span>
                            <span class="numeros text-xl font-bold text-primaria">{{ Formato::moeda($assinatura->plano->preco_centavos) }}<span class="text-sm font-medium">/mês</span></span>
                        </div>
                        <dl class="{{ $dl }}">
                            <dt class="text-suave">Situação</dt>
                            <dd>
                                {{ $assinatura->rotuloStatus() }}
                                @if ($assinatura->teste_ate) até {{ Formato::data($assinatura->teste_ate) }} @endif
                                @if ($assinatura->inadimplente_desde) desde {{ Formato::data($assinatura->inadimplente_desde) }} @endif
                            </dd>
                            <dt class="text-suave">Acesso</dt><dd>{{ $situacao->aviso ?? 'Normal' }}</dd>
                            <dt class="text-suave">Pessoas ativas</dt><dd class="numeros">{{ Formato::numero($uso->pessoas ?? 0) }} de {{ Formato::numero($assinatura->plano->limite_pessoas) }}</dd>
                            <dt class="text-suave">Catracas ativas</dt><dd class="numeros">{{ Formato::numero($uso->catracas ?? 0) }} de {{ Formato::numero($assinatura->plano->limite_catracas) }}</dd>
                        </dl>
                    </div>
                @else
                    <x-estado-vazio titulo="Sem assinatura" />
                @endif
            </x-cartao>

            @if ($assinatura && $assinatura->status !== 'cancelada')
                <div class="flex flex-col gap-6">
                    <x-cartao titulo="Trocar plano" descricao="O uso atual precisa caber no plano novo. O cliente vê o motivo na auditoria dele.">
                        <form method="POST" action="{{ route('plataforma.clientes.plano', $cliente) }}" class="flex flex-col gap-4">
                            @csrf
                            <x-selecao nome="plano_id" rotulo="Novo plano" obrigatorio vazio="Selecione"
                                :opcoes="$planos->reject(fn ($p) => $p->id === $assinatura->plano_id)->mapWithKeys(fn ($p) => [$p->id => $p->nome.' · '.Formato::moeda($p->preco_centavos).'/mês'.($p->disponivel_venda ? '' : ' (fora de venda)')])->all()" />
                            <x-campo nome="motivo" rotulo="Motivo" obrigatorio maxlength="200" placeholder="Ex.: upgrade negociado por telefone" />
                            <x-botao variante="primaria" type="submit" class="self-end">Trocar plano</x-botao>
                        </form>
                    </x-cartao>

                    @if ($assinatura->status === 'teste')
                        <x-cartao titulo="Estender teste grátis" descricao="A primeira cobrança passa a vencer no dia seguinte ao novo fim do teste.">
                            <form method="POST" action="{{ route('plataforma.clientes.teste', $cliente) }}" class="flex flex-col gap-4">
                                @csrf
                                <x-campo nome="dias" tipo="number" rotulo="Dias a mais" obrigatorio min="1" :max="GestaoClientes::DIAS_MAXIMOS_EXTENSAO_TESTE" valor="7" />
                                <x-campo nome="motivo" rotulo="Motivo" obrigatorio maxlength="200" placeholder="Ex.: atraso na instalação das catracas" />
                                <x-botao variante="primaria" type="submit" class="self-end">Estender teste</x-botao>
                            </form>
                        </x-cartao>
                    @endif
                </div>
            @endif
        </div>
    @elseif ($aba === 'cobranca')
        <x-cartao titulo="Faturas" :preenchimento="false">
            <div class="p-5">
                <x-tabela id="faturas-cliente" legenda="Faturas do cliente" :vazia="$faturas->isEmpty()">
                    <x-slot:cabecalho>
                        <tr>
                            <x-tabela.coluna>Vencimento</x-tabela.coluna>
                            <x-tabela.coluna numero>Valor</x-tabela.coluna>
                            <x-tabela.coluna>Situação</x-tabela.coluna>
                            <x-tabela.coluna>Pago em</x-tabela.coluna>
                            <x-tabela.coluna><span class="sr-only">Ações</span></x-tabela.coluna>
                        </tr>
                    </x-slot:cabecalho>
                    <x-slot:vazio>
                        <x-estado-vazio icone="financeiro" titulo="Nenhuma fatura" descricao="Clientes de venda manual são cobrados fora do sistema, pelo contrato." />
                    </x-slot:vazio>
                    @foreach ($faturas as $fatura)
                        <tr>
                            <td class="numeros">{{ Formato::data($fatura->vencimento) }}</td>
                            <td class="numeros text-right">{{ Formato::moeda((int) $fatura->valor_centavos) }}</td>
                            <td><x-status :tipo="Fatura::TIPOS_STATUS[$fatura->status] ?? 'info'">{{ Fatura::ROTULOS[$fatura->status] ?? $fatura->status }}</x-status></td>
                            <td class="numeros">{{ $fatura->pago_em ? Formato::dataHora($fatura->pago_em) : '—' }}</td>
                            <td class="text-right">
                                @if (in_array($fatura->status, ['pendente', 'vencida'], true))
                                    <x-botao tamanho="pequeno" data-abrir-modal="baixa-{{ $fatura->id }}">Dar baixa</x-botao>
                                    <x-modal-confirmacao :id="'baixa-'.$fatura->id" titulo="Registrar esta fatura como paga?" variante="info"
                                        :acao="route('plataforma.clientes.baixa', [$cliente, $fatura->id])" confirmar="Confirmar pagamento">
                                        Fatura de {{ Formato::moeda((int) $fatura->valor_centavos) }} com vencimento em {{ Formato::data($fatura->vencimento) }}.
                                        Sem outra fatura vencida, a assinatura volta a ficar em dia.
                                        <x-slot:campos>
                                            <x-campo nome="motivo" :id="'motivo-'.$fatura->id" rotulo="Como foi pago?" obrigatorio maxlength="200"
                                                placeholder="Ex.: Pix recebido em 10/11" ajuda="Aparece na auditoria do cliente. Não escreva dados pessoais." />
                                        </x-slot:campos>
                                    </x-modal-confirmacao>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-tabela>
            </div>
        </x-cartao>
    @else
        <div class="grid gap-6 xl:grid-cols-[1fr_24rem]">
            <x-cartao titulo="Histórico" descricao="Ações comerciais e da plataforma. Nunca mostra dados de pessoas do cliente.">
                @if ($historico->isEmpty())
                    <x-estado-vazio icone="auditoria" titulo="Sem eventos ainda" />
                @else
                    <ol class="flex flex-col gap-4 border-l-2 border-borda pl-5">
                        @foreach ($historico as $evento)
                            <li class="relative">
                                <span @class([
                                    'absolute top-1.5 -left-[1.625rem] size-2.5 rounded-full ring-4 ring-superficie',
                                    'bg-primaria' => $evento->origem === 'plataforma',
                                    'bg-sucesso' => $evento->origem === 'cliente',
                                    'bg-borda-forte' => $evento->origem === 'sistema',
                                ])></span>
                                <p class="text-sm font-medium">@include('plataforma.clientes._evento', ['evento' => $evento])</p>
                                <p class="text-xs text-suave">{{ Formato::dataHora($evento->data_hora) }} · {{ ['plataforma' => 'Plataforma', 'cliente' => 'Cliente', 'sistema' => 'Sistema'][$evento->origem] ?? $evento->origem }}{{ $evento->autor ? ' · '.$evento->autor : '' }}</p>
                                @if ($evento->acao === 'nota')
                                    <p class="mt-1 rounded-controle bg-superficie-2 px-3 py-2 text-sm">{{ $evento->texto }}</p>
                                @elseif (! empty($evento->depois['motivo']))
                                    <p class="mt-1 rounded-controle bg-superficie-2 px-3 py-2 text-sm">Motivo: {{ $evento->depois['motivo'] }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-cartao>

            <x-cartao titulo="Anotação interna" descricao="Só a plataforma vê. Evite dados pessoais.">
                <form method="POST" action="{{ route('plataforma.clientes.notas', $cliente) }}" class="flex flex-col gap-3">
                    @csrf
                    <x-campo nome="texto" tipo="textarea" rotulo="Anotação" obrigatorio maxlength="1000" placeholder="Ex.: pediu orçamento para mais 5 catracas." />
                    <x-botao type="submit" class="self-end">Anotar</x-botao>
                </form>
            </x-cartao>
        </div>
    @endif

    @if ($cliente->ativo)
        <x-modal-confirmacao id="modal-desativar" :titulo="'Desativar o cliente '.$cliente->nome.'?'"
            :acao="route('plataforma.clientes.situacao', $cliente)" confirmar="Desativar cliente" :digitar="$cliente->nome">
            <strong class="text-texto">Ninguém do cliente conseguirá entrar e as catracas serão recusadas.</strong>
            Nada é apagado: os cadastros e o histórico ficam guardados, e o cliente pode ser reativado.
            <x-slot:campos><input type="hidden" name="ativo" value="0"></x-slot:campos>
        </x-modal-confirmacao>
    @endif
</x-layouts.app>
