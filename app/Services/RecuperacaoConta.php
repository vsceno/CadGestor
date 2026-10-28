<?php

namespace App\Services;

use App\Mail\ConfirmacaoEmail;
use App\Mail\LinkSenha;
use App\Models\Usuario;
use App\Rules\SenhaForte;
use App\Support\ContextoBanco;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Recuperação de acesso sem que ninguém veja ou escolha a senha de outra
 * pessoa: "esqueci minha senha", link de primeira senha para administrador
 * criado pela plataforma e reenvio da confirmação de e-mail.
 *
 * O link é como a chave de um cofre enviada pelo correio ao titular: só quem
 * acessa a caixa de e-mail consegue usá-la, ela vale por pouco tempo, abre
 * uma vez só e o banco guarda apenas a "impressão digital" (hash) dela.
 */
class RecuperacaoConta
{
    public const MINUTOS_REDEFINICAO = 60;

    public const MINUTOS_CONTA_NOVA = 72 * 60;

    public const HORAS_CONFIRMACAO = 72;

    public function __construct(
        private readonly ContextoBanco $contexto,
        private readonly LimitadorTentativas $limitador,
        private readonly Auditoria $auditoria,
    ) {}

    /**
     * "Esqueci minha senha". Não revela se o e-mail existe: quem chama mostra
     * sempre a mesma mensagem. Devolve false só quando o limite de pedidos foi
     * atingido (aí nada é enviado).
     */
    public function solicitarRedefinicao(string $email, string $ip): bool
    {
        $email = mb_strtolower(trim($email));

        if ($this->limitador->excedeu('redefinicao_senha', $ip, $email, 10, 3)) {
            return false;
        }

        $this->limitador->registrarUso('redefinicao_senha', $ip, $email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return true;
        }

        $conta = DB::connection('pgsql')->selectOne('SELECT usuario_id, organizacao_id FROM cg_login_usuario(?)', [$email]);

        if ($conta === null) {
            return true;
        }

        // A conta passa a ser o contexto: ela se enxerga (RLS) e o pedido vai para a trilha do cliente dela.
        $this->contexto->definir($conta->organizacao_id, $conta->usuario_id);

        try {
            $usuario = Usuario::query()->find($conta->usuario_id);

            if ($usuario instanceof Usuario && $usuario->ativo && $this->clienteAtivo($conta->organizacao_id)) {
                $this->enviarLink($usuario, 'esqueci_senha');
            }
        } finally {
            $this->contexto->limpar();
        }

        return true;
    }

    /**
     * Gera e envia o link para o e-mail do próprio usuário, no contexto já
     * definido por quem chama.
     *
     * @param  bool  $contaNova  administrador recém-criado: o link serve para criar a primeira senha
     */
    public function enviarLink(Usuario $usuario, string $via, bool $contaNova = false): void
    {
        $token = bin2hex(random_bytes(32));
        $minutos = $contaNova ? self::MINUTOS_CONTA_NOVA : self::MINUTOS_REDEFINICAO;

        DB::connection('pgsql')->transaction(function () use ($usuario, $token, $minutos, $via): void {
            // Validade calculada no banco (UTC): sem confusão de fuso horário.
            DB::connection('pgsql')->update(
                'UPDATE usuario_sistema SET redefinicao_token_hash = ?, redefinicao_expira_em = now() + make_interval(mins => ?) WHERE id = ?',
                [hash('sha256', $token), $minutos, $usuario->id],
            );
            $this->auditoria->registrar('redefinicao_solicitada', 'usuario_sistema', $usuario->id, null, ['via' => $via]);
        });

        Mail::to($usuario->email)->send(new LinkSenha($usuario->nome, route('senha.redefinir', ['token' => $token]), $minutos, $contaNova));
    }

    /** O link ainda vale? (para mostrar o formulário de nova senha) */
    public function linkValido(string $token): bool
    {
        return $this->contaDoLink($token) !== null;
    }

