{{--
    Botão de tema: alterna entre seguir o sistema, claro e escuro.
    A escolha fica num cookie (sem dado pessoal) e o servidor já entrega a
    página no tema certo nas próximas visitas.
--}}
@props(['tema' => 'sistema'])

@php
    $rotulos = ['sistema' => 'Tema: igual ao sistema', 'claro' => 'Tema: claro', 'escuro' => 'Tema: escuro'];
    $icones = ['sistema' => 'monitor', 'claro' => 'sol', 'escuro' => 'lua'];
@endphp

<button type="button" data-alternar-tema data-tema-atual="{{ $tema }}"
    class="inline-flex size-10 items-center justify-center rounded-controle text-texto hover:bg-superficie-2"
    aria-label="{{ $rotulos[$tema] }} (clique para mudar)" title="{{ $rotulos[$tema] }}">
    @foreach ($icones as $chave => $icone)
        <x-icone :nome="$icone" class="size-5" data-icone-tema="{{ $chave }}" :hidden="$chave !== $tema" />
    @endforeach
</button>
