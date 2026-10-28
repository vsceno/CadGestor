@php
    use App\Support\Formato;

    $etapas = [1 => 'Dados do contrato', 2 => 'Plano', 3 => 'Primeiro administrador', 4 => 'Revisão'];
    $dados = $rascunho['dados'] ?? [];
    $admin = $rascunho['admin'] ?? [];
@endphp

<x-layouts.app titulo="Novo cliente">
    <x-migalhas :itens="[['rotulo' => 'Clientes', 'href' => route('plataforma.clientes.index')], ['rotulo' => 'Novo cliente']]" />
    <x-cabecalho-pagina titulo="Novo cliente (venda manual)"
        subtitulo="Para contratos fechados fora do site. A assinatura nasce ativa e o administrador recebe um link para criar a própria senha." />

    {{-- Etapas --}}
    <nav aria-label="Etapas do cadastro">
        <ol class="grid gap-2 sm:grid-cols-4">
            @foreach ($etapas as $numero => $nome)
                @php $estado = $numero < $etapa ? 'feita' : ($numero === $etapa ? 'atual' : 'futura'); @endphp
                <li @class([
                    'flex items-center gap-3 rounded-controle border px-3 py-2.5 text-sm',
                    'border-primaria bg-primaria-suave' => $estado === 'atual',
                    'border-borda bg-superficie' => $estado !== 'atual',
                ]) @if ($estado === 'atual') aria-current="step" @endif>
                    <span @class([
                        'numeros flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                        'bg-sucesso-suave text-sucesso' => $estado === 'feita',
                        'bg-primaria text-sobre-primaria' => $estado === 'atual',
                        'border border-borda-forte text-suave' => $estado === 'futura',
                    ])>
                        @if ($estado === 'feita')<x-icone nome="marcado" class="size-4" rotulo="Concluída" />@else{{ $numero }}@endif
                    </span>
                    @if ($estado === 'feita')
                        <a href="{{ route('plataforma.clientes.novo', ['etapa' => $numero]) }}" class="font-medium text-texto">{{ $nome }}</a>
                    @else
                        <span @class(['font-medium', 'text-suave' => $estado === 'futura'])>{{ $nome }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    @if ($errors->any())
        <x-alerta tipo="erro" titulo="Não foi possível continuar">Corrija os campos destacados.</x-alerta>
    @endif

    <x-cartao class="max-w-4xl">
        @if ($etapa === 1)
            <form method="POST" action="{{ route('plataforma.clientes.novo.etapa', ['etapa' => 1]) }}" class="flex flex-col gap-6">
                @csrf
                <x-campos-cliente :dados="$dados" />
                <div class="flex justify-between gap-2">
                    <x-botao :href="route('plataforma.clientes.index')">Cancelar</x-botao>
                    <x-botao variante="primaria" type="submit">Continuar <x-icone nome="avancar" class="size-4" /></x-botao>
                </div>
            </form>
        @elseif ($etapa === 2)
            <form method="POST" action="{{ route('plataforma.clientes.novo.etapa', ['etapa' => 2]) }}" class="flex flex-col gap-6">
                @csrf
                <fieldset>
                    <legend class="mb-3 text-sm font-medium">Plano contratado</legend>
                    <div class="grid gap-3 sm:grid-cols-3">
                        @foreach ($planos as $opcao)
                            <label class="flex cursor-pointer flex-col gap-1 rounded-cartao border border-borda-forte p-4 has-checked:border-primaria has-checked:bg-primaria-suave">
                                <span class="flex items-center gap-2">
                                    <input type="radio" name="plano_id" value="{{ $opcao->id }}" class="size-4 accent-primaria" required
                                        @checked(old('plano_id', $rascunho['plano_id'] ?? null) === $opcao->id)>
                                    <span class="font-semibold">{{ $opcao->nome }}</span>
                                    @unless ($opcao->disponivel_venda)<x-status tipo="inativo">Fora de venda</x-status>@endunless
                                </span>
                                <span class="numeros text-lg font-bold text-primaria">{{ Formato::moeda($opcao->preco_centavos) }}<span class="text-sm font-medium">/mês</span></span>
                                <span class="text-sm text-suave">até {{ Formato::numero($opcao->limite_pessoas) }} pessoas · {{ $opcao->limite_catracas }} catracas</span>
                            </label>
                        @endforeach
                    </div>
                    @error('plano_id')<p class="mt-2 text-sm font-medium text-perigo">{{ $message }}</p>@enderror
                </fieldset>
                <div class="flex justify-between gap-2">
                    <x-botao :href="route('plataforma.clientes.novo', ['etapa' => 1])" icone="voltar">Voltar</x-botao>
                    <x-botao variante="primaria" type="submit">Continuar <x-icone nome="avancar" class="size-4" /></x-botao>
                </div>
            </form>
        @elseif ($etapa === 3)
            <form method="POST" action="{{ route('plataforma.clientes.novo.etapa', ['etapa' => 3]) }}" class="flex flex-col gap-5">
                @csrf
                <p class="text-sm text-suave">Quem vai administrar o ambiente do cliente. Ele recebe no e-mail um link para criar a própria senha: a plataforma nunca vê nem define senhas.</p>
                <x-campo nome="admin_nome" rotulo="Nome completo" obrigatorio maxlength="150" :valor="$admin['admin_nome'] ?? null" />
                <x-campo nome="admin_email" tipo="email" rotulo="E-mail (será o login)" obrigatorio maxlength="254" :valor="$admin['admin_email'] ?? null" />
                <div class="flex justify-between gap-2">
                    <x-botao :href="route('plataforma.clientes.novo', ['etapa' => 2])" icone="voltar">Voltar</x-botao>
                    <x-botao variante="primaria" type="submit">Continuar <x-icone nome="avancar" class="size-4" /></x-botao>
                </div>
            </form>
        @else
            <div class="flex flex-col gap-6">
                <p class="text-sm text-suave">Confira antes de salvar. Você pode voltar a qualquer etapa.</p>
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[12rem_1fr]">
                    <dt class="text-suave">Razão social</dt><dd class="font-medium">{{ $dados['nome'] }}</dd>
                    <dt class="text-suave">CNPJ</dt><dd class="numeros">{{ Formato::cnpj($dados['cnpj']) }}</dd>
                    <dt class="text-suave">Endereço</dt>
                    <dd>{{ $dados['logradouro'] }}, {{ $dados['numero'] }} · {{ $dados['bairro'] }} · {{ $dados['municipio'] }}/{{ $dados['uf'] }} · CEP {{ Formato::cep($dados['cep']) }}</dd>
                    <dt class="text-suave">Contato</dt><dd>{{ Formato::telefone($dados['telefone']) }} · {{ $dados['email_financeiro'] }}</dd>
                    <dt class="text-suave">Plano</dt><dd>{{ $plano?->nome }} · {{ $plano ? Formato::moeda($plano->preco_centavos) : '' }}/mês</dd>
                    <dt class="text-suave">Administrador</dt><dd>{{ $admin['admin_nome'] }} · {{ $admin['admin_email'] }}</dd>
                </dl>
                <div class="flex flex-wrap justify-between gap-2">
                    <div class="flex gap-2">
                        <x-botao :href="route('plataforma.clientes.novo', ['etapa' => 3])" icone="voltar">Voltar</x-botao>
                        <form method="POST" action="{{ route('plataforma.clientes.novo.cancelar') }}">
                            @csrf
                            <x-botao variante="fantasma" type="submit">Descartar</x-botao>
                        </form>
                    </div>
                    <form method="POST" action="{{ route('plataforma.clientes.novo.concluir') }}">
                        @csrf
                        <x-botao variante="primaria" type="submit" icone="marcado">Criar cliente</x-botao>
                    </form>
                </div>
            </div>
        @endif
    </x-cartao>
</x-layouts.app>
