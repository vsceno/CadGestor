@php
    $menor = $faixa === 'menor';
    $semNascimento = $faixa === 'desconhecida';
@endphp

<x-layouts.app :titulo="'Consentimento de '.$pessoa->nome">
    <x-migalhas :itens="[['rotulo' => 'Pessoas', 'href' => route('pessoas.index')], ['rotulo' => $pessoa->nome, 'href' => route('pessoas.show', $pessoa)], ['rotulo' => 'Consentimento']]" />
    <x-cabecalho-pagina :titulo="'Consentimento de '.$pessoa->nome"
        subtitulo="Leia o termo com a pessoa antes de registrar o aceite." />

    @if ($termo === null)
        <x-alerta tipo="erro">
            Não há termo publicado. Peça ao administrador do CadGestor para publicar o termo antes de colher consentimento.
        </x-alerta>
    @elseif ($semNascimento)
        <x-alerta tipo="atencao">
            Informe a data de nascimento antes de colher o consentimento: ela define se é preciso o responsável legal.
            <a href="{{ route('pessoas.editar', $pessoa) }}" class="font-semibold underline">Editar a pessoa</a>
        </x-alerta>
    @else
        <x-cartao class="max-w-3xl">
            <form method="POST" action="{{ route('pessoas.consentimento.salvar', $pessoa) }}" class="flex flex-col gap-6">
                @csrf

                <details class="rounded-controle border border-borda p-4" open>
                    <summary class="cursor-pointer font-semibold">Termo de consentimento (versão {{ $termo->versao }})</summary>
                    <div class="mt-3 whitespace-pre-line text-sm">{{ $termo->texto }}</div>
                    <p class="mt-3 text-xs text-suave">Hash SHA-256 do texto: <span class="numeros break-all">{{ $termo->hash_texto }}</span></p>
                </details>

                <x-selecao nome="canal" rotulo="Como foi colhido" obrigatorio sem-opcional :vazio="null" :valor="'presencial'"
                    :opcoes="['presencial' => 'Presencial, no cadastro ou na portaria', 'digital' => 'Digital, assinado pela própria pessoa']" />

                @if ($menor)
                    <x-alerta tipo="info">
                        A pessoa tem menos de 18 anos. O aceite é do responsável legal, com os dados dele abaixo.
                    </x-alerta>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-campo nome="responsavel_nome" rotulo="Nome completo do responsável" obrigatorio maxlength="150" />
                        <x-campo nome="responsavel_cpf" rotulo="CPF do responsável" mascara="cpf" obrigatorio />
                        <x-selecao nome="responsavel_parentesco" rotulo="Vínculo com a pessoa" obrigatorio sem-opcional
                            :opcoes="$parentescos" vazio="Selecione" />
                    </div>

                    <label class="flex items-start gap-3 text-sm">
                        <input type="hidden" name="aceite_responsavel" value="0">
                        <input type="checkbox" name="aceite_responsavel" value="1" class="mt-0.5 size-4 accent-primaria">
                        <span>O responsável legal leu o termo e aceita o tratamento dos dados biométricos da pessoa.</span>
                    </label>
                @else
                    <label class="flex items-start gap-3 text-sm">
                        <input type="hidden" name="aceite_titular" value="0">
                        <input type="checkbox" name="aceite_titular" value="1" class="mt-0.5 size-4 accent-primaria">
                        <span>A pessoa leu o termo e aceita o tratamento dos dados biométricos para o controle de acesso.</span>
                    </label>
                @endif

                <div class="flex justify-end gap-2">
                    <x-botao :href="route('pessoas.show', $pessoa)">Cancelar</x-botao>
                    <x-botao variante="primaria" type="submit" icone="marcado">Registrar consentimento</x-botao>
                </div>
            </form>
        </x-cartao>
    @endif
</x-layouts.app>
