<x-layouts.publico titulo="{{ $confirmado ? 'E-mail confirmado' : 'Link inválido' }}">
    <x-cartao class="shadow-alta">
        <div class="flex flex-col gap-5">
            @if ($confirmado)
                <h1 class="text-2xl">E-mail confirmado</h1>
                <x-alerta tipo="sucesso" :fechavel="false">Tudo certo. Agora você já pode entrar no CadGestor.</x-alerta>
                <x-botao variante="primaria" href="{{ route('entrar') }}">Entrar</x-botao>
            @else
                <h1 class="text-2xl">Link inválido</h1>
                <x-alerta tipo="erro">Este link expirou ou já foi usado. Entre com seu e-mail e senha para pedir um novo.</x-alerta>
                <x-botao href="{{ route('entrar') }}">Ir para o login</x-botao>
            @endif
        </div>
    </x-cartao>
</x-layouts.publico>
