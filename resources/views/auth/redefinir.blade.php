<x-layouts.publico titulo="{{ $token ? 'Criar nova senha' : 'Link inválido' }}">
    <x-cartao class="shadow-alta">
        <div class="flex flex-col gap-5">
            @if ($token === null)
                <h1 class="text-2xl">Link inválido</h1>
                <x-alerta tipo="erro">Este link expirou ou já foi usado. Cada link vale uma única vez, por tempo limitado.</x-alerta>
                <x-botao variante="primaria" href="{{ route('senha.esqueci') }}">Pedir um novo link</x-botao>
            @else
                <div class="flex flex-col gap-1">
                    <h1 class="text-2xl">Criar nova senha</h1>
                    <p class="text-sm text-suave">Ao salvar, você sai de todos os aparelhos em que estava conectado.</p>
                </div>

                <form method="POST" action="{{ route('senha.redefinir') }}" class="flex flex-col gap-4" autocomplete="off">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <x-campo nome="senha" tipo="password" rotulo="Nova senha" obrigatorio autofocus autocomplete="new-password" maxlength="128"
                        ajuda="Mínimo de 12 caracteres. Uma frase longa é mais fácil de lembrar e mais segura." />
                    <x-campo nome="senha_confirmation" tipo="password" rotulo="Confirme a nova senha" obrigatorio autocomplete="new-password" maxlength="128" />
                    <x-botao variante="primaria" type="submit" class="w-full">Salvar nova senha</x-botao>
                </form>
            @endif
        </div>
    </x-cartao>
</x-layouts.publico>
