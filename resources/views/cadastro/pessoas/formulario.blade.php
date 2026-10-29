@php
    $novo = ! $pessoa->exists;
    $ufs = array_combine(\App\Http\Requests\DadosClienteRequest::UFS, \App\Http\Requests\DadosClienteRequest::UFS);
@endphp

<x-layouts.app :titulo="$novo ? 'Nova pessoa' : 'Editar pessoa'">
    <x-migalhas :itens="[['rotulo' => 'Pessoas', 'href' => route('pessoas.index')], ['rotulo' => $novo ? 'Nova pessoa' : $pessoa->nome]]" />
    <x-cabecalho-pagina :titulo="$novo ? 'Nova pessoa' : 'Editar pessoa'"
        subtitulo="As alterações vão para a trilha de auditoria, sem os dados pessoais." />

    <x-cartao class="max-w-3xl">
        <form method="POST" action="{{ $novo ? route('pessoas.salvar') : route('pessoas.atualizar', $pessoa) }}" class="flex flex-col gap-6">
            @csrf
            @unless ($novo) @method('PUT') @endunless

            <fieldset class="flex flex-col gap-4">
                <legend class="mb-1 text-xs font-semibold tracking-wide text-suave uppercase">Identificação</legend>
                <x-campo nome="nome" rotulo="Nome completo" obrigatorio maxlength="150" :valor="$pessoa->nome" ajuda="Como consta no documento." />
                <div class="grid gap-4 sm:grid-cols-2">
                    @if ($novo)
                        <x-campo nome="cpf" rotulo="CPF" mascara="cpf" obrigatorio
                            ajuda="Um CPF é uma pessoa só. Se ela já existe, use o cadastro existente." />
                    @else
                        <x-campo nome="cpf_exibicao" rotulo="CPF" :valor="\App\Support\Formato::cpfMascarado($pessoa->cpf)" disabled
                            ajuda="O CPF não muda depois do cadastro." />
                    @endif
                    <x-campo nome="data_nascimento" tipo="date" rotulo="Data de nascimento" :valor="$pessoa->data_nascimento?->format('Y-m-d')"
                        ajuda="Usada para a regra de menores de idade." />
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend class="mb-1 text-xs font-semibold tracking-wide text-suave uppercase">Documento</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-selecao nome="documento_tipo" rotulo="Tipo de documento" :opcoes="\App\Models\Pessoa::TIPOS_DOCUMENTO"
                        vazio="Não informado" :valor="$pessoa->documento_tipo" />
                    <x-campo nome="documento_numero" rotulo="Número do documento" maxlength="30" :valor="$pessoa->documento_numero" />
                    <x-campo nome="documento_orgao" rotulo="Órgão emissor" maxlength="20" :valor="$pessoa->documento_orgao" ajuda="Ex.: SSP." />
                    <x-selecao nome="documento_uf" rotulo="UF do documento" :opcoes="$ufs" vazio="Não informada" :valor="$pessoa->documento_uf" />
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend class="mb-1 text-xs font-semibold tracking-wide text-suave uppercase">Contato</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-campo nome="email" tipo="email" rotulo="E-mail" maxlength="254" :valor="$pessoa->email" />
                    <x-campo nome="telefone" rotulo="Telefone" mascara="telefone"
                        :valor="$pessoa->telefone ? \App\Support\Formato::telefone($pessoa->telefone) : null" />
                </div>
            </fieldset>

            <div class="flex justify-end gap-2">
                <x-botao :href="$novo ? route('pessoas.index') : route('pessoas.show', $pessoa)">Cancelar</x-botao>
                <x-botao variante="primaria" type="submit" icone="marcado">Salvar</x-botao>
            </div>
        </form>
    </x-cartao>
</x-layouts.app>
