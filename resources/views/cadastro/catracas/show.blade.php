<x-layouts.app :titulo="$catraca->nome">
    <x-migalhas :itens="[['rotulo' => 'Catracas', 'href' => route('catracas.index')], ['rotulo' => $catraca->nome]]" />
    <x-cabecalho-pagina :titulo="$catraca->nome" :subtitulo="$catraca->empresa->razao_social">
        <x-slot:acoes>
            <x-botao icone="editar" :href="route('catracas.editar', $catraca)">Editar</x-botao>
            @if ($catraca->ativo)
                <x-botao variante="perigo" icone="inativo" data-abrir-modal="desativar-catraca">Desativar</x-botao>
            @else
                <form method="POST" action="{{ route('catracas.situacao', $catraca) }}">
                    @csrf
                    <input type="hidden" name="ativo" value="1">
                    <x-botao variante="primaria" type="submit" icone="marcado">Reativar</x-botao>
                </form>
            @endif
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <div class="flex max-w-3xl flex-col gap-6">
        @if (session('token_catraca'))
            <x-cartao>
                <div class="flex flex-col gap-3">
                    <h2 class="text-lg">Token de conexão</h2>
                    <x-alerta tipo="atencao">
                        Copie o token agora. Ele não aparece de novo. Quem tiver o token pode conectar um equipamento ao CadGestor,
                        por isso não envie por e-mail nem por mensagem.
                    </x-alerta>
                    <code class="numeros block break-all rounded-controle border border-borda bg-superficie-2 p-3 text-sm select-all">{{ session('token_catraca') }}</code>
                </div>
            </x-cartao>
        @endif

        <x-cartao>
            <dl class="grid gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-suave">Situação</dt>
                    <dd class="mt-1">
                        @if ($catraca->ativo)
                            <x-status tipo="ativo">Ativa</x-status>
                        @else
                            <x-status tipo="inativo">Desativada</x-status>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-suave">Identificador do equipamento</dt>
                    <dd class="numeros mt-1 break-all">{{ $catraca->identificador_dispositivo }}</dd>
                </div>
                <div>
                    <dt class="text-suave">Localização</dt>
                    <dd class="mt-1">{{ $catraca->localizacao ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-suave">Tipo de identificação</dt>
                    <dd class="mt-1">{{ \App\Models\Catraca::TIPOS_IDENTIFICACAO[$catraca->tipo_identificacao] ?? $catraca->tipo_identificacao }}</dd>
                </div>
                <div>
                    <dt class="text-suave">Se o servidor não responder</dt>
                    <dd class="mt-1">{{ \App\Models\Catraca::MODOS_FALHA[$catraca->modo_falha] ?? $catraca->modo_falha }}</dd>
                </div>
                <div>
                    <dt class="text-suave">Fabricante e modelo</dt>
                    <dd class="mt-1">{{ trim(($catraca->fabricante ?? '').' '.($catraca->modelo ?? '')) ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-suave">Último contato</dt>
                    <dd class="mt-1">{{ $catraca->ultimo_contato_em ? \App\Support\Formato::dataHora($catraca->ultimo_contato_em) : 'Ainda não conectou' }}</dd>
                </div>
            </dl>
        </x-cartao>

        <x-cartao>
            <div class="flex flex-col gap-3">
                <h2 class="text-lg">Conexão do equipamento</h2>
                <p class="text-sm text-suave">
                    Se o token vazou ou o equipamento foi trocado, gere um novo. O token atual deixa de funcionar na hora
                    e o equipamento precisa ser configurado de novo.
                </p>
                <div>
                    <x-botao variante="perigo" icone="desfazer" data-abrir-modal="renovar-token">Gerar novo token</x-botao>
                </div>
            </div>
        </x-cartao>
    </div>

    <x-modal-confirmacao id="renovar-token" titulo="Gerar novo token?" :acao="route('catracas.token', $catraca)" confirmar="Gerar novo token">
        O token atual deixa de funcionar imediatamente. Esta ação não pode ser desfeita.
    </x-modal-confirmacao>

    @if ($catraca->ativo)
        <x-modal-confirmacao id="desativar-catraca" titulo="Desativar esta catraca?" :acao="route('catracas.situacao', $catraca)" confirmar="Desativar">
            <x-slot:campos>
                <input type="hidden" name="ativo" value="0">
            </x-slot:campos>
            A catraca sai da contagem do plano. Ela pode ser reativada depois, desde que o plano tenha vaga.
        </x-modal-confirmacao>
    @endif
</x-layouts.app>
