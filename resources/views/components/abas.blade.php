{{--
    Abas de navegação entre seções de um registro (ex.: Dados, Vínculos, Acessos).
    Cada aba é um link para a sua própria URL: funciona sem JavaScript, com o
    botão voltar e com o teclado.

    abas: [['rotulo' => 'Dados', 'href' => ..., 'ativa' => true, 'contador' => 3]]
--}}
@props(['abas' => [], 'rotulo' => 'Seções'])

<nav aria-label="{{ $rotulo }}" {{ $attributes->merge(['class' => 'border-b border-borda']) }}>
    <ul class="-mb-px flex gap-1 overflow-x-auto">
        @foreach ($abas as $aba)
            <li>
                <a href="{{ $aba['href'] }}"
                   @if (! empty($aba['ativa'])) aria-current="page" @endif
                   class="flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium whitespace-nowrap no-underline transition-colors
                        {{ ! empty($aba['ativa']) ? 'border-primaria text-primaria' : 'border-transparent text-suave hover:border-borda-forte hover:text-texto' }}">
                    {{ $aba['rotulo'] }}
                    @isset($aba['contador'])
                        <span class="numeros rounded-full bg-superficie-2 px-2 text-xs text-suave">{{ $aba['contador'] }}</span>
                    @endisset
                </a>
            </li>
        @endforeach
    </ul>
</nav>
