{{--
    Layout da área logada (plataforma e cliente).

    - Desktop: menu lateral fixo, recolhível para mostrar só ícones (preferência lembrada).
    - Celular: barra inferior com 4 atalhos + "Mais" (abre o menu completo).
    - Cabeçalho: seletor de contexto (cliente, na plataforma; unidade, no cliente),
      busca global e alertas, sempre visíveis.

    Props:
      titulo        título da aba do navegador
      menu          [['rotulo','href','icone','ativo'=>bool], ...]   (menu lateral)
      atalhos       até 4 itens no mesmo formato (barra inferior do celular)
      contexto      ['rotulo' => 'Unidade', 'atual' => 'Matriz', 'opcoes' => [['rotulo','href','ativo']]]
      usuario       ['nome' => ..., 'papel' => ...]
      alertas       quantidade de alertas não lidos (0 esconde o contador)
      buscaAcao     URL do formulário de busca global (null esconde a busca)
      links         ['alertas' => url, 'perfil' => url, 'senha' => url, 'sair' => url (POST)]

    Slots: padrão (conteúdo), mensagens (alertas de topo).
--}}
@props([
    'titulo' => null,
    'menu' => [],
    'atalhos' => [],
    'contexto' => null,
    'usuario' => null,
    'alertas' => 0,
    'buscaAcao' => null,
    'links' => [],
])

@php
    $recolhido = $preferencias->menuRecolhido;

    // Sem menu explícito: o do usuário logado (App\Support\Navegacao), conforme os papéis dele.
    if (isset($navegacao)) {
        $menu = $menu ?: $navegacao['menu'];
        $atalhos = $atalhos ?: $navegacao['atalhos'];
        $usuario ??= $navegacao['usuario'];
        $links = $links ?: $navegacao['links'];
    }
    $links += ['alertas' => '#', 'perfil' => '#', 'senha' => '#', 'sair' => '#'];
@endphp

