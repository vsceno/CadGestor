<x-layouts.app titulo="LGPD">
    <x-cabecalho-pagina titulo="LGPD e consentimento"
        subtitulo="Termo do controle de acesso com biometria e os números de aceites e de biometrias do cliente." />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-indicador rotulo="Pessoas ativas" :valor="\App\Support\Formato::numero($indicadores['pessoas_ativas'])" icone="pessoas" />
        <x-indicador rotulo="Com consentimento ativo" :valor="\App\Support\Formato::numero($indicadores['com_consentimento'])" icone="sucesso" />
        <x-indicador rotulo="Sem consentimento" :valor="\App\Support\Formato::numero($indicadores['sem_consentimento'])" icone="lgpd" />
        <x-indicador rotulo="Biometrias ativas" :valor="\App\Support\Formato::numero($indicadores['biometrias_ativas'])" icone="monitor" />
    </div>

    <x-cartao class="max-w-3xl">
        <div class="flex flex-col gap-5">
            <div class="flex flex-col gap-3">
                <h2 class="text-lg">Termo vigente</h2>

                @if ($termo)
                    <p class="text-sm text-suave">
                        Versão {{ $termo->versao }}, vigente desde {{ \App\Support\Formato::dataHora($termo->vigente_desde) }}.
                    </p>
                    <p class="text-xs text-suave">Hash SHA-256: <span class="numeros break-all">{{ $termo->hash_texto }}</span></p>

                    <details class="rounded-controle border border-borda p-4">
                        <summary class="cursor-pointer text-sm font-medium">Ler o texto do termo</summary>
                        <div class="mt-3 whitespace-pre-line text-sm">{{ $termo->texto }}</div>
                    </details>
                @else
                    <x-alerta tipo="erro">Não há termo publicado. Sem termo, ninguém pode dar consentimento.</x-alerta>
                @endif
            </div>

            <form method="POST" action="{{ route('lgpd.termos.publicar') }}" class="flex flex-col gap-4 border-t border-borda pt-5">
                @csrf
                <div>
                    <h3 class="text-base font-semibold">Publicar nova versão</h3>
                    <p class="text-sm text-suave">
                        Termo publicado não muda. Para alterar o texto, publique uma nova versão: quem já aceitou a anterior continua com ela.
                    </p>
                </div>
                <x-campo nome="versao" rotulo="Versão" obrigatorio maxlength="20" :valor="null"
                    ajuda="Ex.: 1.1. Não pode repetir uma versão já publicada." />
                <x-campo nome="texto" tipo="textarea" rotulo="Texto do termo" obrigatorio :valor="null"
                    ajuda="Mínimo de 200 caracteres. Use o texto revisado pelo jurídico do cliente." />
                <div class="flex justify-end">
                    <x-botao variante="primaria" type="submit" icone="marcado">Publicar versão</x-botao>
                </div>
            </form>
        </div>
    </x-cartao>
</x-layouts.app>
