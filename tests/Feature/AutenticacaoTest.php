<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Usuario;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Login com isolamento entre clientes: a conta é localizada sem saber o
 * cliente, e a partir daí o banco opera só no cliente dela.
 */
class AutenticacaoTest extends TestCase
{
    private const SENHA = 'frase de teste bem longa';

    public function test_senha_e_guardada_em_argon2id(): void
    {
        $this->assertStringStartsWith('$argon2id$', Hash::make(self::SENHA));
    }

    public function test_login_define_o_contexto_do_cliente_do_usuario(): void
    {
        $cliente = $this->criarCliente('Cliente A');
        $id = $this->criarUsuario($cliente, 'ana@cliente-a.exemplo', [Papel::CadastroPessoa]);

        $this->assertTrue(Auth::attempt(['email' => 'ANA@cliente-a.exemplo', 'password' => self::SENHA]));
        $this->assertSame($id, Auth::id());
        $this->assertSame($cliente, $this->contexto()->organizacaoId());
        $this->assertFalse($this->contexto()->emModoPlataforma());
        $this->assertSame(
            $cliente,
            DB::connection('pgsql')->scalar("SELECT current_setting('app.organizacao_id')"),
        );

        /** @var Usuario $usuario */
        $usuario = Auth::user();
        $this->assertTrue($usuario->temPapel(Papel::CadastroPessoa));
        $this->assertFalse($usuario->temPapel(Papel::Administrador));
    }

    public function test_senha_errada_e_email_inexistente_nao_entram(): void
    {
        $cliente = $this->criarCliente();
        $this->criarUsuario($cliente, 'ana@cliente.exemplo', [Papel::Consulta]);

        $this->assertFalse(Auth::attempt(['email' => 'ana@cliente.exemplo', 'password' => 'errada']));
        $this->assertFalse(Auth::attempt(['email' => 'ninguem@exemplo.com', 'password' => self::SENHA]));
    }

    public function test_usuario_inativo_ou_cliente_desativado_nao_entram(): void
    {
        $cliente = $this->criarCliente();
        $id = $this->criarUsuario($cliente, 'ana@cliente.exemplo', [Papel::Administrador]);

        $this->comoPlataforma(fn () => DB::connection('pgsql')->update('UPDATE organizacao SET ativo = false WHERE id = ?', [$cliente]));
        $this->assertFalse(Auth::attempt(['email' => 'ana@cliente.exemplo', 'password' => self::SENHA]), 'Cliente desativado.');

        $this->comoPlataforma(fn () => DB::connection('pgsql')->update('UPDATE organizacao SET ativo = true WHERE id = ?', [$cliente]));
        $this->contexto()->definir($cliente);
        DB::connection('pgsql')->update('UPDATE usuario_sistema SET ativo = false WHERE id = ?', [$id]);
        $this->contexto()->limpar();
        $this->assertFalse(Auth::attempt(['email' => 'ana@cliente.exemplo', 'password' => self::SENHA]), 'Usuário inativo.');
    }

    public function test_administrador_do_cliente_tem_todos_os_papeis_do_cliente_mas_nao_o_da_plataforma(): void
    {
        $cliente = $this->criarCliente();
        $this->criarUsuario($cliente, 'admin@cliente.exemplo', [Papel::Administrador]);
        Auth::attempt(['email' => 'admin@cliente.exemplo', 'password' => self::SENHA]);

        /** @var Usuario $usuario */
        $usuario = Auth::user();
        $this->assertTrue($usuario->temPapel(Papel::CadastroEmpresa));
        $this->assertTrue($usuario->temPapel(Papel::Consulta));
        $this->assertFalse($usuario->temPapel(Papel::Plataforma));
    }

    public function test_usuario_da_plataforma_opera_fora_dos_clientes(): void
    {
        $id = $this->comoPlataforma(function (): string {
            $id = (string) DB::connection('pgsql')->scalar(
                'INSERT INTO usuario_sistema (organizacao_id, nome, email, senha_hash) VALUES (NULL, ?, ?, ?) RETURNING id',
                ['Equipe', 'equipe@plataforma.exemplo', Hash::make(self::SENHA)],
            );
            DB::connection('pgsql')->insert("INSERT INTO usuario_papel (usuario_id, papel) VALUES (?, 'plataforma')", [$id]);

            return $id;
        });
        $this->criarCliente('Cliente com dados');

        $this->assertTrue(Auth::attempt(['email' => 'equipe@plataforma.exemplo', 'password' => self::SENHA]));
        $this->assertSame($id, Auth::id());
        $this->assertTrue($this->contexto()->emModoPlataforma());
        $this->assertNull($this->contexto()->organizacaoId());

        // Vê os clientes, mas nenhum dado do ambiente deles.
        $this->assertGreaterThan(0, (int) DB::connection('pgsql')->scalar('SELECT count(*) FROM organizacao'));
        $this->assertSame(0, (int) DB::connection('pgsql')->scalar('SELECT count(*) FROM perfil_vinculo'));
    }

    /**
     * @param  list<Papel>  $papeis
     */
    private function criarUsuario(string $cliente, string $email, array $papeis): string
    {
        $this->contexto()->definir($cliente);
        $id = (string) DB::connection('pgsql')->scalar(
            'INSERT INTO usuario_sistema (nome, email, senha_hash) VALUES (?, ?, ?) RETURNING id',
            ['Usuária Teste', $email, Hash::make(self::SENHA)],
        );

        foreach ($papeis as $papel) {
            DB::connection('pgsql')->insert('INSERT INTO usuario_papel (usuario_id, papel) VALUES (?, ?)', [$id, $papel->value]);
        }

        $this->contexto()->limpar();

        return $id;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $acao
     * @return T
     */
    private function comoPlataforma(callable $acao): mixed
    {
        $this->contexto()->definir(null, null, true);

        try {
            return $acao();
        } finally {
            $this->contexto()->limpar();
        }
    }
}
