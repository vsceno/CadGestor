<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Estrutura do banco: executa database/sql/0004_pessoas_vinculos_regras.sql (SQL puro, com RLS,
 * triggers e permissões finas do papel da aplicação).
 *
 * Sem "down": correções sempre entram numa migração nova. Para recriar o
 * banco de desenvolvimento do zero, use "php artisan banco:recriar".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared((string) file_get_contents(database_path('sql/0004_pessoas_vinculos_regras.sql')));
    }
};
