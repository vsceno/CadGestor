<x-layouts.publico titulo="Esqueci minha senha">
    <x-cartao class="shadow-alta">
        <div class="flex flex-col gap-5">
            <h1 class="text-2xl">Esqueci minha senha</h1>

            @if (! empty($enviado))
                <x-alerta tipo="sucesso" :fechavel="false">
                    Se este e-mail tiver uma conta ativa, enviamos um link para criar uma nova senha. Ele vale por 1 hora.
                </x-alerta>
                <p class="text-sm text-suave">Não chegou? Confira a caixa de spam. Por segurança, a mensagem é a mesma exista ou não a conta.</p>
                <x-botao href="{{ route('entrar') }}">Voltar para o login</x-botao>
            @else
                <p class="text-sm text-suave">Informe o e-mail que você usa para entrar. Enviaremos um link para criar uma nova senha.</p>

                @if (! empty($limite))
                    <x-alerta tipo="erro">Muitos pedidos em pouco tempo. Aguarde uma hora e tente de novo.</x-alerta>
                @endif

                <form method="POST" action="{{ route('senha.esqueci') }}" class="flex flex-col gap-4">
                    @csrf
                    <x-campo nome="email" tipo="email" rotulo="E-mail" obrigatorio autofocus autocomplete="username" maxlength="254" />
                    <x-botao variante="primaria" type="submit" class="w-full">Enviar link</x-botao>
                </form>
                <a href="{{ route('entrar') }}" class="text-center text-sm">Lembrei a senha</a>
            @endif
        </div>
    </x-cartao>
</x-layouts.publico>
