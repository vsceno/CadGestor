@php $novo = ! $perfil->exists; @endphp

<x-layouts.app :titulo="$novo ? 'Novo perfil' : 'Editar perfil'">
    <x-migalhas :itens="[['rotulo' => 'Perfis de acesso', 'href' => route('perfis.index')], ['rotulo' => $novo ? 'Novo perfil' : $perfil->nome]]" />
    <x-cabecalho-pagina :titulo="$novo ? 'Novo perfil' : 'Editar perfil'"
        subtitulo="A base legal define o fundamento para tratar os dados das pessoas com este perfil. Só o administrador altera." />

    <x-cartao class="max-w-2xl">
        <form method="POST" action="{{ $novo ? route('perfis.salvar') : route('perfis.atualizar', $perfil) }}" class="flex flex-col gap-4">
            @csrf
            @unless ($novo) @method('PUT') @endunless

            <x-campo nome="nome" rotulo="Nome" obrigatorio maxlength="60" :valor="$perfil->nome"
                ajuda="Não pode repetir o nome de outro perfil. Maiúsculas e minúsculas não fazem diferença." />
            <x-campo nome="descricao" tipo="textarea" rotulo="Descrição" maxlength="255" :valor="$perfil->descricao" />
            <x-selecao nome="base_legal" rotulo="Base legal" obrigatorio :opcoes="\App\Models\PerfilVinculo::BASES_LEGAIS" vazio="Selecione"
                :valor="$perfil->base_legal"
                ajuda="Consentimento exige o aceite do titular. As outras bases precisam ser validadas pelo jurídico do cliente." />

            <label class="flex items-start gap-3 text-sm">
                <input type="hidden" name="base_legal_validada" value="0">
                <input type="checkbox" name="base_legal_validada" value="1" class="mt-0.5 size-4 accent-primaria" @checked(old('base_legal_validada', $perfil->base_legal_validada))>
                <span>A base legal foi validada pelo jurídico do cliente</span>
            </label>

            <label class="flex items-start gap-3 text-sm">
                <input type="hidden" name="ativo" value="0">
                <input type="checkbox" name="ativo" value="1" class="mt-0.5 size-4 accent-primaria" @checked(old('ativo', $perfil->ativo))>
                <span>Perfil ativo (aparece nas escolhas das empresas e das pessoas)</span>
            </label>

            <div class="flex justify-end gap-2">
                <x-botao :href="route('perfis.index')">Cancelar</x-botao>
                <x-botao variante="primaria" type="submit" icone="marcado">Salvar</x-botao>
            </div>
        </form>
    </x-cartao>
</x-layouts.app>
