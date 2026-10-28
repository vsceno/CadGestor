<?php

namespace App\Console\Commands;

use App\Rules\SenhaForte;
use App\Services\Auditoria;
use App\Support\ContextoBanco;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Cria um usuário da equipe da plataforma (quem controla os clientes).
 * A senha é digitada escondida no terminal; nunca há senha fixa em seed.
 */
#[Signature('plataforma:criar-usuario {--nome=} {--email=}')]
#[Description('Cria um usuário da equipe da plataforma (a senha é digitada escondida)')]
class CriarUsuarioPlataforma extends Command
{
    public function handle(ContextoBanco $contexto, Auditoria $auditoria): int
    {
        $nome = trim((string) ($this->option('nome') ?: $this->ask('Nome completo')));
        $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('E-mail'))));
        $senha = (string) $this->secret('Senha (mínimo de 12 caracteres)');
        $confirmacao = (string) $this->secret('Confirme a senha');

        $contexto->definir(null, null, true);

        try {
            $validacao = Validator::make(
                ['nome' => $nome, 'email' => $email, 'senha' => $senha, 'senha_confirmation' => $confirmacao],
                [
                    'nome' => ['required', 'string', 'min:3', 'max:150'],
                    'email' => ['required', 'email', 'max:254'],
                    'senha' => ['required', 'confirmed', new SenhaForte($email)],
                ],
                ['senha.confirmed' => 'A confirmação não confere com a senha.'],
            );

            if ($validacao->fails()) {
                foreach ($validacao->errors()->all() as $erro) {
                    $this->error($erro);
                }

                return self::FAILURE;
            }

            if (DB::connection('pgsql')->scalar('SELECT cg_email_em_uso(?, NULL)', [$email])) {
                $this->error('Este e-mail já está em uso.');

                return self::FAILURE;
            }

            DB::connection('pgsql')->transaction(function () use ($nome, $email, $senha, $auditoria): void {
                $id = (string) DB::connection('pgsql')->scalar(
                    'INSERT INTO usuario_sistema (organizacao_id, nome, email, senha_hash) VALUES (NULL, ?, ?, ?) RETURNING id',
                    [$nome, $email, Hash::make($senha)],
                );
                DB::connection('pgsql')->insert("INSERT INTO usuario_papel (usuario_id, papel) VALUES (?, 'plataforma')", [$id]);
                $auditoria->registrar('criar', 'usuario_sistema', $id, null, ['papeis' => ['plataforma']], null);
            });
        } finally {
            $contexto->limpar();
        }

        $this->info("Usuário da plataforma criado: {$email}");

        return self::SUCCESS;
    }
}
