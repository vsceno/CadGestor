<x-layouts.app :titulo="'Vínculo de '.$pessoa->nome">
    <x-migalhas :itens="[['rotulo' => 'Pessoas', 'href' => route('pessoas.index')], ['rotulo' => $pessoa->nome, 'href' => route('pessoas.show', $pessoa)], ['rotulo' => 'Vínculo']]" />
    <x-cabecalho-pagina :titulo="$vinculo->perfil->nome.' em '.$vinculo->empresa->razao_social"
        :subtitulo="$vinculo->status === 'encerrado' ? 'Vínculo encerrado. O registro fica no histórico.' : 'Altere a validade ou encerre o vínculo.'" />

    @if ($vinculo->status === 'encerrado')
        <x-alerta tipo="info">
            Encerrado em {{ \App\Support\Formato::data($vinculo->encerrado_em) }}. Motivo: {{ $vinculo->motivo_encerramento }}.
            A validade ficou em {{ \App\Support\Formato::data($vinculo->data_fim) }}.
        </x-alerta>
    @else
        <x-cartao class="max-w-2xl">
            <form method="POST" action="{{ route('pessoas.vinculos.atualizar', [$pessoa, $vinculo]) }}" class="flex flex-col gap-4">
                @csrf
                @method('PUT')
                <h2 class="text-lg">Validade</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-campo nome="data_inicio" tipo="date" rotulo="Início" obrigatorio :valor="$vinculo->data_inicio->format('Y-m-d')" />
                    <x-campo nome="data_fim" tipo="date" rotulo="Validade (fim)" :valor="$vinculo->data_fim?->format('Y-m-d')"
                        ajuda="Deixe em branco para vínculo sem prazo." />
                </div>
                <div class="flex justify-end gap-2">
                    <x-botao :href="route('pessoas.show', $pessoa)">Voltar</x-botao>
                    <x-botao variante="primaria" type="submit" icone="marcado">Salvar validade</x-botao>
                </div>
            </form>
        </x-cartao>

        <x-cartao class="max-w-2xl">
            <form method="POST" action="{{ route('pessoas.vinculos.encerrar', [$pessoa, $vinculo]) }}" class="flex flex-col gap-4">
                @csrf
                <div>
                    <h2 class="text-lg">Encerrar vínculo</h2>
                    <p class="text-sm text-suave">
                        O vínculo deixa de valer e fica no histórico, com a data e o motivo. Não pode ser desfeito:
                        para voltar a vincular a pessoa, crie um novo vínculo.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-campo nome="data_encerramento" tipo="date" rotulo="Data do encerramento" :valor="null" ajuda="Em branco = hoje." />
                    <x-campo nome="motivo" rotulo="Motivo" obrigatorio maxlength="255" ajuda="Ex.: Fim do contrato, desligamento." />
                </div>
                <div class="flex justify-end">
                    <x-botao variante="perigo" type="submit" icone="inativo">Encerrar vínculo</x-botao>
                </div>
            </form>
        </x-cartao>
    @endif
</x-layouts.app>
