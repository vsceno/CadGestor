{{--
    Tabela de dados padrão.

    - Cabeçalho fixo ao rolar (dentro da área com rolagem).
    - Densidade: o usuário alterna entre normal e compacta (lembrada no navegador).
    - Colunas configuráveis: cada <th>/<td> com data-coluna="chave"; o menu
      "Colunas" lista as de :colunas e esconde/mostra (lembrado no navegador).
    - Seleção em lote: use <x-tabela.selecionar-todos /> no cabeçalho e
      <x-tabela.selecionar :valor="$id" /> em cada linha; a barra (slot "lote")
      aparece com a quantidade selecionada.
    - Slots: filtros (busca + chips), cabecalho (<tr> do thead), slot padrão
      (linhas do tbody), vazio (quando não há linhas), rodape (paginação), lote.

    id é obrigatório (identifica as preferências desta tabela).
    colunas: ['cnpj' => 'CNPJ', 'plano' => 'Plano'] (as que o usuário pode esconder)
--}}
@props(['id', 'colunas' => [], 'legenda' => null, 'vazia' => false])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }} data-tabela="{{ $id }}">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0 flex-1">{{ $filtros ?? '' }}</div>
        <div class="flex items-center gap-2">
            @if ($colunas !== [])
                <details class="relative" data-menu-colunas>
                    <summary class="inline-flex h-8 cursor-pointer list-none items-center gap-1.5 rounded-controle border border-borda-forte bg-superficie px-3 text-sm font-medium hover:bg-superficie-2 [&::-webkit-details-marker]:hidden">
                        <x-icone nome="regras" class="size-4" /> Colunas
                    </summary>
                    <div class="absolute right-0 z-20 mt-1 w-56 rounded-cartao border border-borda bg-superficie p-2 shadow-alta">
                        @foreach ($colunas as $chave => $rotulo)
                            <label class="flex cursor-pointer items-center gap-2 rounded-controle px-2 py-1.5 text-sm hover:bg-superficie-2">
                                <input type="checkbox" class="size-4 accent-primaria" checked data-alternar-coluna="{{ $chave }}">
                                {{ $rotulo }}
                            </label>
                        @endforeach
                    </div>
                </details>
            @endif
            <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-controle border border-borda-forte bg-superficie px-3 text-sm font-medium hover:bg-superficie-2"
                data-alternar-densidade aria-pressed="false">
                <x-icone nome="menu" class="size-4" /> Compacta
            </button>
        </div>
    </div>

    @if (isset($lote) && ! $vazia)
        <div class="flex flex-wrap items-center gap-3 rounded-cartao border border-primaria/30 bg-primaria-suave px-4 py-2 text-sm" data-barra-lote hidden>
            <span class="font-semibold text-primaria"><span data-contador-lote>0</span> selecionado(s)</span>
            {{ $lote }}
        </div>
    @endif

    <div class="max-h-[70vh] overflow-auto rounded-cartao border border-borda bg-superficie shadow-cartao">
        <table class="w-full border-collapse text-left text-sm" data-densidade="normal">
            @if ($legenda)<caption class="sr-only">{{ $legenda }}</caption>@endif
            <thead class="sticky top-0 z-10 bg-superficie-2 text-xs font-semibold tracking-wide text-suave uppercase shadow-[0_1px_0_var(--cg-borda)]">
                {{ $cabecalho }}
            </thead>
            <tbody class="divide-y divide-borda [&_td]:px-4 [&_td]:py-3 in-data-[densidade=compacta]:[&_td]:py-1.5 [&_tr]:transition-colors [&_tr:hover]:bg-superficie-2/60">
                @if ($vazia)
                    <tr><td colspan="99">{{ $vazio ?? '' }}</td></tr>
                @else
                    {{ $slot }}
                @endif
            </tbody>
        </table>
    </div>

    @if (isset($rodape) && ! $vazia)
        <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-suave">{{ $rodape }}</div>
    @endif
</div>
