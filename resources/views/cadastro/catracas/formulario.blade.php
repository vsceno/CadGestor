@php $novo = ! $catraca->exists; @endphp

<x-layouts.app :titulo="$novo ? 'Nova catraca' : 'Editar catraca'">
    <x-migalhas :itens="[['rotulo' => 'Catracas', 'href' => route('catracas.index')], ['rotulo' => $novo ? 'Nova catraca' : $catraca->nome]]" />
    <x-cabecalho-pagina :titulo="$novo ? 'Nova catraca' : 'Editar catraca'"
        subtitulo="O token de conexão é gerado ao cadastrar e aparece uma única vez." />

    @if ($novo && $empresas->isEmpty())
        <x-alerta tipo="atencao">
            Cadastre e deixe ativa uma empresa antes de criar a catraca.
            <a href="{{ route('empresas.criar') }}" class="font-semibold underline">Cadastrar empresa</a>
        </x-alerta>
    @endif

    <x-cartao class="max-w-3xl">
        <form method="POST" action="{{ $novo ? route('catracas.salvar') : route('catracas.atualizar', $catraca) }}" class="flex flex-col gap-4">
            @csrf
            @unless ($novo) @method('PUT') @endunless

            @error('plano')<x-alerta tipo="atencao">{{ $message }}</x-alerta>@enderror

            @if ($novo)
                <x-selecao nome="empresa_id" rotulo="Empresa" obrigatorio :opcoes="$empresas->pluck('razao_social', 'id')->all()"
                    vazio="Selecione a empresa" :valor="$catraca->empresa_id" />
            @else
                <div class="flex flex-col gap-1.5">
                    <span class="text-sm font-medium text-texto">Empresa</span>
                    <p class="text-sm text-texto">{{ $catraca->empresa->razao_social }}</p>
                    <p class="text-xs text-suave">A empresa de uma catraca não muda depois do cadastro.</p>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-campo nome="nome" rotulo="Nome" obrigatorio maxlength="100" :valor="$catraca->nome"
                    ajuda="Ex.: Portaria principal, Refeitório." />
                <x-campo nome="localizacao" rotulo="Localização" maxlength="150" :valor="$catraca->localizacao"
                    ajuda="Onde o equipamento está instalado." />
                <x-campo nome="identificador_dispositivo" rotulo="Identificador do equipamento" obrigatorio maxlength="100"
                    :valor="$catraca->identificador_dispositivo" ajuda="Letras, números, ponto, hífen ou dois-pontos (3 a 100 caracteres)." />
                <x-campo nome="fabricante" rotulo="Fabricante" maxlength="100" :valor="$catraca->fabricante" />
                <x-campo nome="modelo" rotulo="Modelo" maxlength="100" :valor="$catraca->modelo" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-selecao nome="tipo_identificacao" rotulo="Tipo de identificação" obrigatorio :opcoes="\App\Models\Catraca::TIPOS_IDENTIFICACAO"
                    vazio="Selecione" :valor="$catraca->tipo_identificacao" />
                <x-selecao nome="modo_falha" rotulo="Se o servidor não responder" obrigatorio :opcoes="\App\Models\Catraca::MODOS_FALHA"
                    vazio="Selecione" :valor="$catraca->modo_falha" ajuda="Liberar deixa passar sem conferência. Use só onde a segurança física permitir." />
            </div>

            <div class="flex justify-end gap-2">
                <x-botao :href="$novo ? route('catracas.index') : route('catracas.show', $catraca)">Cancelar</x-botao>
                <x-botao variante="primaria" type="submit" icone="marcado">Salvar</x-botao>
            </div>
        </form>
    </x-cartao>
</x-layouts.app>
