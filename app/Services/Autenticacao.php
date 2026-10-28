<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\ContextoBanco;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Login e logout com as travas de segurança:
 * - mesma resposta (e mesmo tempo) para e-mail inexistente e senha errada;
 * - bloqueio temporário após várias falhas (por e-mail e por rede);
 * - conta inativa ou de cliente desativado não entra;
 * - e-mail ainda não confirmado não entra (mas pode pedir novo link);
 * - novo ID de sessão a cada login (contra fixação de sessão).
 */
class Autenticacao
{
    public const OK = 'ok';

    public const FALHA = 'falha';

    public const BLOQUEADO = 'bloqueado';

    public const EMAIL_NAO_CONFIRMADO = 'email_nao_confirmado';

    private const CHAVE_REENVIO = 'reenvio_confirmacao';

    public function __construct(
        private readonly LimitadorTentativas $limitador,
        private readonly Auditoria $auditoria,
        private readonly ContextoBanco $contexto,
    ) {}

    /** @return self::OK|self::FALHA|self::BLOQUEADO|self::EMAIL_NAO_CONFIRMADO */
    public function entrar(string $email, #[\SensitiveParameter] string $senha, string $ip): string
    {
        $email = mb_strtolower(trim($email));

        if ($this->limitador->bloqueado($email, $ip)) {
            return self::BLOQUEADO;
        }

        $usuario = Auth::getProvider()->retrieveByCredentials(['email' => $email]);
        $usuario = $usuario instanceof Usuario ? $usuario : null;

        // Sem conta, confere contra um hash fictício: a resposta demora o mesmo tempo.
        $senhaConfere = Hash::check($senha, $usuario?->getAuthPassword() ?? self::hashFicticio());
        $sucesso = $usuario !== null && $senhaConfere;

        $this->limitador->registrar($email, $ip, $sucesso);

        if (! $sucesso) {
            // Sem e-mail na trilha; o registro só aponta o usuário quando ele existe.
            $this->auditoria->registrar('login_falhou', 'usuario_sistema', $usuario?->id, usuarioId: null);
            $this->contexto->limpar();

            return self::FALHA;
        }

        // Só depois da senha correta, para não revelar quais e-mails existem.
        if ($usuario->email_verificado_em === null) {
            session()->put(self::CHAVE_REENVIO, ['usuario_id' => $usuario->id, 'expira' => now()->addMinutes(10)->timestamp]);
            $this->contexto->limpar();

            return self::EMAIL_NAO_CONFIRMADO;
        }

        if (Hash::needsRehash($usuario->getAuthPassword())) {
            DB::connection('pgsql')->update('UPDATE usuario_sistema SET senha_hash = ? WHERE id = ?', [Hash::make($senha), $usuario->id]);
            $usuario->refresh();
        }

        Auth::login($usuario);
        session()->regenerate();
        DB::connection('pgsql')->update('UPDATE usuario_sistema SET ultimo_login_em = now() WHERE id = ?', [$usuario->id]);
        $this->auditoria->registrar('login', 'usuario_sistema', $usuario->id);

        return self::OK;
    }

    public function sair(): void
    {
        if (Auth::check()) {
            $this->auditoria->registrar('logout', 'usuario_sistema', (string) Auth::id());
        }

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->contexto->limpar();
    }

    /** Conta que acertou a senha mas ainda não confirmou o e-mail (dentro do prazo). */
    public function contaAguardandoConfirmacao(): ?string
    {
        $pendente = session()->get(self::CHAVE_REENVIO);

        if (! is_array($pendente) || ! is_string($pendente['usuario_id'] ?? null) || ($pendente['expira'] ?? 0) < time()) {
            session()->forget(self::CHAVE_REENVIO);

            return null;
        }

        return $pendente['usuario_id'];
    }

    public function esquecerContaAguardandoConfirmacao(): void
    {
        session()->forget(self::CHAVE_REENVIO);
    }

    /**
     * Hash Argon2id (mesmos parâmetros do Laravel: m=65536, t=4, p=1) de uma
     * senha aleatória descartada. Pré-calculado: gerá-lo a cada pedido
     * deixaria a resposta mais lenta justamente para e-mails inexistentes.
     */
    private static function hashFicticio(): string
    {
        return '$argon2id$v=19$m=65536,t=4,p=1$YmV1WmJLTjcxLjlnaEFiTA$xe0l7+i6rmT4bxChorI/XkqqvHxn4lzMz4udMJAxt+c';
    }
}
