@php use App\Support\Formato; @endphp

<x-layouts.app titulo="Dados da empresa">
    <x-migalhas :itens="[['rotulo' => 'Minha assinatura', 'href' => route('assinatura.index')], ['rotulo' => 'Dados da empresa']]" />
    <x-cabecalho-pagina titulo="Dados da empresa" subtitulo="Usados na cobrança e na nota fiscal do CadGestor." />

    @if ($errors->any())
        <x-alerta tipo="erro" titulo="Não foi possível salvar">Corrija os campos destacados.</x-alerta>
    @endif

    <x-cartao class="max-w-4xl">
        <div class="flex flex-col gap-6">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[12rem_1fr]">
                <dt class="text-suave">Razão social</dt><dd class="font-medium">{{ $cliente->nome }}</dd>
                <dt class="text-suave">CNPJ</dt><dd class="numeros">{{ $cliente->cnpj ? Formato::cnpj($cliente->cnpj) : '—' }}</dd>
            </dl>
            <p class="text-sm text-suave">Para mudar a razão social ou o CNPJ (troca de titularidade do contrato), fale com o comercial do CadGestor.</p>

            <form method="POST" action="{{ route('assinatura.empresa') }}" class="flex flex-col gap-6">
                @csrf
                @method('PUT')
                <x-campos-cliente :dados="$cliente" :identificacao="false" />
                <div class="flex justify-end gap-2">
                    <x-botao :href="route('assinatura.index')">Cancelar</x-botao>
                    <x-botao variante="primaria" type="submit" icone="marcado">Salvar</x-botao>
                </div>
            </form>
        </div>
    </x-cartao>
</x-layouts.app>
