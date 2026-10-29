<x-layouts.publico titulo="Confirme seu e-mail" largura="max-w-md">
    <x-cartao class="mt-8 shadow-alta">
        <div class="flex flex-col gap-4">
            <h1 class="text-2xl">Confirme seu e-mail</h1>

            <x-alerta tipo="sucesso" :fechavel="false">
                Cadastro recebido. Enviamos um link de confirmação para o e-mail informado.
            </x-alerta>

            <p class="text-sm text-suave">
                O link vale por 72 horas. Seu teste grátis de {{ $diasTeste }} dias começa agora: a primeira
                cobrança só vence no fim dele.
            </p>
            <p class="text-sm text-suave">
                Não chegou? Confira a caixa de spam. Depois de confirmar, entre com o e-mail e a senha que você criou.
            </p>

            <x-botao variante="primaria" :href="route('entrar')">Ir para o login</x-botao>
        </div>
    </x-cartao>
</x-layouts.publico>
