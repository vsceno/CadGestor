{{--
    Cabeçalho de coluna, opcionalmente ordenável.

    ordenavel: chave do campo (vai para ?ordem=chave&direcao=asc|desc).
    A ordem atual é lida da própria requisição; aria-sort informa o leitor de tela.
    numero: alinha à direita (valores e contagens).
    coluna: chave para o menu "Colunas" (data-coluna).

    Uso: <x-tabela.coluna ordenavel="nome">Nome</x-tabela.coluna>
--}}
@props(['ordenavel' => null, 'numero' => false, 'coluna' => null])

@php
    $ordemAtual = request()->query('ordem');
    $direcaoAtual = request()->query('direcao') === 'desc' ? 'desc' : 'asc';
    $ativa = $ordenavel !== null && $ordemAtual === $ordenavel;
    $proxima = $ativa && $direcaoAtual === 'asc' ? 'desc' : 'asc';
    $ariaSort = $ativa ? ($direcaoAtual === 'asc' ? 'ascending' : 'descending') : null;
@endphp

<th scope="col" @if ($coluna) data-coluna="{{ $coluna }}" @endif @if ($ariaSort) aria-sort="{{ $ariaSort }}" @endif
    {{ $attributes->merge(['class' => 'px-4 py-3 whitespace-nowrap'.($numero ? ' text-right' : '')]) }}>
    @if ($ordenavel)
        <a href="{{ request()->fullUrlWithQuery(['ordem' => $ordenavel, 'direcao' => $proxima, 'page' => null]) }}"
           class="inline-flex items-center gap-1 text-suave no-underline hover:text-texto {{ $ativa ? 'text-texto' : '' }}">
            {{ $slot }}
            <x-icone :nome="$ativa ? ($direcaoAtual === 'asc' ? 'seta-cima' : 'seta-baixo') : 'ordenar'" class="size-3.5" />
        </a>
    @else
        {{ $slot }}
    @endif
</th>
