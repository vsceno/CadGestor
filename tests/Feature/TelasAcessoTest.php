<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Mail\LinkSenha;
use App\Services\LimitadorTentativas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Telas de acesso: entrar, sair, esqueci minha senha, criar senha pelo link,
 * trocar a própria senha, troca obrigatória e checagem de papel.
 */
class TelasAcessoTest extends TestCase
{
    private const SENHA = 'frase de teste bem longa';

    private const NOVA = 'outra frase longa e diferente';

    public function test_visitante_e_levado_ao_login(): void
    {
        $this->get('/')->assertRedirect(route('entrar'));
        $this->get(route('entrar'))->assertOk()->assertSee('Entrar no CadGestor')->assertSee('Esqueci minha senha');
    }

    public function test_login_certo_abre_o_inicio_do_cliente_com_primeiros_passos(): void
    {
        $this->criarUsuarioCliente('ana@cliente.exemplo', [Papel::Administrador]);

        $this->post(route('entrar'), ['email' => 'ana@cliente.exemplo', 'senha' => self::SENHA])
            ->assertRedirect(route('inicio'));
        $this->assertAuthenticated();

        $this->get(route('inicio'))->assertOk()->assertSee('Primeiros passos')->assertSee('Olá, Usuária.');
    }

    public function test_login_errado_mostra_mensagem_generica(): void
    {
        $this->criarUsuarioCliente('ana@cliente.exemplo', [Papel::Consulta]);

        $this->from(route('entrar'))
            ->post(route('entrar'), ['email' => 'ana@cliente.exemplo', 'senha' => 'errada'])
            ->assertRedirect(route('entrar'))
            ->assertSessionHas('erro_login', 'E-mail ou senha incorretos.');

        $this->from(route('entrar'))
            ->post(route('entrar'), ['email' => 'ninguem@exemplo.com', 'senha' => self::SENHA])
            ->assertSessionHas('erro_login', 'E-mail ou senha incorretos.');
        $this->assertGuest();
    }

    public function test_bloqueia_apos_cinco_falhas_mesmo_com_a_senha_certa(): void
    {
        $this->criarUsuarioCliente('ana@cliente.exemplo', [Papel::Consulta]);

        for ($i = 0; $i < LimitadorTentativas::MAXIMO_POR_EMAIL; $i++) {
            $this->post(route('entrar'), ['email' => 'ana@cliente.exemplo', 'senha' => 'errada']);
        }

        $this->from(route('entrar'))
            ->post(route('entrar'), ['email' => 'ana@cliente.exemplo', 'senha' => self::SENHA])
            ->assertSessionHas('erro_login', fn (string $msg): bool => str_contains($msg, 'Muitas tentativas'));
        $this->assertGuest();
    }

    public function test_esqueci_minha_senha_nao_revela_quem_tem_conta_e_o_link_funciona_uma_vez(): void
    {
        Mail::fake();
        $this->criarUsuarioCliente('ana@cliente.exemplo', [Papel::Consulta]);

        $respostaExiste = $this->post(route('senha.esqueci'), ['email' => 'ana@cliente.exemplo'])->assertOk();
        $respostaNaoExiste = $this->post(route('senha.esqueci'), ['email' => 'ninguem@exemplo.com'])->assertOk();
        $respostaExiste->assertSee('Se este e-mail tiver uma conta ativa');
        $respostaNaoExiste->assertSee('Se este e-mail tiver uma conta ativa');

        Mail::assertSent(LinkSenha::class, 1);
        $link = '';
        Mail::assertSent(LinkSenha::class, function (LinkSenha $mail) use (&$link): bool {
            $link = $mail->link;

            return $mail->hasTo('ana@cliente.exemplo');
        });
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $token = $query['token'];

        // No banco, só o hash do token.
        $this->assertSame(1, (int) DB::connection('pgsql')->scalar(
            'SELECT count(*) FROM cg_conta_por_redefinicao(?)', [hash('sha256', $token)],
        ));

        $this->get(route('senha.redefinir', ['token' => $token]))->assertOk()->assertSee('Criar nova senha');
        $this->post(route('senha.redefinir'), ['token' => $token, 'senha' => self::NOVA, 'senha_confirmation' => self::NOVA])
            ->assertRedirect(route('entrar'));

        $this->get(route('senha.redefinir', ['token' => $token]))->assertOk()->assertSee('Link inválido');
        $this->post(route('entrar'), ['email' => 'ana@cliente.exemplo', 'senha' => self::NOVA])->assertRedirect(route('inicio'));
    }