    /**
     * Define a nova senha pelo link: uso único, encerra as sessões abertas
     * (o hash muda) e libera o login travado por tentativas.
     *
     * @throws ValidationException
     */
    public function redefinir(string $token, string $senha, string $confirmacao, string $ip): void
    {
        $conta = $this->contaDoLink($token);

        if ($conta === null) {
            throw ValidationException::withMessages(['token' => 'Este link não vale mais. Peça um novo em "Esqueci minha senha".']);
        }

        Validator::make(
            ['senha' => $senha, 'senha_confirmation' => $confirmacao],
            ['senha' => ['required', 'confirmed', new SenhaForte($conta->email)]],
            ['senha.confirmed' => 'A confirmação não confere com a nova senha.'],
        )->validate();

        $this->contexto->definir($conta->organizacao_id, $conta->usuario_id);

        try {
            DB::connection('pgsql')->transaction(function () use ($token, $senha, $conta, $ip): void {
                $alterada = DB::connection('pgsql')->selectOne(
                    'SELECT usuario_id FROM cg_redefinir_senha(?, ?)',
                    [hash('sha256', $token), Hash::make($senha)],
                );

                if ($alterada === null) {
                    throw ValidationException::withMessages(['token' => 'Este link não vale mais. Peça um novo em "Esqueci minha senha".']);
                }

                $this->auditoria->registrar('senha_alterada', 'usuario_sistema', $conta->usuario_id, null, ['via' => 'link'], $conta->usuario_id);
                $this->limitador->desbloquear($conta->email, $ip);
            });
        } finally {
            $this->contexto->limpar();
        }
    }

    /**
     * Novo link de confirmação de e-mail (o anterior deixa de valer), no
     * contexto do cliente do usuário. Devolve false se ele já confirmou.
     */
    public function reenviarConfirmacao(Usuario $usuario, string $via): bool
    {
        if ($usuario->email_verificado_em !== null) {
            return false;
        }

        $token = bin2hex(random_bytes(32));

        DB::connection('pgsql')->transaction(function () use ($usuario, $token, $via): void {
            DB::connection('pgsql')->update(
                'UPDATE usuario_sistema SET verificacao_token_hash = ?, verificacao_expira_em = now() + make_interval(hours => ?) WHERE id = ? AND email_verificado_em IS NULL',
                [hash('sha256', $token), self::HORAS_CONFIRMACAO, $usuario->id],
            );
            $this->auditoria->registrar('confirmacao_reenviada', 'usuario_sistema', $usuario->id, null, ['via' => $via], $usuario->id);
        });

        Mail::to($usuario->email)->send(new ConfirmacaoEmail($usuario->nome, route('email.confirmar', ['token' => $token]), self::HORAS_CONFIRMACAO));

        return true;
    }

    /** Link de confirmação de e-mail (chega sem sessão). */
    public function confirmarEmail(string $token): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return false;
        }

        $conta = DB::connection('pgsql')->selectOne('SELECT usuario_id, organizacao_id FROM cg_confirmar_email(?)', [hash('sha256', $token)]);

        if ($conta === null) {
            return false;
        }

        $this->contexto->definir($conta->organizacao_id, $conta->usuario_id);

        try {
            $this->auditoria->registrar('email_confirmado', 'usuario_sistema', $conta->usuario_id, null, null, $conta->usuario_id);
        } finally {
            $this->contexto->limpar();
        }

        return true;
    }

    /** @return object{usuario_id: string, organizacao_id: ?string, email: string}|null */
    private function contaDoLink(string $token): ?object
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }

        return DB::connection('pgsql')->selectOne(
            'SELECT usuario_id, organizacao_id, email FROM cg_conta_por_redefinicao(?)',
            [hash('sha256', $token)],
        );
    }

    private function clienteAtivo(?string $organizacaoId): bool
    {
        return $organizacaoId === null
            || (bool) DB::connection('pgsql')->scalar('SELECT ativo FROM organizacao WHERE id = ?', [$organizacaoId]);
    }
}
