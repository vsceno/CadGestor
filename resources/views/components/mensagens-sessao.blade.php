{{--
    Mensagens deixadas na sessão por um redirecionamento ("Salvo.", "Você saiu.").
    Chaves: sucesso, atencao, erro, info.
--}}
@foreach (['sucesso' => 'sucesso', 'atencao' => 'atencao', 'erro' => 'erro', 'info' => 'info'] as $chave => $tipo)
    @if (session()->has($chave))
        <x-alerta :tipo="$tipo">{{ session($chave) }}</x-alerta>
    @endif
@endforeach
