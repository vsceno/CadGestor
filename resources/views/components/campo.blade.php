{{--
    Campo de formulário com rótulo SEMPRE visível (o placeholder é só exemplo),
    dica, erro ao lado do campo e máscara brasileira opcional.

    tipo:    text | email | password | number | date | time | tel | search | textarea
    mascara: cpf | cnpj | cep | telefone (aplicada enquanto digita; o servidor valida de novo)
    O erro vem sozinho do validador do Laravel ($errors) pelo nome do campo.
    Campo não obrigatório ganha "(opcional)" no rótulo.

    Uso: <x-campo nome="cnpj" rotulo="CNPJ" mascara="cnpj" obrigatorio />
--}}
@props([
    'nome',
    'rotulo',
    'tipo' => 'text',
    'valor' => null,
    'ajuda' => null,
    'obrigatorio' => false,
    'mascara' => null,
    'id' => null,
])

@php
    $id ??= str_replace(['[', ']', '.'], ['-', '', '-'], $nome);
    $chaveErro = str_replace(['[', ']'], ['.', ''], $nome);
    $erro = ($errors ?? new \Illuminate\Support\ViewErrorBag)->first($chaveErro);
    $valorAtual = old($chaveErro, $valor);
    $descricoes = trim(($ajuda ? "{$id}-ajuda " : '').($erro ? "{$id}-erro" : ''));

    $classesControle = 'block w-full rounded-controle border bg-superficie px-3 text-texto shadow-cartao '
        .'placeholder:text-suave/70 transition-colors hover:border-suave focus:border-primaria '
        .'disabled:cursor-not-allowed disabled:bg-superficie-2 '
        .($erro ? 'border-perigo bg-perigo-suave/30' : 'border-borda-forte');

    $mascaras = [
        'cpf' => ['inputmode' => 'numeric', 'maxlength' => 14, 'placeholder' => '000.000.000-00'],
        'cnpj' => ['maxlength' => 18, 'placeholder' => '00.000.000/0000-00'],
        'cep' => ['inputmode' => 'numeric', 'maxlength' => 9, 'placeholder' => '00000-000'],
        'telefone' => ['inputmode' => 'tel', 'maxlength' => 15, 'placeholder' => '(00) 00000-0000'],
    ];
    $extras = $mascara ? ['data-mascara' => $mascara] + ($mascaras[$mascara] ?? []) : [];

    // Preenchimento automático do navegador (acessibilidade): valor sensato por tipo; quem usa pode trocar.
    $extras['autocomplete'] = match (true) {
        $tipo === 'email' => 'email',
        $tipo === 'password' => 'current-password',
        $mascara === 'telefone' => 'tel',
        $mascara === 'cep' => 'postal-code',
        default => 'on',
    };
@endphp

<div {{ $attributes->only('class')->merge(['class' => 'flex flex-col gap-1.5']) }}>
    <label for="{{ $id }}" class="text-sm font-medium text-texto">
        {{ $rotulo }}
        @unless ($obrigatorio)<span class="font-normal text-suave">(opcional)</span>@endunless
    </label>

    @if ($tipo === 'textarea')
        <textarea id="{{ $id }}" name="{{ $nome }}" rows="4"
            {{ $attributes->except('class')->merge(['autocomplete' => 'on', 'class' => $classesControle.' py-2']) }}
            @required($obrigatorio)
            @if ($erro) aria-invalid="true" @endif
            @if ($descricoes) aria-describedby="{{ $descricoes }}" @endif>{{ $valorAtual }}</textarea>
    @else
        <input id="{{ $id }}" name="{{ $nome }}" type="{{ $tipo }}"
            @if ($tipo !== 'password') value="{{ $valorAtual }}" @endif
            {{ $attributes->except('class')->merge($extras)->merge(['class' => $classesControle.' h-10']) }}
            @required($obrigatorio)
            @if ($erro) aria-invalid="true" @endif
            @if ($descricoes) aria-describedby="{{ $descricoes }}" @endif>
    @endif

    @if ($ajuda)
        <p id="{{ $id }}-ajuda" class="text-sm text-suave">{{ $ajuda }}</p>
    @endif

    @if ($erro)
        <p id="{{ $id }}-erro" class="flex items-start gap-1.5 text-sm font-medium text-perigo">
            <x-icone nome="erro" class="mt-0.5 size-4" />
            <span>{{ $erro }}</span>
        </p>
    @endif
</div>
