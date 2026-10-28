{{--
    Campos de cadastro de um cliente (pessoa jurídica).
    identificacao=true inclui razão social e CNPJ (só a plataforma altera).

    dados: array ou modelo com os valores atuais.
--}}
@props(['dados' => [], 'identificacao' => true])

@php
    $v = fn (string $campo): ?string => data_get($dados, $campo);
    $ufs = array_combine(\App\Http\Requests\DadosClienteRequest::UFS, \App\Http\Requests\DadosClienteRequest::UFS);
    $fmt = fn (string $campo, callable $formatar): ?string => filled($v($campo)) ? $formatar($v($campo)) : null;
@endphp

<div class="flex flex-col gap-6">
    @if ($identificacao)
        <fieldset class="flex flex-col gap-4">
            <legend class="mb-1 text-xs font-semibold tracking-wide text-suave uppercase">Identificação</legend>
            <div class="grid gap-4 sm:grid-cols-3">
                <x-campo class="sm:col-span-2" nome="nome" rotulo="Razão social" obrigatorio maxlength="150" autocomplete="organization" :valor="$v('nome')" />
                <x-campo nome="cnpj" rotulo="CNPJ" mascara="cnpj" obrigatorio :valor="$fmt('cnpj', [\App\Support\Formato::class, 'cnpj'])" />
            </div>
        </fieldset>
    @endif

    <fieldset class="flex flex-col gap-4">
        <legend class="mb-1 text-xs font-semibold tracking-wide text-suave uppercase">Dados fiscais</legend>
        <x-campo nome="nome_fantasia" rotulo="Nome fantasia" maxlength="150" :valor="$v('nome_fantasia')" />
        <div class="grid gap-4 sm:grid-cols-2">
            <x-campo nome="inscricao_municipal" rotulo="Inscrição municipal" maxlength="20" :valor="$v('inscricao_municipal')"
                ajuda="Necessária para emitir a nota fiscal de serviço." />
            <x-campo nome="inscricao_estadual" rotulo="Inscrição estadual" maxlength="20" placeholder="ou ISENTO" :valor="$v('inscricao_estadual')" />
        </div>
    </fieldset>

    <fieldset class="flex flex-col gap-4">
        <legend class="mb-1 text-xs font-semibold tracking-wide text-suave uppercase">Endereço</legend>
        <div class="grid gap-4 sm:grid-cols-6">
            <x-campo class="sm:col-span-2" nome="cep" rotulo="CEP" mascara="cep" obrigatorio :valor="$fmt('cep', [\App\Support\Formato::class, 'cep'])" />
            <x-campo class="sm:col-span-4" nome="logradouro" rotulo="Logradouro" obrigatorio maxlength="200" :valor="$v('logradouro')" />
            <x-campo class="sm:col-span-2" nome="numero" rotulo="Número" obrigatorio maxlength="20" :valor="$v('numero')" />
            <x-campo class="sm:col-span-4" nome="complemento" rotulo="Complemento" maxlength="100" :valor="$v('complemento')" />
            <x-campo class="sm:col-span-2" nome="bairro" rotulo="Bairro" obrigatorio maxlength="100" :valor="$v('bairro')" />
            <x-campo class="sm:col-span-3" nome="municipio" rotulo="Município" obrigatorio maxlength="100" :valor="$v('municipio')" />
            <x-selecao class="sm:col-span-1" nome="uf" rotulo="UF" obrigatorio :opcoes="$ufs" vazio="UF" :valor="$v('uf')" />
        </div>
    </fieldset>

    <fieldset class="flex flex-col gap-4">
        <legend class="mb-1 text-xs font-semibold tracking-wide text-suave uppercase">Contato</legend>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-campo nome="telefone" rotulo="Telefone da empresa" mascara="telefone" obrigatorio :valor="$fmt('telefone', [\App\Support\Formato::class, 'telefone'])" />
            <x-campo nome="email_financeiro" tipo="email" rotulo="E-mail financeiro" obrigatorio maxlength="254" :valor="$v('email_financeiro')"
                ajuda="Recebe faturas e avisos de cobrança." />
            <x-campo nome="contato_nome" rotulo="Responsável comercial" maxlength="150" :valor="$v('contato_nome')" />
            <x-campo nome="contato_telefone" rotulo="Telefone do responsável" mascara="telefone" :valor="$fmt('contato_telefone', [\App\Support\Formato::class, 'telefone'])" />
        </div>
    </fieldset>
</div>
