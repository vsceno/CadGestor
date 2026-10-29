@php
    use App\Support\Formato;
@endphp

<x-layouts.publico titulo="Contratar o CadGestor" largura="max-w-3xl">
    <header class="flex flex-col gap-2 pt-2">
        <h1 class="text-3xl">Contrate o CadGestor</h1>
        <p class="text-suave">
            Controle de acesso com LGPD. <strong class="text-texto">{{ $diasTeste }} dias grátis</strong>,
            sem cartão: a primeira cobrança só vence no fim do teste.
        </p>
    </header>

    @if (session('erro'))
        <x-alerta tipo="erro">{{ session('erro') }}</x-alerta>
    @endif

    <form method="POST" action="{{ route('contratar') }}" class="flex flex-col gap-6">
        @csrf

        <x-cartao titulo="Plano" descricao="Você pode trocar de plano a qualquer momento, em Minha assinatura.">
            <fieldset>
                <legend class="sr-only">Plano</legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ($planos as $plano)
                        <label class="flex cursor-pointer flex-col gap-1.5 rounded-cartao border border-borda-forte bg-superficie p-4 has-checked:border-primaria has-checked:bg-primaria-suave">
                            <span class="flex items-center gap-2">
                                <input type="radio" name="plano" value="{{ $plano->codigo }}" class="size-4 accent-primaria" required
                                    @checked(old('plano', $planoEscolhido) === $plano->codigo)>
                                <span class="font-semibold">{{ $plano->nome }}</span>
                            </span>
                            <span class="numeros text-xl font-bold text-primaria">{{ Formato::moeda($plano->preco_centavos) }}<span class="text-sm font-medium">/mês</span></span>
                            <span class="text-sm text-suave">até {{ Formato::numero($plano->limite_pessoas) }} pessoas · {{ $plano->limite_catracas }} catracas</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            @error('plano')<p class="mt-3 text-sm font-medium text-perigo">{{ $message }}</p>@enderror
        </x-cartao>

        <x-cartao titulo="Sua empresa" descricao="Endereço e dados fiscais você completa depois, em Minha assinatura.">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-campo class="sm:col-span-2" nome="razao_social" rotulo="Razão social" obrigatorio maxlength="150" autocomplete="organization" />
                <x-campo nome="cnpj" rotulo="CNPJ" mascara="cnpj" obrigatorio />
                <x-campo nome="telefone" rotulo="Telefone da empresa" mascara="telefone" obrigatorio ajuda="Com DDD." />
            </div>
        </x-cartao>

        <x-cartao titulo="Administrador da conta" descricao="Quem vai entrar no CadGestor. Este e-mail será o seu login.">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-campo nome="responsavel_nome" rotulo="Nome completo" obrigatorio maxlength="150" autocomplete="name" />
                <x-campo nome="email" tipo="email" rotulo="E-mail (será o seu login)" obrigatorio maxlength="254" />
                <x-campo nome="senha" tipo="password" rotulo="Senha" obrigatorio maxlength="128" autocomplete="new-password"
                    ajuda="Mínimo de 12 caracteres. Uma frase longa é mais fácil de lembrar e mais segura." />
                <x-campo nome="senha_confirmation" tipo="password" rotulo="Confirme a senha" obrigatorio maxlength="128" autocomplete="new-password" />
            </div>
        </x-cartao>

        <div class="flex flex-col gap-2">
            <label class="flex items-start gap-3 text-sm leading-relaxed">
                <input type="checkbox" name="aceite" value="1" class="mt-1 size-4 shrink-0 accent-primaria" required @checked(old('aceite'))>
                <span>
                    Li e aceito os <a href="{{ route('documentos.termos') }}" target="_blank" rel="noopener">Termos de Uso</a>, o
                    <a href="{{ route('documentos.contrato-dados') }}" target="_blank" rel="noopener">Contrato de Tratamento de Dados</a> e a
                    <a href="{{ route('documentos.privacidade') }}" target="_blank" rel="noopener">Política de Privacidade</a>, em nome da empresa.
                </span>
            </label>
            @error('aceite')<p class="text-sm font-medium text-perigo">{{ $message }}</p>@enderror
        </div>

        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
            <x-botao :href="route('entrar')">Já tenho conta</x-botao>
            <x-botao variante="primaria" type="submit">Começar teste grátis</x-botao>
        </div>
    </form>
</x-layouts.publico>
