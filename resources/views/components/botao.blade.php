{{--
    Botão (ou link com aparência de botão, quando recebe href).

    variante: primaria (ação principal da tela, uma só) | secundaria | perigo | fantasma
    tamanho:  pequeno | medio | grande (portaria/tablet)
    icone:    nome de <x-icone> à esquerda do texto
    Sem texto visível (só ícone)? passe :rotulo para o leitor de tela.

    Uso: <x-botao variante="primaria" icone="adicionar" type="submit">Novo cliente</x-botao>
--}}
@props([
    'variante' => 'secundaria',
    'tamanho' => 'medio',
    'icone' => null,
    'href' => null,
    'rotulo' => null,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-controle font-semibold whitespace-nowrap '
        .'transition-colors disabled:cursor-not-allowed disabled:opacity-60 aria-disabled:pointer-events-none aria-disabled:opacity-60';

    $variantes = [
        'primaria' => 'bg-primaria text-sobre-primaria shadow-cartao hover:bg-primaria-hover hover:text-sobre-primaria',
        'secundaria' => 'border border-borda-forte bg-superficie text-texto shadow-cartao hover:bg-superficie-2 hover:text-texto',
        'perigo' => 'border border-perigo/40 bg-superficie text-perigo hover:bg-perigo-suave hover:text-perigo',
        'fantasma' => 'text-texto hover:bg-superficie-2 hover:text-texto',
    ];

    $tamanhos = [
        'pequeno' => 'h-8 px-3 text-sm',
        'medio' => 'h-10 px-4 text-sm',
        'grande' => 'h-14 px-6 text-lg',
    ];

    $classes = $base.' '.($variantes[$variante] ?? $variantes['secundaria']).' '.($tamanhos[$tamanho] ?? $tamanhos['medio']);
    $tamanhoIcone = $tamanho === 'grande' ? 'size-6' : 'size-4';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }} @if ($rotulo) aria-label="{{ $rotulo }}" @endif>
        @if ($icone)<x-icone :nome="$icone" :class="$tamanhoIcone" />@endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'class' => $classes]) }} @if ($rotulo) aria-label="{{ $rotulo }}" @endif>
        @if ($icone)<x-icone :nome="$icone" :class="$tamanhoIcone" />@endif
        {{ $slot }}
    </button>
@endif
