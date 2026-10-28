{{--
    Card de indicador (número em destaque no topo das telas).

    variacao: texto curto opcional, ex.: "+3 neste mês"; tendencia: alta | baixa | neutra
    tipo: normal | destaque (fundo azul, o indicador mais importante) | atencao | perigo
    href: torna o card clicável (leva para a lista filtrada).

    Uso: <x-indicador rotulo="Clientes ativos" valor="42" icone="clientes" variacao="+3 neste mês" tendencia="alta" />
--}}
@props([
    'rotulo',
    'valor',
    'icone' => null,
    'variacao' => null,
    'tendencia' => 'neutra',
    'tipo' => 'normal',
    'href' => null,
])

@php
    $fundo = match ($tipo) {
        'destaque' => 'bg-primaria text-sobre-primaria border-transparent',
        default => 'bg-superficie text-texto border-borda',
    };
    $corValor = match ($tipo) {
        'atencao' => 'text-atencao',
        'perigo' => 'text-perigo',
        default => '',
    };
    $suave = $tipo === 'destaque' ? 'text-sobre-primaria/85' : 'text-suave';
    $iconeTendencia = ['alta' => 'seta-cima', 'baixa' => 'seta-baixo'][$tendencia] ?? null;
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => "flex flex-col gap-2 rounded-cartao border p-5 no-underline shadow-cartao {$fundo}".($href ? ' transition-shadow hover:shadow-alta' : '')]) }}>
    <div class="flex items-center justify-between gap-3">
        <span class="text-sm font-medium {{ $suave }}">{{ $rotulo }}</span>
        @if ($icone)<x-icone :nome="$icone" class="size-5 {{ $suave }}" />@endif
    </div>
    <span class="numeros text-3xl font-bold tracking-tight {{ $corValor }}">{{ $valor }}</span>
    @if ($variacao)
        <span class="flex items-center gap-1 text-sm {{ $suave }}">
            @if ($iconeTendencia)<x-icone :nome="$iconeTendencia" class="size-4" />@endif
            {{ $variacao }}
        </span>
    @endif
</{{ $tag }}>