    public function test_nova_senha_fraca_e_recusada_no_link(): void
    {
        Mail::fake();
        $this->criarUsuarioCliente('ana@cliente.exemplo', [Papel::Consulta]);
        $this->post(route('senha.esqueci'), ['email' => 'ana@cliente.exemplo']);
        $token = '';
        Mail::assertSent(LinkSenha::class, function (LinkSenha $mail) use (&$token): bool {
            parse_str((string) parse_url($mail->link, PHP_URL_QUERY), $query);
            $token = $query['token'];

            return true;
        });

        $this->from(route('senha.redefinir', ['token' => $token]))
            ->post(route('senha.redefinir'), ['token' => $token, 'senha' => 'curta', 'senha_confirmation' => 'curta'])
            ->assertSessionHasErrors(['senha' => 'A senha deve ter pelo menos 12 caracteres.']);
        $this->post(route('senha.redefinir'), ['token' => $token, 'senha' => self::NOVA, 'senha_confirmation' => 'outra coisa qualquer'])
            ->assertSessionHasErrors(['senha' => 'A confirmação não confere com a nova senha.']);
        $this->assertSame(1, (int) DB::connection('pgsql')->scalar(
            'SELECT count(*) FROM cg_conta_por_redefinicao(?)', [hash('sha256', $token)],
        ), 'Erro de digitação não gasta o link.');
    }

    public function test_trocar_a_propria_senha(): void
    {
        $this->criarUsuarioCliente('ana@cliente.exemplo', [Papel::Consulta]);
        $this->post(route('entrar'), ['email' => 'ana@cliente.exemplo', 'senha' => self::SENHA]);

        $this->post(route('senha.trocar'), ['senha_atual' => 'errada', 'senha' => self::NOVA, 'senha_confirmation' => self::NOVA])
            ->assertSessionHasErrors(['senha_atual' => 'Senha atual incorreta.']);

        $this->post(route('senha.trocar'), ['senha_atual' => self::SENHA, 'senha' => self::NOVA, 'senha_confirmation' => self::NOVA])
            ->assertRedirect(route('inicio'));
        $this->assertAuthenticated();
        $this->get(route('inicio'))->assertOk();
    }

    public function test_troca_de_senha_obrigatoria_bloqueia_as_outras_telas(): void
    {
        $id = $this->criarUsuarioCliente('ana@cliente.exemplo', [Papel::Consulta], deveTrocar: true);
        $this->post(route('entrar'), ['email' => 'ana@cliente.exemplo', 'senha' => self::SENHA]);

        $this->get(route('inicio'))->assertRedirect(route('senha.trocar'));
        $this->get(route('senha.trocar'))->assertOk();
        $this->assertNotEmpty($id);
    }

    public function test_papel_e_exigido_nas_rotas_protegidas(): void
    {
        Route::middleware(['web', 'auth', 'papel:administrador'])->get('/teste-so-admin', fn () => 'ok');
        $this->criarUsuarioCliente('consulta@cliente.exemplo', [Papel::Consulta]);
        $this->post(route('entrar'), ['email' => 'consulta@cliente.exemplo', 'senha' => self::SENHA]);

        $this->get('/teste-so-admin')->assertForbidden();
    }

    public function test_plataforma_ve_o_painel_e_o_menu_da_plataforma(): void
    {
        $this->contexto()->definir(null, null, true);
        $id = (string) DB::connection('pgsql')->scalar(
            'INSERT INTO usuario_sistema (organizacao_id, nome, email, senha_hash) VALUES (NULL, ?, ?, ?) RETURNING id',
            ['Equipe Comercial', 'equipe@plataforma.exemplo', Hash::make(self::SENHA)],
        );
        DB::connection('pgsql')->insert("INSERT INTO usuario_papel (usuario_id, papel) VALUES (?, 'plataforma')", [$id]);
        $this->contexto()->limpar();

        $this->post(route('entrar'), ['email' => 'equipe@plataforma.exemplo', 'senha' => self::SENHA]);
        $this->get(route('inicio'))->assertOk()->assertSee('Painel da plataforma')->assertDontSee('Primeiros passos');
    }

    public function test_sair_encerra_a_sessao(): void
    {
        $this->criarUsuarioCliente('ana@cliente.exemplo', [Papel::Consulta]);
        $this->post(route('entrar'), ['email' => 'ana@cliente.exemplo', 'senha' => self::SENHA]);

        $this->post(route('sair'))->assertRedirect(route('entrar'));
        $this->assertGuest();
        $this->get(route('inicio'))->assertRedirect(route('entrar'));
    }

    /**
     * @param  list<Papel>  $papeis
     */
    private function criarUsuarioCliente(string $email, array $papeis, bool $deveTrocar = false): string
    {
        $cliente = $this->criarCliente();
        $this->contexto()->definir($cliente);
        $id = (string) DB::connection('pgsql')->scalar(
            'INSERT INTO usuario_sistema (nome, email, senha_hash, deve_trocar_senha) VALUES (?, ?, ?, ?) RETURNING id',
            ['Usuária Teste', $email, Hash::make(self::SENHA), $deveTrocar],
        );

        foreach ($papeis as $papel) {
            DB::connection('pgsql')->insert('INSERT INTO usuario_papel (usuario_id, papel) VALUES (?, ?)', [$id, $papel->value]);
        }

        $this->contexto()->limpar();

        return $id;
    }
}
