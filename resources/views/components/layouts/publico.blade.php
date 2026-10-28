{{--
    Layout das páginas sem login (entrar, recuperar senha, contratar, documentos).
--}}
@props(['titulo' => null, 'largura' => 'max-w-md'])

<!DOCTYPE html>
<html lang="pt-BR" @if ($preferencias->atributoTema()) data-tema="{{ $preferencias->atributoTema() }}" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $titulo ? $titulo.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-[radial-gradient(1100px_480px_at_10%_-10%,var(--cg-primaria-suave),transparent_60%)]">
    <header class="mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-4 sm:px-6">
        <a href="/" class="flex items-center gap-2.5 text-lg font-bold text-texto no-underline hover:text-texto">
            <span class="flex size-9 items-center justify-center rounded-controle bg-primaria text-sm text-white" aria-hidden="true">CG</span>
            {{ config('app.name') }}
        </a>
        <x-alternar-tema :tema="$preferencias->tema" />
    </header>

    <main id="conteudo" class="mx-auto flex w-full {{ $largura }} flex-1 flex-col gap-4 px-4 py-6">
        {{ $slot }}
    </main>

    <footer class="py-6 text-center text-sm text-suave">
        {{ config('app.name') }} · controle de acesso
    </footer>
</body>
</html>
