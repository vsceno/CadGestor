@php $novo = ! $plano->exists; @endphp

<x-layouts.app :titulo="$novo ? 'Novo plano' : 'Editar plano'">
    <x-migalhas :itens="[['rotulo' => 'Planos', 'href' => route('plataforma.planos.index')], ['rotulo' => $novo ? 'Novo plano' : $plano->nome]]" />
    <x-cabecalho-pagina :titulo="$novo ? 'Novo plano' : 'Editar plano '.$plano->nome" />

    <x-cartao class="max-w-2xl">
        <form method="POST" action="{{ $novo ? route('plataforma.planos.salvar') : route('plataforma.planos.atualizar', $plano) }}" class="flex flex-col gap-4">
            @csrf
            @unless ($novo) @method('PUT') @endunless

            @if ($novo)
                <x-campo nome="codigo" rotulo="Código" obrigatorio maxlength="30" ajuda="Identifica o plano nos contratos e não muda depois. Ex.: profissional." />
            @endif
            <x-campo nome="nome" rotulo="Nome" obrigatorio maxlength="60" :valor="$plano->nome" />
            <x-campo nome="descricao" rotulo="Descrição" maxlength="255" :valor="$plano->descricao" />
            <div class="grid gap-4 sm:grid-cols-3">
                <x-campo nome="preco" rotulo="Preço mensal (R$)" obrigatorio inputmode="decimal" placeholder="0,00"
                    :valor="$plano->preco_centavos !== null ? number_format($plano->preco_centavos / 100, 2, ',', '.') : null" :id="'preco'" />
                <x-campo nome="limite_pessoas" tipo="number" rotulo="Limite de pessoas" obrigatorio min="1" :valor="$plano->limite_pessoas" />
                <x-campo nome="limite_catracas" tipo="number" rotulo="Limite de catracas" obrigatorio min="1" :valor="$plano->limite_catracas" />
            </div>
            @error('preco_centavos')<p class="text-sm font-medium text-perigo">{{ $message }}</p>@enderror
            <x-campo nome="ordem" tipo="number" rotulo="Ordem de exibição" obrigatorio min="0" :valor="$plano->ordem" />
            <label class="flex items-center gap-2 text-sm">
                <input type="hidden" name="disponivel_venda" value="0">
                <input type="checkbox" name="disponivel_venda" value="1" class="size-4 accent-primaria" @checked(old('disponivel_venda', $plano->disponivel_venda))>
                Disponível para venda (aparece na contratação pelo site)
            </label>
            <div class="flex justify-end gap-2">
                <x-botao :href="route('plataforma.planos.index')">Cancelar</x-botao>
                <x-botao variante="primaria" type="submit" icone="marcado">Salvar</x-botao>
            </div>
        </form>
    </x-cartao>
</x-layouts.app>
