<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recria o schema "cadgestor" do zero (desenvolvimento e testes) com o papel
 * dono, refazendo as permissões padrão do papel da aplicação, e roda as
 * migrações. Nunca em produção.
 */
class RecriadorBanco
{
    public function recriar(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Recriar o banco é proibido em produção.');
        }

        DB::connection('pgsql_owner')->unprepared(<<<'SQL'
            DROP SCHEMA IF EXISTS cadgestor CASCADE;
            CREATE SCHEMA cadgestor;
            GRANT USAGE ON SCHEMA cadgestor TO cadgestor_app;
            ALTER DEFAULT PRIVILEGES IN SCHEMA cadgestor
                GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO cadgestor_app;
            ALTER DEFAULT PRIVILEGES IN SCHEMA cadgestor
                GRANT USAGE, SELECT ON SEQUENCES TO cadgestor_app;
            ALTER DEFAULT PRIVILEGES IN SCHEMA cadgestor
                GRANT EXECUTE ON FUNCTIONS TO cadgestor_app;
            SQL);

        // Conexões abertas guardavam o schema antigo em cache de planos: reabre.
        DB::purge('pgsql');
        DB::purge('pgsql_owner');

        Artisan::call('migrate', ['--database' => 'pgsql_owner', '--force' => true]);
    }
}
