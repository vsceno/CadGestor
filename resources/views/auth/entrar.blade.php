<x-layouts.publico titulo="Entrar">
    <x-cartao class="shadow-alta">
        <div class="flex flex-col gap-5">
            <div class="flex flex-col gap-1">
                <h1 class="text-2xl">Entrar no CadGestor</h1>
                <p class="text-sm text-suave">Controle de acesso com LGPD.</p>
            </div>

            @if (session('erro_login'))
                <x-alerta tipo="erro">
                    {{ session('erro_login') }}
                    @if (session('pode_reenviar'))
                        <form method="POST" action="{{ route('email.reenviar') }}" class="mt-2">
                            @csrf
                            <button type="submit" class="font-semibold text-primaria underline">Reenviar o link de confirmação</button>
                        </form>
                    @endif
                </x-alerta>
            @endif

            <form method="POST" action="{{ route('entrar') }}" class="flex flex-col gap-4">
                @csrf
                {{-- Depois de um erro, o cursor já vai para a senha (o e-mail continua preenchido). --}}
                <x-campo nome="email" tipo="email" rotulo="E-mail" obrigatorio :autofocus="! old('email')" autocomplete="username" maxlength="254" />
                <div class="flex flex-col gap-1.5">
                    <x-campo nome="senha" tipo="password" rotulo="Senha" obrigatorio :autofocus="(bool) old('email')" autocomplete="current-password" maxlength="128" />
                    <a href="{{ route('senha.esqueci') }}" class="self-end text-sm">Esqueci minha senha</a>
                </div>
                <x-botao variante="primaria" type="submit" class="w-full">Entrar</x-botao>
            </form>
        </div>
    </x-cartao>
</x-layouts.publico>
