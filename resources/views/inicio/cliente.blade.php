@php
    $concluidos = count(array_filter($passos, fn (array $p): bool => $p['concluido']));
    $total = count($passos);
    $percentual = $total === 0 ? 100 : (int) round($concluidos * 100 / $total);
@endphp

<x-layouts.app titulo="Início">
    <x-cabecalho-pagina titulo="Início" :subtitulo="'Olá, '.explode(' ', auth()->user()->nome)[0].'.'" />

    @if ($concluidos < $total)
        <x-cartao titulo="Primeiros passos" descricao="Complete a configuração para começar a controlar os acessos.">
            <div class="flex flex-col gap-5">
                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium">{{ $concluidos }} de {{ $total }} concluídos</span>
                        <span class="numeros text-suave">{{ $percentual }}%</span>
                    </div>
                    {{-- <progress> nativo: sem estilo inline (a CSP proíbe) e já acessível. --}}
                    <progress value="{{ $concluidos }}" max="{{ $total }}" aria-label="Progresso da configuração"
                        class="h-2 w-full appearance-none overflow-hidden rounded-full bg-superficie-2 [&::-moz-progress-bar]:bg-primaria [&::-webkit-progress-bar]:bg-superficie-2 [&::-webkit-progress-value]:rounded-full [&::-webkit-progress-value]:bg-primaria">
                        {{ $percentual }}%
                    </progress>
                </div>

                <ol class="flex flex-col divide-y divide-borda">
                    @foreach ($passos as $indice => $passo)
                        <li class="flex items-start gap-4 py-3">
                            @if ($passo['concluido'])
                                <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-sucesso-suave text-sucesso">
                                    <x-icone nome="marcado" class="size-4" rotulo="Concluído" />
                                </span>
                            @else
                                <span class="numeros mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full border border-borda-forte text-sm font-semibold text-suave">
                                    {{ $indice + 1 }}
                                </span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <p @class(['font-medium', 'text-suave line-through' => $passo['concluido']])>{{ $passo['titulo'] }}</p>
                                <p class="text-sm text-suave">{{ $passo['descricao'] }}</p>
                            </div>
                            @if (! $passo['concluido'])
                                @if ($passo['href'])
                                    <x-botao tamanho="pequeno" :href="$passo['href']">Fazer agora</x-botao>
                                @else
                                    <x-status tipo="inativo">Em breve</x-status>
                                @endif
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </x-cartao>
    @else
        <x-alerta tipo="sucesso" :fechavel="false" titulo="Configuração concluída">O seu ambiente está pronto para controlar os acessos.</x-alerta>
    @endif
</x-layouts.app>
