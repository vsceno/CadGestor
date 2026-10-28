{{--
    Lista de escolha (<select>) com o mesmo padrão do <x-campo>: rótulo visível,
    dica e erro ao lado.

    opcoes: [valor => rótulo]. vazio: texto da opção vazia (null = sem opção vazia).

    Uso: <x-selecao nome="uf" rotulo="UF" :opcoes="$ufs" vazio="Selecione" obrigatorio />
--}}
@props([
    'nome',
    'rotulo',
    'opcoes' => [],
    'valor' => null,
    'vazio' => 'Selecione',
    'ajuda' => null,
    'obrigatorio' => false,
    'semOpcional' => false,
    'id' => null,
])

@php
    $id ??= str_replace(['[', ']', '.'], ['-', '', '-'], $nome);
    $chaveErro = str_replace(['[', ']'], ['.', ''], $nome);
    $erro = ($errors ?? new \Illuminate\Support\ViewErrorBag)->first($chaveErro);
    $valorAtual = (string) old($chaveErro, $valor);
    $descricoes = trim(($ajuda ? "{$id}-ajuda " : '').($erro ? "{$id}-erro" : ''));
@endphp

<div {{ $attributes->only('class')->merge(['class' => 'flex flex-col gap-1.5']) }}>
    <label for="{{ $id }}" class="text-sm font-medium text-texto">
        {{ $rotulo }}
        @unless ($obrigatorio || $semOpcional)<span class="font-normal text-suave">(opcional)</span>@endunless
    </label>

    <select id="{{ $id }}" name="{{ $nome }}"
        {{ $attributes->except('class')->merge(['autocomplete' => 'on', 'class' => 'block h-10 w-full rounded-controle border bg-superficie px-3 text-texto shadow-cartao transition-colors hover:border-suave focus:border-primaria '.($erro ? 'border-perigo' : 'border-borda-forte')]) }}
        @required($obrigatorio)
        @if ($erro) aria-invalid="true" @endif
        @if ($descricoes) aria-describedby="{{ $descricoes }}" @endif>
        @if ($vazio !== null)
            <option value="">{{ $vazio }}</option>
        @endif
        @foreach ($opcoes as $valorOpcao => $rotuloOpcao)
            <option value="{{ $valorOpcao }}" @selected($valorAtual === (string) $valorOpcao)>{{ $rotuloOpcao }}</option>
        @endforeach
    </select>

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
