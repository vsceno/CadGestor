{{--
    Migalhas de pão (telas de detalhe). O último item é a página atual (sem link).

    itens: [['rotulo' => 'Pessoas', 'href' => route(...)], ['rotulo' => 'João Silva']]
--}}
@props(['itens' => []])

<nav aria-label="Você está em" {{ $attributes->merge(['class' => 'text-sm']) }}>
    <ol class="flex flex-wrap items-center gap-1.5 text-suave">
        @foreach ($itens as $item)
            <li class="flex items-center gap-1.5">
                @if (! $loop->first)<x-icone nome="avancar" class="size-3.5" />@endif
                @if (! $loop->last && ! empty($item['href']))
                    <a href="{{ $item['href'] }}" class="text-suave no-underline hover:text-primaria hover:underline">{{ $item['rotulo'] }}</a>
                @else
                    <span @if ($loop->last) aria-current="page" class="font-medium text-texto" @endif>{{ $item['rotulo'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
