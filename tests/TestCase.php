<?php

namespace Tests;

use App\Support\ContextoBanco;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base dos testes.
 *
 * O banco de TESTE é recriado uma vez por execução em tests/bootstrap.php.
 * Cada teste roda dentro de uma transação da conexão da APLICAÇÃO (papel
 * cadgestor_app, sujeito ao RLS), desfeita no fim: um teste nunca enxerga
 * dados de outro.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['pgsql'];

    /** Contexto de banco da requisição de teste (cliente, usuário, plataforma). */
    protected function contexto(): ContextoBanco
    {
        return app(ContextoBanco::class);
    }

    /**
     * Cria um cliente (organização) já provisionado, como a plataforma faz.
     * Devolve o id. O contexto volta a ficar vazio.
     */
    protected function criarCliente(string $nome = 'Cliente de teste'): string
    {
        $this->contexto()->definir(null, null, true);
        $id = (string) DB::connection('pgsql')->scalar('INSERT INTO organizacao (nome) VALUES (?) RETURNING id', [$nome]);
        $this->contexto()->definir($id);
        DB::connection('pgsql')->select('SELECT cg_provisionar_organizacao(?)', [$id]);
        $this->contexto()->limpar();

        return $id;
    }
}
