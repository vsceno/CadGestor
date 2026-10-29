<x-layouts.app :titulo="$empresa->razao_social">
    <x-migalhas :itens="[['rotulo' => 'Empresas', 'href' => route('empresas.index')], ['rotulo' => $empresa->razao_social]]" />
    <x-cabecalho-pagina :titulo="$empresa->razao_social"
        :subtitulo="$empresa->ativo
            ? 'Empresa ativa. Escolha os perfis que ela aceita e o horário padrão de funcionamento.'
            : 'Empresa desativada. Ela não aparece nas novas escolhas, mas o histórico foi mantido.'">
        <x-slot:acoes>
            <x-botao icone="editar" :href="route('empresas.editar', $empresa)">Editar dados</x-botao>
            @if ($empresa->ativo)
                <x-botao variante="perigo" icone="inativo" data-abrir-modal="desativar-empresa">Desativar</x-botao>
            @else
                <form method="POST" action="{{ route('empresas.situacao', $empresa) }}">
                    @csrf
                    <input type="hidden" name="ativo" value="1">
                    <x-botao variante="primaria" type="submit" icone="marcado">Reativar</x-botao>
                </form>
            @endif
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-cartao>
            <form method="POST" action="{{ route('empresas.perfis.salvar', $empresa) }}" class="flex flex-col gap-4">
                @csrf
                @method('PUT')
                <div>
                    <h2 class="text-lg">Perfis aceitos</h2>
                    <p class="text-sm text-suave">Só pessoas com um destes perfis podem ser vinculadas a esta empresa.</p>
                </div>

                @if ($perfis->isEmpty())
                    <x-estado-vazio titulo="Nenhum perfil ativo" descricao="Ative um perfil de acesso antes de escolher os perfis desta empresa.">
                        <x-slot:acao>
                            <x-botao :href="route('perfis.index')">Ver perfis de acesso</x-botao>
                        </x-slot:acao>
                    </x-estado-vazio>
                @else
                    <fieldset class="flex flex-col gap-2">
                        <legend class="sr-only">Perfis aceitos por esta empresa</legend>
                        @foreach ($perfis as $perfil)
                            <label class="flex items-start gap-3 rounded-controle border border-borda p-3 text-sm hover:bg-superficie-2">
                                <input type="checkbox" name="perfis[]" value="{{ $perfil->id }}" class="mt-0.5 size-4 accent-primaria" @checked(in_array($perfil->id, $aceitos, true))>
                                <span>
                                    <span class="font-medium text-texto">{{ $perfil->nome }}</span>
                                    @if ($perfil->descricao)<span class="block text-xs text-suave">{{ $perfil->descricao }}</span>@endif
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                    <div class="flex justify-end">
                        <x-botao variante="primaria" type="submit" icone="marcado">Salvar perfis</x-botao>
                    </div>
                @endif
            </form>
        </x-cartao>

        <x-cartao>
            <form method="POST" action="{{ route('empresas.horarios.salvar', $empresa) }}" class="flex flex-col gap-4">
                @csrf
                @method('PUT')
                <div>
                    <h2 class="text-lg">Horário padrão</h2>
                    <p class="text-sm text-suave">Vale quando o perfil não tem regra própria nesta empresa. Um horário que termina às 00:00 vai até o fim do dia.</p>
                </div>

                @error('horarios')<x-alerta tipo="atencao">{{ $message }}</x-alerta>@enderror

                <div class="flex flex-col gap-4">
                    @foreach ($linhas as $i => $linha)
                        <div class="grid gap-3 sm:grid-cols-3">
                            <x-selecao :id="'horario-dia-'.$i" nome="dias[]" rotulo="Dia" :opcoes="\App\Support\IntervaloHorario::DIAS"
                                vazio="Escolha o dia" sem-opcional :valor="old('dias.'.$i, $linha['dia'])" />
                            <x-campo :id="'horario-inicio-'.$i" nome="inicios[]" tipo="time" rotulo="Início" sem-opcional
                                :valor="old('inicios.'.$i, $linha['inicio'])" />
                            <x-campo :id="'horario-fim-'.$i" nome="fins[]" tipo="time" rotulo="Fim" sem-opcional
                                :valor="old('fins.'.$i, $linha['fim'])" />
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end">
                    <x-botao variante="primaria" type="submit" icone="marcado">Salvar horário padrão</x-botao>
                </div>
            </form>
        </x-cartao>
    </div>

    @if ($empresa->ativo)
        <x-modal-confirmacao id="desativar-empresa" titulo="Desativar esta empresa?" :acao="route('empresas.situacao', $empresa)" confirmar="Desativar">
            <x-slot:campos>
                <input type="hidden" name="ativo" value="0">
            </x-slot:campos>
            A empresa sai das novas escolhas. O histórico é mantido e ela pode ser reativada depois.
        </x-modal-confirmacao>
    @endif
</x-layouts.app>
