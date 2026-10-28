{{--
    Selo de status. A cor NUNCA aparece sozinha: sempre com ícone e texto.

    tipo: ativo (verde: liberado/ativo) | negado (vermelho: negado/erro)
          | atencao (âmbar) | inativo (cinza: inativo/arquivado) | info (azul)

    Uso: <x-status tipo="ativo">Ativo</x-status>
--}}
@props(['tipo' => 'info'])

@php
    $estilos = [
        'ativo' => ['bg-sucesso-suave text-sucesso', 'sucesso'],
        'negado' => ['bg-perigo-suave text-perigo', 'erro'],
        'atencao' => ['bg-atencao-suave text-atencao', 'atencao'],
        'inativo' => ['bg-inativo-suave text-inativo', 'inativo'],
        'info' => ['bg-primaria-suave text-primaria', 'info'],
    ];
    [$cores, $icone] = $estilos[$tipo] ?? $estilos['info'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap {$cores}"]) }}>
    <x-icone :nome="$icone" class="size-3.5" />
    {{ $slot }}
</span>
