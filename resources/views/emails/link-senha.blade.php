Olá, {{ $primeiroNome }}.

@if ($contaNova)
Você foi cadastrado(a) como administrador(a) no CadGestor. Para criar a sua senha, acesse:
@else
Recebemos um pedido para redefinir a sua senha do CadGestor. Para criar uma nova senha, acesse:
@endif

{{ $link }}

O link vale por {{ $validade }} e pode ser usado uma única vez.
@unless ($contaNova)

Se você não pediu, ignore esta mensagem: sua senha atual continua valendo.
@endunless

Ninguém da equipe do CadGestor pede a sua senha.
