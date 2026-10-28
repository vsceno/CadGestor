@php
    use App\Models\Fatura;
    use App\Support\Formato;

    $porcentagem = fn (int $uso, int $limite): int => $limite > 0 ? (int) min(100, round($uso * 100 / $limite)) : 0;
@endphp

<x-layouts.app titulo="Minha assinatura">
    <x-cabecalho-pagina titulo="Minha assinatura" subtitulo="Plano, uso, faturas e dados da empresa." />

    @if (! $assinatura)
        <x-alerta tipo="erro">Cliente sem assinatura. Fale com o comercial do CadGestor.</x-alerta>
    @else
        <div class="grid gap-6 xl:grid-cols-2">
            <x-cartao titulo="Plano atual">
                <div class="flex flex-col gap-5">
                    <div class="flex items-baseline justify-between rounded-controle bg-primaria-suave px-4 py-3">
                        <span class="font-semibold text-primaria">{{ $assinatura->plano->nome }}</span>
                        <span class="numeros text-xl font-bold text-primaria">{{ Formato::moeda($assinatura->plano->preco_centavos) }}<span class="text-sm font-medium">/mês</span></span>
                    </div>
                    <p class="flex items-center gap-2 text-sm">
                        <x-status :tipo="$assinatura->status === 'ativa' ? 'ativo' : $situacao->tipoStatus()">{{ $assinatura->rotuloStatus() }}</x-status>
                        @if ($assinatura->teste_ate) até {{ Formato::data($assinatura->teste_ate) }} @endif
                    </p>
                    @foreach (['pessoas' => ['Pessoas ativas', $assinatura->plano->limite_pessoas], 'catracas' => ['Catracas ativas', $assinatura->plano->limite_catracas]] as $chave => [$rotulo, $limite])
                        @php $p = $porcentagem($uso[$chave], $limite); @endphp
                        <div class="flex flex-col gap-1.5">
                            <div class="flex justify-between text-sm">
                                <span class="font-medium">{{ $rotulo }}</span>
                                <span class="numeros {{ $p >= 100 ? 'font-semibold text-perigo' : ($p >= 90 ? 'font-semibold text-atencao' : 'text-suave') }}">
                                    {{ Formato::numero($uso[$chave]) }} de {{ Formato::numero($limite) }}@if ($p >= 100) · limite atingido @endif
                                </span>
                            </div>
                            <progress value="{{ $uso[$chave] }}" max="{{ $limite }}" aria-label="{{ $rotulo }}: {{ $uso[$chave] }} de {{ $limite }}"
                                @class([
                                    'h-2 w-full appearance-none overflow-hidden rounded-full bg-superficie-2 [&::-webkit-progress-bar]:bg-superficie-2 [&::-webkit-progress-value]:rounded-full',
                                    '[&::-moz-progress-bar]:bg-perigo [&::-webkit-progress-value]:bg-perigo' => $p >= 100,
                                    '[&::-moz-progress-bar]:bg-atencao [&::-webkit-progress-value]:bg-atencao' => $p >= 90 && $p < 100,
                                    '[&::-moz-progress-bar]:bg-primaria [&::-webkit-progress-value]:bg-primaria' => $p < 90,
                                ])>{{ $p }}%</progress>
                        </div>
                    @endforeach
                </div>
            </x-cartao>

            @if ($assinatura->status !== 'cancelada')
                <x-cartao titulo="Trocar de plano" descricao="Para um plano menor, o uso atual precisa caber nos limites dele.">
                    <form method="POST" action="{{ route('assinatura.plano') }}" class="flex flex-col gap-3">
                        @csrf
                        @foreach ($planos as $plano)
                            <label class="flex cursor-pointer items-center gap-3 rounded-controle border border-borda-forte p-3 has-checked:border-primaria has-checked:bg-primaria-suave">
                                <input type="radio" name="plano_id" value="{{ $plano->id }}" class="size-4 accent-primaria" required @checked($plano->id === $assinatura->plano_id)>
                                <span class="flex-1">
                                    <span class="block font-semibold">{{ $plano->nome }}{{ $plano->id === $assinatura->plano_id ? ' (atual)' : '' }}</span>
                                    <span class="text-sm text-suave">até {{ Formato::numero($plano->limite_pessoas) }} pessoas · {{ $plano->limite_catracas }} catracas</span>
                                </span>
                                <span class="numeros font-semibold">{{ Formato::moeda($plano->preco_centavos) }}/mês</span>
                            </label>
                        @endforeach
                        @error('plano_id')<p class="text-sm font-medium text-perigo">{{ $message }}</p>@enderror
                        <x-botao variante="primaria" type="submit" class="self-end">Trocar plano</x-botao>
                    </form>
                </x-cartao>
            @endif
        </div>

        <x-cartao titulo="Faturas" descricao="O pagamento (Pix, boleto ou cartão) é feito no meio de pagamento. Nenhum dado de cartão passa pelo CadGestor." :preenchimento="false">
            <div class="p-5">
                <x-tabela id="faturas" legenda="Faturas da assinatura" :vazia="$faturas->isEmpty()">
                    <x-slot:cabecalho>
                        <tr>
                            <x-tabela.coluna>Vencimento</x-tabela.coluna>
                            <x-tabela.coluna numero>Valor</x-tabela.coluna>
                            <x-tabela.coluna>Situação</x-tabela.coluna>
                            <x-tabela.coluna><span class="sr-only">Ações</span></x-tabela.coluna>
                        </tr>
                    </x-slot:cabecalho>
                    <x-slot:vazio>
                        <x-estado-vazio icone="financeiro" titulo="Nenhuma fatura" descricao="As faturas aparecem aqui quando forem geradas." />
                    </x-slot:vazio>
                    @foreach ($faturas as $fatura)
                        <tr>
                            <td class="numeros">{{ Formato::data($fatura->vencimento) }}</td>
                            <td class="numeros text-right">{{ Formato::moeda($fatura->valor_centavos) }}</td>
                            <td><x-status :tipo="Fatura::TIPOS_STATUS[$fatura->status] ?? 'info'">{{ Fatura::ROTULOS[$fatura->status] ?? $fatura->status }}</x-status></td>
                            <td class="text-right">
                                @if (in_array($fatura->status, ['pendente', 'vencida'], true) && $fatura->link_pagamento)
                                    <x-botao tamanho="pequeno" variante="primaria" :href="$fatura->link_pagamento" rel="noopener noreferrer">Pagar</x-botao>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-tabela>
            </div>
        </x-cartao>
    @endif

    <x-cartao titulo="Dados da empresa" descricao="Endereço, inscrições e contatos usados na cobrança e na nota fiscal.">
        <div class="flex flex-wrap items-center justify-between gap-3">
            @if ($cliente->cadastroCompleto())
                <p class="text-sm">{{ $cliente->nome }} · CNPJ {{ Formato::cnpj($cliente->cnpj) }}</p>
            @else
                <x-alerta tipo="atencao" class="flex-1">Complete o endereço, o telefone e o e-mail financeiro: são necessários para a nota fiscal.</x-alerta>
            @endif
            <x-botao icone="editar" :href="route('assinatura.empresa')">Editar dados da empresa</x-botao>
        </div>
    </x-cartao>
</x-layouts.app>
