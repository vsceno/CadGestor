<x-layouts.app titulo="Trocar senha">
    <x-cabecalho-pagina titulo="Trocar senha" subtitulo="Ao salvar, as sessões abertas em outros aparelhos são encerradas." />

    <x-cartao class="max-w-xl">
        <form method="POST" action="{{ route('senha.trocar') }}" class="flex flex-col gap-4" autocomplete="off">
            @csrf
            <x-campo nome="senha_atual" tipo="password" rotulo="Senha atual" obrigatorio autofocus autocomplete="current-password" maxlength="128" />
            <x-campo nome="senha" tipo="password" rotulo="Nova senha" obrigatorio autocomplete="new-password" maxlength="128"
                ajuda="Mínimo de 12 caracteres. Não use o seu e-mail." />
            <x-campo nome="senha_confirmation" tipo="password" rotulo="Confirme a nova senha" obrigatorio autocomplete="new-password" maxlength="128" />
            <div class="flex justify-end gap-2">
                <x-botao href="{{ route('inicio') }}">Cancelar</x-botao>
                <x-botao variante="primaria" type="submit">Salvar nova senha</x-botao>
            </div>
        </form>
    </x-cartao>
</x-layouts.app>
