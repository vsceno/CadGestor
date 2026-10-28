{{--
    Estado "carregando" (skeleton): blocos cinza no formato do conteúdo que vai
    aparecer. Para leitores de tela, anuncia "Carregando...".

    formato: linhas (texto) | tabela | cards
--}}
@props(['formato' => 'linhas', 'quantidade' => 3])

<div role="status" aria-live="polite" {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }}>
    <span class="sr-only">Carregando…</span>
    @if ($formato === 'cards')
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @for ($i = 0; $i < $quantidade; $i++)
                <div class="flex flex-col gap-3 rounded-cartao border border-borda bg-superficie p-5">
                    <div class="esqueleto h-4 w-24"></div>
                    <div class="esqueleto h-8 w-32"></div>
                </div>
            @endfor
        </div>
    @elseif ($formato === 'tabela')
        <div class="flex flex-col gap-2 rounded-cartao border border-borda bg-superficie p-4">
            <div class="esqueleto h-5 w-full"></div>
            @for ($i = 0; $i < $quantidade; $i++)
                <div class="esqueleto h-9 w-full opacity-70"></div>
            @endfor
        </div>
    @else
        @for ($i = 0; $i < $quantidade; $i++)
            <div class="esqueleto h-4 {{ $i === $quantidade - 1 ? 'w-2/3' : 'w-full' }}"></div>
        @endfor
    @endif
</div>
