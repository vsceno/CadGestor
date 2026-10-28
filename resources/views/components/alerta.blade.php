{{--
    Mensagem para o usuário (sucesso, erro, atenção ou informação).

    - Erro NÃO some sozinho e não tem botão de fechar: fica até ser resolvido.
    - Sucesso é curto e pode ser fechado. Com :desfazer (URL de um POST), mostra
      o botão "Desfazer".
    - Sempre com ícone + título/texto (nunca só a cor).

    Uso: <x-alerta tipo="erro" titulo="Não foi possível salvar">Confira os campos destacados.</x-alerta>
--}}
@props([
    'tipo' => 'info',
    'titulo' => null,
    'desfazer' => null,
    'fechavel' => null,
])

@php
    $estilos = [
        'sucesso' => ['border-sucesso/30 bg-sucesso-suave text-sucesso', 'sucesso', 'status'],
        'erro' => ['border-perigo/30 bg-perigo-suave text-perigo', 'erro', 'alert'],
        'atencao' => ['border-atencao/30 bg-atencao-suave text-atencao', 'atencao', 'status'],
        'info' => ['border-primaria/30 bg-primaria-suave text-primaria', 'info', 'status'],
    ];
    [$cores, $icone, $papel] = $estilos[$tipo] ?? $estilos['info'];
    $fechavel ??= $tipo === 'sucesso';
@endphp

<div role="{{ $papel }}" {{ $attributes->merge(['class' => "flex items-start gap-3 rounded-cartao border p-4 {$cores}"]) }} data-alerta>
    <x-icone :nome="$icone" class="mt-0.5 size-5" />
    <div class="min-w-0 flex-1 text-sm">
        @if ($titulo)<p class="font-semibold">{{ $titulo }}</p>@endif
        <div class="text-texto">{{ $slot }}</div>
    </div>
    @if ($desfazer)
        <form method="POST" action="{{ $desfazer }}">
            @csrf
            <x-botao tamanho="pequeno" variante="secundaria" icone="desfazer" type="submit">Desfazer</x-botao>
        </form>
    @endif
    @if ($fechavel && $tipo !== 'erro')
        <button type="button" class="rounded-controle p-1 hover:bg-superficie/60" data-fechar-alerta aria-label="Fechar mensagem">
            <x-icone nome="fechar" class="size-4" />
        </button>
    @endif
</div>