<!DOCTYPE html>
<html lang="pt-BR" @if ($preferencias->atributoTema()) data-tema="{{ $preferencias->atributoTema() }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $titulo ? $titulo.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    <a href="#conteudo" class="sr-only z-50 rounded-controle bg-primaria font-semibold text-sobre-primaria shadow-alta focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:px-4 focus:py-2 focus:text-sobre-primaria">
        Pular para o conteúdo
    </a>

    <div class="flex min-h-screen">
        {{-- Menu lateral (desktop) --}}
        <aside id="menu-lateral" data-menu-lateral data-recolhido="{{ $recolhido ? 'sim' : 'nao' }}"
            class="group/menu peer [--cg-foco:#9bb4ff] fixed inset-y-0 left-0 z-40 hidden w-64 flex-col bg-lateral text-lateral-texto transition-[width] data-[recolhido=sim]:w-[4.5rem] lg:flex
                   max-lg:data-[aberto=sim]:flex max-lg:data-[aberto=sim]:w-72 max-lg:data-[aberto=sim]:shadow-alta"
            aria-label="Menu principal">
            <div class="flex h-16 items-center gap-3 px-4">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-controle bg-primaria text-sm font-bold text-white" aria-hidden="true">CG</span>
                <span class="truncate text-lg font-bold text-white group-data-[recolhido=sim]/menu:lg:hidden">{{ config('app.name') }}</span>
                <button type="button" class="ml-auto rounded-controle p-1.5 text-lateral-texto hover:bg-lateral-ativo hover:text-white lg:hidden"
                    data-fechar-menu aria-label="Fechar menu">
                    <x-icone nome="fechar" class="size-5" />
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-3 py-2">
                <ul class="flex flex-col gap-1">
                    @foreach ($menu as $item)
                        <li>
                            <a href="{{ $item['href'] }}" @if (! empty($item['ativo'])) aria-current="page" @endif
                               title="{{ $item['rotulo'] }}"
                               class="flex items-center gap-3 rounded-controle px-3 py-2.5 text-sm font-medium no-underline transition-colors
                                    {{ ! empty($item['ativo']) ? 'bg-primaria text-white hover:text-white' : 'text-lateral-texto hover:bg-lateral-ativo hover:text-white' }}">
                                <x-icone :nome="$item['icone']" class="size-5" />
                                <span class="truncate group-data-[recolhido=sim]/menu:lg:sr-only">{{ $item['rotulo'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="border-t border-white/10 p-3">
                <button type="button" data-recolher-menu aria-controls="menu-lateral"
                    class="hidden w-full items-center gap-3 rounded-controle px-3 py-2.5 text-sm text-lateral-texto hover:bg-lateral-ativo hover:text-white lg:flex"
                    aria-label="{{ $recolhido ? 'Expandir menu' : 'Recolher menu' }}">
                    <x-icone nome="recolher" class="size-5 group-data-[recolhido=sim]/menu:hidden" />
                    <x-icone nome="expandir" class="hidden size-5 group-data-[recolhido=sim]/menu:block" />
                    <span class="group-data-[recolhido=sim]/menu:sr-only">Recolher menu</span>
                </button>
            </div>
        </aside>

        {{-- Fundo escuro do menu aberto no celular --}}
        <div class="fixed inset-0 z-30 hidden bg-black/50 lg:hidden" data-fundo-menu></div>

        <div class="flex min-w-0 flex-1 flex-col transition-[padding] lg:pl-64 lg:peer-data-[recolhido=sim]:pl-[4.5rem]" data-area-principal>
            {{-- Cabeçalho --}}
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-borda bg-superficie/95 px-4 backdrop-blur lg:px-6">
                @if ($contexto)
                    <details class="relative" data-seletor-contexto>
                        <summary class="flex h-10 max-w-[14rem] cursor-pointer list-none items-center gap-2 rounded-controle border border-borda-forte bg-superficie px-3 text-sm hover:bg-superficie-2 sm:max-w-xs [&::-webkit-details-marker]:hidden">
                            <span class="hidden text-suave sm:inline">{{ $contexto['rotulo'] }}:</span>
                            <span class="truncate font-semibold">{{ $contexto['atual'] }}</span>
                            <x-icone nome="seta-baixo" class="size-4 text-suave" />
                        </summary>
                        <ul class="absolute left-0 z-30 mt-1 max-h-80 w-72 overflow-y-auto rounded-cartao border border-borda bg-superficie p-1 shadow-alta">
                            @foreach ($contexto['opcoes'] ?? [] as $opcao)
                                <li>
                                    <a href="{{ $opcao['href'] }}" class="flex items-center justify-between gap-2 rounded-controle px-3 py-2 text-sm text-texto no-underline hover:bg-superficie-2"
                                       @if (! empty($opcao['ativo'])) aria-current="true" @endif>
                                        <span class="truncate">{{ $opcao['rotulo'] }}</span>
                                        @if (! empty($opcao['ativo']))<x-icone nome="marcado" class="size-4 text-primaria" />@endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                @if ($buscaAcao)
                    {{-- POST: o que foi pesquisado (nome, CPF) não fica na URL nem no histórico. --}}
                    <form method="POST" action="{{ $buscaAcao }}" role="search" data-busca-global
                        class="relative hidden max-w-md flex-1 md:block max-md:data-[aberta=sim]:absolute max-md:data-[aberta=sim]:inset-x-3 max-md:data-[aberta=sim]:top-3 max-md:data-[aberta=sim]:z-40 max-md:data-[aberta=sim]:block max-md:data-[aberta=sim]:max-w-none">
                        @csrf
                        <label for="busca-global" class="sr-only">Buscar por nome ou CPF</label>
                        <x-icone nome="busca" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-suave" />
                        <input id="busca-global" name="termo" type="search" autocomplete="off" maxlength="100"
                            placeholder="Buscar por nome ou CPF"
                            class="h-10 w-full rounded-controle border border-borda-forte bg-superficie-2 pr-3 pl-9 text-sm text-texto placeholder:text-suave focus:border-primaria focus:bg-superficie">
                    </form>
                @endif

                <div class="ml-auto flex items-center gap-1">
                    @if ($buscaAcao)
                        <x-botao variante="fantasma" icone="busca" rotulo="Buscar" class="md:hidden" data-abrir-busca />
                    @endif

                    <x-alternar-tema :tema="$preferencias->tema" />

                    <a href="{{ $links['alertas'] }}" class="relative inline-flex size-10 items-center justify-center rounded-controle text-texto hover:bg-superficie-2 hover:text-texto"
                       aria-label="Alertas{{ $alertas > 0 ? ': '.$alertas.' não lidos' : '' }}">
                        <x-icone nome="alertas" class="size-5" />
                        @if ($alertas > 0)
                            <span class="numeros absolute top-1 right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-perigo px-1 text-[11px] font-bold text-white" aria-hidden="true">
                                {{ $alertas > 99 ? '99+' : $alertas }}
                            </span>
                        @endif
                    </a>

                    @if ($usuario)
                        <details class="relative">
                            <summary class="flex h-10 cursor-pointer list-none items-center gap-2 rounded-controle px-2 hover:bg-superficie-2 [&::-webkit-details-marker]:hidden">
                                <span class="flex size-8 items-center justify-center rounded-full bg-primaria-suave text-sm font-semibold text-primaria" aria-hidden="true">
                                    {{ mb_strtoupper(mb_substr($usuario['nome'], 0, 1)) }}
                                </span>
                                <span class="hidden text-left text-sm leading-tight sm:block">
                                    <span class="block font-semibold">{{ $usuario['nome'] }}</span>
                                    <span class="block text-xs text-suave">{{ $usuario['papel'] ?? '' }}</span>
                                </span>
                            </summary>
                            <ul class="absolute right-0 z-30 mt-1 w-52 rounded-cartao border border-borda bg-superficie p-1 shadow-alta">
                                <li><a href="{{ $links['perfil'] }}" class="flex items-center gap-2 rounded-controle px-3 py-2 text-sm text-texto no-underline hover:bg-superficie-2"><x-icone nome="usuario" class="size-4" /> Meu perfil</a></li>
                                <li><a href="{{ $links['senha'] }}" class="flex items-center gap-2 rounded-controle px-3 py-2 text-sm text-texto no-underline hover:bg-superficie-2"><x-icone nome="bloquear" class="size-4" /> Trocar senha</a></li>
                                <li class="mt-1 border-t border-borda pt-1">
                                    <form method="POST" action="{{ $links['sair'] }}">
                                        @csrf
                                        <button type="submit" class="flex w-full items-center gap-2 rounded-controle px-3 py-2 text-sm text-perigo hover:bg-perigo-suave">
                                            <x-icone nome="sair" class="size-4" /> Sair
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </details>
                    @endif
                </div>
            </header>

            <main id="conteudo" class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 pb-24 lg:px-8 lg:pb-8">
                <x-mensagens-sessao />
                {{ $mensagens ?? '' }}
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- Barra inferior (celular): 4 atalhos + Mais --}}
    @if ($atalhos !== [])
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-borda bg-superficie lg:hidden" aria-label="Atalhos">
            <ul class="grid grid-cols-5">
                @foreach (array_slice($atalhos, 0, 4) as $atalho)
                    <li>
                        <a href="{{ $atalho['href'] }}" @if (! empty($atalho['ativo'])) aria-current="page" @endif
                           class="flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium no-underline {{ ! empty($atalho['ativo']) ? 'text-primaria' : 'text-suave hover:text-texto' }}">
                            <x-icone :nome="$atalho['icone']" class="size-6" />
                            {{ $atalho['rotulo'] }}
                        </a>
                    </li>
                @endforeach
                <li>
                    <button type="button" data-abrir-menu aria-controls="menu-lateral" aria-expanded="false"
                        class="flex w-full flex-col items-center gap-0.5 py-2 text-[11px] font-medium text-suave hover:text-texto">
                        <x-icone nome="mais" class="size-6" />
                        Mais
                    </button>
                </li>
            </ul>
        </nav>
    @endif
</body>
</html>
