<?php

namespace App\Console\Commands;

use App\Support\RecriadorBanco;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Apaga e recria o schema do banco de DESENVOLVIMENTO (todas as tabelas,
 * funções e dados) e roda as migrações com o papel dono. Recusado em produção.
 */
#[Signature('banco:recriar {--forcar : Não pedir confirmação}')]
#[Description('Apaga e recria o banco de desenvolvimento (nunca em produção)')]
class RecriarBanco extends Command
{
    public function handle(RecriadorBanco $recriador): int
    {
        if (app()->isProduction()) {
            $this->error('Este comando nunca roda em produção.');

            return self::FAILURE;
        }

        $banco = (string) config('database.connections.pgsql_owner.database');

        if (! $this->option('forcar') && ! $this->confirm("Apagar TODOS os dados do banco {$banco} e recriá-lo?")) {
            $this->info('Nada foi alterado.');

            return self::SUCCESS;
        }

        $recriador->recriar();
        $this->info("Banco {$banco} recriado e migrado.");

        return self::SUCCESS;
    }
}
