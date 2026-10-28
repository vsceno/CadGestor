{{--
    Modal de confirmação (o ÚNICO uso de modal: confirmar ações). Formulários
    longos têm tela própria.

    - O texto (slot) descreve o IMPACTO: "12 pessoas perderão o acesso".
    - :digitar exige digitar um texto exato (ex.: o nome do cliente) para liberar o botão.
    - Abre com qualquer botão que tenha data-abrir-modal="{id}". <dialog> nativo:
      prende o foco dentro, fecha com Esc e devolve o foco ao botão de origem.

    Uso:
    <x-botao variante="perigo" data-abrir-modal="bloquear-cliente">Bloquear</x-botao>
    <x-modal-confirmacao id="bloquear-cliente" titulo="Bloquear cliente?" :acao="route(...)" confirmar="Bloquear">
        12 pessoas perderão o acesso imediatamente.
    </x-modal-confirmacao>
--}}
@props([
    'id',
    'titulo',
    'acao',
    'metodo' => 'POST',
    'confirmar' => 'Confirmar',
    'variante' => 'perigo',
    'digitar' => null,
])

<dialog id="{{ $id }}" aria-labelledby="{{ $id }}-titulo" aria-describedby="{{ $id }}-impacto"
    class="cg-modal m-auto w-[min(32rem,calc(100%-2rem))] rounded-cartao border border-borda bg-superficie p-0 text-texto shadow-alta">
    <form method="POST" action="{{ $acao }}" class="flex flex-col gap-4 p-6" data-form-confirmacao>
        @csrf
        @unless (strtoupper($metodo) === 'POST')
            @method($metodo)
        @endunless

        <div class="flex items-start gap-3">
            <span @class([
                'flex size-10 shrink-0 items-center justify-center rounded-full',
                'bg-perigo-suave text-perigo' => $variante === 'perigo',
                'bg-primaria-suave text-primaria' => $variante !== 'perigo',
            ])>
                <x-icone :nome="$variante === 'perigo' ? 'atencao' : 'info'" class="size-5" />
            </span>
            <div class="flex flex-col gap-1">
                <h2 id="{{ $id }}-titulo" class="text-lg">{{ $titulo }}</h2>
                <div id="{{ $id }}-impacto" class="text-sm text-suave">{{ $slot }}</div>
            </div>
        </div>

        {{ $campos ?? '' }}

        @if ($digitar)
            <x-campo nome="confirmacao_texto" :id="$id.'-digitar'" obrigatorio autocomplete="off"
                :rotulo="'Para confirmar, digite: '.$digitar" data-exigir-texto="{{ $digitar }}" />
        @endif

        <div class="flex flex-wrap justify-end gap-2 pt-2">
            <x-botao variante="secundaria" data-fechar-modal>Cancelar</x-botao>
            <x-botao :variante="$variante === 'perigo' ? 'perigo' : 'primaria'" type="submit" data-botao-confirmar
                :disabled="(bool) $digitar">
                {{ $confirmar }}
            </x-botao>
        </div>
    </form>
</dialog>
