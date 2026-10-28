{{--
    Cabeçalho padrão das telas: título (e subtítulo) à esquerda; período,
    exportação e a ação principal à direita (slot "acoes").

    Uso:
    <x-cabecalho-pagina titulo="Pessoas" subtitulo="Quem tem acesso às suas unidades">
        <x-slot:acoes>...</x-slot:acoes>
    </x-cabecalho-pagina>
--}}
@props(['titulo', 'subtitulo' => null])

<header {{ $attributes->merge(['class' => 'flex flex-wrap items-end justify-between gap-4']) }}>
    <div class="min-w-0">
        <h1 class="text-2xl">{{ $titulo }}</h1>
        @if ($subtitulo)<p class="mt-1 text-sm text-suave">{{ $subtitulo }}</p>@endif
    </div>
    @isset($acoes)
        <div class="flex flex-wrap items-center gap-2">{{ $acoes }}</div>
    @endisset
</header>
