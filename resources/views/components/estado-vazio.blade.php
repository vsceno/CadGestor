{{--
    Estado vazio: diz o que falta e qual é o próximo passo (nunca só "nenhum registro").

    Slot "acao": botão do próximo passo.

    Uso:
    <x-estado-vazio titulo="Nenhuma pessoa cadastrada" descricao="Cadastre a primeira pessoa para liberar o acesso dela.">
        <x-slot:acao><x-botao variante="primaria" icone="adicionar" href="...">Cadastrar pessoa</x-botao></x-slot:acao>
    </x-estado-vazio>
--}}
@props(['titulo', 'descricao' => null, 'icone' => 'vazio'])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-3 px-6 py-12 text-center']) }}>
    <span class="flex size-12 items-center justify-center rounded-full bg-primaria-suave text-primaria">
        <x-icone :nome="$icone" class="size-6" />
    </span>
    <p class="text-base font-semibold text-texto">{{ $titulo }}</p>
    @if ($descricao)
        <p class="max-w-md text-sm text-suave">{{ $descricao }}</p>
    @endif
    @isset($acao)
        <div class="mt-2">{{ $acao }}</div>
    @endisset
</div>
