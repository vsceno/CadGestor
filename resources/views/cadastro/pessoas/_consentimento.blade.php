{{-- Consentimento e biometria da pessoa (LGPD). A biometria só existe com consentimento ativo. --}}
<x-cartao :preenchimento="false">
    <div class="flex flex-col gap-4 p-5">
        <div>
            <h2 class="text-lg">Consentimento e biometria</h2>
            <p class="text-sm text-suave">A biometria só é guardada com o consentimento ativo da pessoa. Ela pode ser revogada a qualquer momento.</p>
        </div>

        @if ($consentimento)
            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-suave">Situação</dt>
                    <dd class="mt-1"><x-status tipo="ativo">Consentimento ativo</x-status></dd>
                </div>
                <div>
                    <dt class="text-suave">Aceito em</dt>
                    <dd class="mt-1">{{ \App\Support\Formato::dataHora($consentimento->aceito_em) }}</dd>
                </div>
                <div>
                    <dt class="text-suave">Termo e canal</dt>
                    <dd class="mt-1">
                        Versão {{ $consentimento->versao_termo }} · {{ $consentimento->canal === 'digital' ? 'Digital' : 'Presencial' }}
                        @if ($consentimento->titular_menor)
                            · aceito pelo responsável legal
                        @endif
                    </dd>
                </div>
            </dl>

            <div>
                <h3 class="text-sm font-semibold">Biometria cadastrada</h3>
                @forelse ($biometrias as $biometria)
                    <p class="text-sm">
                        {{ $biometria->tipo === 'facial' ? 'Reconhecimento facial' : 'Digital, dedo '.$biometria->dedo }}
                        · cadastrada em {{ \App\Support\Formato::data($biometria->created_at) }}
                    </p>
                @empty
                    <p class="text-sm text-suave">Nenhuma digital ou rosto cadastrado.</p>
                @endforelse
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($biometrias->isNotEmpty())
                    <x-botao variante="secundaria" icone="excluir" data-abrir-modal="excluir-biometria">Apagar biometria a pedido do titular</x-botao>
                @endif
                <x-botao variante="perigo" icone="desfazer" data-abrir-modal="revogar-consentimento">Revogar consentimento</x-botao>
            </div>

            <x-modal-confirmacao id="excluir-biometria" titulo="Apagar a biometria?" :acao="route('pessoas.biometria.excluir', $pessoa)" confirmar="Apagar">
                As digitais e o rosto cadastrados são apagados. O consentimento continua ativo.
            </x-modal-confirmacao>

            <x-modal-confirmacao id="revogar-consentimento" titulo="Revogar o consentimento?" :acao="route('pessoas.consentimento.revogar', $pessoa)" confirmar="Revogar">
                As biometrias desta pessoa são apagadas e a remoção segue para as catracas. Para voltar a usar a biometria,
                será preciso um novo consentimento. Esta ação não pode ser desfeita.
            </x-modal-confirmacao>
        @else
            <x-alerta tipo="atencao">Sem consentimento ativo: a biometria não pode ser cadastrada para esta pessoa.</x-alerta>
            <div>
                <x-botao variante="primaria" icone="marcado" :href="route('pessoas.consentimento.formulario', $pessoa)">Registrar consentimento</x-botao>
            </div>
        @endif
    </div>
</x-cartao>
