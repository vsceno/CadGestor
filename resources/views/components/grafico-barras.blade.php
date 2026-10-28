{{--
    Gráfico de barras horizontais acessível, sem biblioteca externa nem estilo
    inline (compatível com a CSP): cada linha tem rótulo, valor e uma barra
    (<progress>). O valor aparece também ao passar o mouse (title) e há uma
    tabela alternativa para leitores de tela.

    dados: [['rotulo' => 'Básico', 'valor' => 12, 'texto' => '12 clientes'], ...]
--}}
@props(['titulo', 'dados' => []])

@php
    $maximo = max(1, ...array_map(fn (array $d): int => (int) $d['valor'], $dados ?: [['valor' => 1]]));
@endphp

<figure {{ $attributes->merge(['class' => 'flex flex-col gap-4']) }}>
    <figcaption class="text-sm font-semibold">{{ $titulo }}</figcaption>

    @if ($dados === [])
        <p class="text-sm text-suave">Sem dados ainda.</p>
    @else
        <ul class="flex flex-col gap-3" aria-hidden="true">
            @foreach ($dados as $d)
                @php $texto = $d['texto'] ?? (string) $d['valor']; @endphp
                <li class="flex flex-col gap-1.5" title="{{ $d['rotulo'] }}: {{ $texto }}">
                    <div class="flex justify-between gap-3 text-sm">
                        <span class="font-medium">{{ $d['rotulo'] }}</span>
                        <span class="numeros text-suave">{{ $texto }}</span>
                    </div>
                    <progress value="{{ (int) $d['valor'] }}" max="{{ $maximo }}"
                        class="h-3 w-full appearance-none overflow-hidden rounded-full bg-superficie-2 [&::-moz-progress-bar]:bg-primaria [&::-webkit-progress-bar]:bg-superficie-2 [&::-webkit-progress-value]:rounded-full [&::-webkit-progress-value]:bg-primaria"></progress>
                </li>
            @endforeach
        </ul>

        {{-- Alternativa para leitores de tela. --}}
        <table class="sr-only">
            <caption>{{ $titulo }}</caption>
            <thead><tr><th scope="col">Item</th><th scope="col">Valor</th></tr></thead>
            <tbody>
                @foreach ($dados as $d)
                    <tr><td>{{ $d['rotulo'] }}</td><td>{{ $d['texto'] ?? $d['valor'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
</figure>
