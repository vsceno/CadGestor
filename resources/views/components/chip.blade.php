{{--
    Chip de filtro. Ativo: mostra o valor e um "x" que remove o filtro (link).
    Inativo: link que aplica o filtro.

    Uso: <x-chip :ativo="true" :remover="url(...)">Resultado: Negado</x-chip>
--}}
@props(['ativo' => false, 'href' => null, 'remover' => null])

@if ($ativo)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full border border-primaria/40 bg-primaria-suave py-1 pr-1 pl-3 text-sm font-medium text-primaria']) }}>
        {{ $slot }}
        @if ($remover)
            <a href="{{ $remover }}" class="rounded-full p-0.5 text-primaria hover:bg-primaria/15" aria-label="Remover filtro: {{ trim(strip_tags($slot)) }}">
                <x-icone nome="fechar" class="size-3.5" />
            </a>
        @endif
    </span>
@else
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full border border-borda-forte bg-superficie px-3 py-1 text-sm font-medium text-texto no-underline hover:bg-superficie-2']) }}>
        <x-icone nome="adicionar" class="size-3.5" /> {{ $slot }}
    </a>
@endif
