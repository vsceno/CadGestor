{{--
    Cartão (bloco de conteúdo). Título opcional; slot "acoes" no canto do título.
    preenchimento=false para tabelas que encostam nas bordas.
--}}
@props(['titulo' => null, 'descricao' => null, 'preenchimento' => true])

<section {{ $attributes->merge(['class' => 'rounded-cartao border border-borda bg-superficie shadow-cartao']) }}>
    @if ($titulo || isset($acoes))
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-borda px-5 py-4">
            <div>
                @if ($titulo)<h2 class="text-base">{{ $titulo }}</h2>@endif
                @if ($descricao)<p class="mt-0.5 text-sm text-suave">{{ $descricao }}</p>@endif
            </div>
            @isset($acoes)<div class="flex flex-wrap gap-2">{{ $acoes }}</div>@endisset
        </div>
    @endif
    <div @class(['p-5' => $preenchimento])>{{ $slot }}</div>
</section>
