<x-layouts.app :titulo="'Editar '.$cliente->nome">
    <x-migalhas :itens="[
        ['rotulo' => 'Clientes', 'href' => route('plataforma.clientes.index')],
        ['rotulo' => $cliente->nome, 'href' => route('plataforma.clientes.show', $cliente)],
        ['rotulo' => 'Editar cadastro'],
    ]" />

    <x-cabecalho-pagina titulo="Editar cadastro do cliente" subtitulo="Dados da empresa contratante, usados no contrato, na cobrança e na nota fiscal." />

    @if ($errors->any())
        <x-alerta tipo="erro" titulo="Não foi possível salvar">Corrija os campos destacados.</x-alerta>
    @endif

    <x-cartao class="max-w-4xl">
        <form method="POST" action="{{ route('plataforma.clientes.atualizar', $cliente) }}" class="flex flex-col gap-6">
            @csrf
            @method('PUT')
            <x-campos-cliente :dados="$cliente" />
            <div class="flex justify-end gap-2">
                <x-botao :href="route('plataforma.clientes.show', $cliente)">Cancelar</x-botao>
                <x-botao variante="primaria" type="submit" icone="marcado">Salvar</x-botao>
            </div>
        </form>
    </x-cartao>
</x-layouts.app>
