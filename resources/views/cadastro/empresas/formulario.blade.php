@php
    $novo = ! $empresa->exists;
    $ufs = array_combine(\App\Http\Requests\DadosClienteRequest::UFS, \App\Http\Requests\DadosClienteRequest::UFS);
@endphp

<x-layouts.app :titulo="$novo ? 'Nova empresa' : 'Editar empresa'">
    <x-migalhas :itens="[['rotulo' => 'Empresas', 'href' => route('empresas.index')], ['rotulo' => $novo ? 'Nova empresa' : $empresa->razao_social]]" />
    <x-cabecalho-pagina :titulo="$novo ? 'Nova empresa' : 'Editar empresa'"
        subtitulo="O CNPJ não pode repetir dentro do cliente. As alterações ficam na trilha de auditoria." />

    <x-cartao class="max-w-3xl">
        <form method="POST" action="{{ $novo ? route('empresas.salvar') : route('empresas.atualizar', $empresa) }}" class="flex flex-col gap-6">
            @csrf
            @unless ($novo) @method('PUT') @endunless

            <fieldset class="flex flex-col gap-4">
                <legend class="mb-1 text-xs font-semibold tracking-wide text-suave uppercase">Identificação</legend>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-campo class="sm:col-span-2" nome="razao_social" rotulo="Razão social" obrigatorio maxlength="200" :valor="$empresa->razao_social" />
                    <x-campo nome="cnpj" rotulo="CNPJ" mascara="cnpj" obrigatorio :valor="$empresa->cnpj ? \App\Support\Formato::cnpj($empresa->cnpj) : null"
                        ajuda="Aceita o CNPJ alfanumérico." />
                </div>
                <x-campo nome="nome_fantasia" rotulo="Nome fantasia" maxlength="200" :valor="$empresa->nome_fantasia" />
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend class="mb-1 text-xs font-semibold tracking-wide text-suave uppercase">Endereço</legend>
                <div class="grid gap-4 sm:grid-cols-6">
                    <x-campo class="sm:col-span-2" nome="cep" rotulo="CEP" mascara="cep" obrigatorio :valor="$empresa->cep ? \App\Support\Formato::cep($empresa->cep) : null" />
                    <x-campo class="sm:col-span-4" nome="logradouro" rotulo="Logradouro" obrigatorio maxlength="200" :valor="$empresa->logradouro" />
                    <x-campo class="sm:col-span-2" nome="numero" rotulo="Número" obrigatorio maxlength="20" :valor="$empresa->numero" />
                    <x-campo class="sm:col-span-4" nome="complemento" rotulo="Complemento" maxlength="100" :valor="$empresa->complemento" />
                    <x-campo class="sm:col-span-2" nome="bairro" rotulo="Bairro" obrigatorio maxlength="100" :valor="$empresa->bairro" />
                    <x-campo class="sm:col-span-3" nome="municipio" rotulo="Município" obrigatorio maxlength="100" :valor="$empresa->municipio" />
                    <x-selecao class="sm:col-span-1" nome="uf" rotulo="UF" obrigatorio :opcoes="$ufs" vazio="UF" :valor="$empresa->uf" />
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-4">
                <legend class="mb-1 text-xs font-semibold tracking-wide text-suave uppercase">Responsável</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-campo nome="responsavel_nome" rotulo="Nome" obrigatorio maxlength="150" :valor="$empresa->responsavel_nome" />
                    <x-campo nome="responsavel_cargo" rotulo="Cargo" maxlength="100" :valor="$empresa->responsavel_cargo" />
                    <x-campo nome="responsavel_email" tipo="email" rotulo="E-mail" obrigatorio maxlength="254" :valor="$empresa->responsavel_email" />
                    <x-campo nome="responsavel_telefone" rotulo="Telefone" mascara="telefone" obrigatorio
                        :valor="$empresa->responsavel_telefone ? \App\Support\Formato::telefone($empresa->responsavel_telefone) : null" />
                </div>
            </fieldset>

            <div class="flex justify-end gap-2">
                <x-botao :href="$novo ? route('empresas.index') : route('empresas.configurar', $empresa)">Cancelar</x-botao>
                <x-botao variante="primaria" type="submit" icone="marcado">Salvar</x-botao>
            </div>
        </form>
    </x-cartao>
</x-layouts.app>
