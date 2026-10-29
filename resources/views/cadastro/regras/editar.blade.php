<x-layouts.app :titulo="'Regras de '.$perfil->nome">
    <x-migalhas :itens="[['rotulo' => 'Regras de acesso', 'href' => route('regras.index')], ['rotulo' => $empresa->razao_social.' · '.$perfil->nome]]" />
    <x-cabecalho-pagina :titulo="'Regras de '.$perfil->nome" :subtitulo="$empresa->razao_social" />

    <x-cartao class="max-w-4xl">
        <form method="POST" action="{{ route('regras.salvar', [$empresa, $perfil]) }}" class="flex flex-col gap-5">
            @csrf
            @method('PUT')

            <p class="text-sm text-suave">
                Cada linha libera o perfil no dia e no horário escolhidos. "Todas as catracas" vale para qualquer catraca da empresa.
                Horários de uma mesma catraca não podem se sobrepor. Um horário que termina às 00:00 vai até o fim do dia.
                Linhas em branco são ignoradas. Sem nenhuma regra, vale o horário padrão da empresa.
            </p>

            @error('perfil')<x-alerta tipo="atencao">{{ $message }}</x-alerta>@enderror
            @error('regras')<x-alerta tipo="atencao">{{ $message }}</x-alerta>@enderror

            <div class="flex flex-col gap-4">
                @foreach ($linhas as $i => $linha)
                    <div class="grid gap-3 border-b border-borda pb-4 sm:grid-cols-4">
                        <x-selecao :nome="'linhas['.$i.'][dia]'" rotulo="Dia" :opcoes="\App\Support\IntervaloHorario::DIAS"
                            vazio="Escolha o dia" sem-opcional :valor="$linha['dia']" />
                        <x-campo :nome="'linhas['.$i.'][inicio]'" tipo="time" rotulo="Início" sem-opcional :valor="$linha['inicio']" />
                        <x-campo :nome="'linhas['.$i.'][fim]'" tipo="time" rotulo="Fim" sem-opcional :valor="$linha['fim']" />
                        <x-selecao :nome="'linhas['.$i.'][catraca]'" rotulo="Catraca" :opcoes="$opcoesCatracas"
                            vazio="Todas as catracas" sem-opcional :valor="$linha['catraca']" />
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <x-botao :href="route('regras.index')">Cancelar</x-botao>
                <x-botao variante="primaria" type="submit" icone="marcado">Salvar regras</x-botao>
            </div>
        </form>
    </x-cartao>
</x-layouts.app>
