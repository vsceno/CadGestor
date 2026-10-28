<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Mail\LimiteDoPlano;
use App\Mail\LinkSenha;
use App\Models\Organizacao;
use App\Services\LimitesPlano;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Plataforma (clientes, planos, assinatura) e "Minha assinatura" do cliente:
 * assistente de venda, desativação, troca de plano, régua de atraso,
 * limites do plano e isolamento entre as áreas.
 */
class PlataformaTest extends TestCase
{
    private const SENHA = 'frase de teste bem longa';

    private const CADASTRO = [
        'nome' => 'Condomínio Solar Ltda',
        'cnpj' => '11.222.333/0001-81',
        'cep' => '60115-170',
        'logradouro' => 'Avenida Exemplo',
        'numero' => '100',
        'bairro' => 'Aldeota',
        'municipio' => 'Fortaleza',
        'uf' => 'CE',
        'telefone' => '(85) 3222-1100',
        'email_financeiro' => 'Financeiro@Solar.exemplo',
    ];

    public function test_assistente_cria_cliente_com_cadastro_assinatura_e_link_para_o_administrador(): void
    {
        Mail::fake();
        $this->entrarComoPlataforma();
        $plano = (string) DB::connection('pgsql')->scalar("SELECT id FROM plano WHERE codigo = 'profissional'");

        $this->post(route('plataforma.clientes.novo.etapa', ['etapa' => 1]), self::CADASTRO)->assertRedirect(route('plataforma.clientes.novo', ['etapa' => 2]));
        $this->post(route('plataforma.clientes.novo.etapa', ['etapa' => 2]), ['plano_id' => $plano])->assertRedirect(route('plataforma.clientes.novo', ['etapa' => 3]));
        $this->post(route('plataforma.clientes.novo.etapa', ['etapa' => 3]), ['admin_nome' => 'Síndica Exemplo', 'admin_email' => 'sindica@solar.exemplo']);
        $this->get(route('plataforma.clientes.novo', ['etapa' => 4]))->assertOk()->assertSee('Condomínio Solar Ltda')->assertSee('11.222.333/0001-81');

        $this->post(route('plataforma.clientes.novo.concluir'))->assertRedirect();

        $this->contexto()->definir(null, null, true);
        $cliente = Organizacao::query()->where('cnpj', '11222333000181')->firstOrFail();
        $this->assertSame('60115170', $cliente->cep);
        $this->assertSame('8532221100', $cliente->telefone);
        $this->assertSame('financeiro@solar.exemplo', $cliente->email_financeiro);
        $this->assertSame('ativa', $cliente->assinatura->status);

        // Administrador criado no ambiente do cliente, sem senha conhecida por ninguém: só o link.
        Mail::assertSent(LinkSenha::class, fn (LinkSenha $mail): bool => $mail->hasTo('sindica@solar.exemplo') && $mail->contaNova);
        $this->contexto()->definir($cliente->id);
        $this->assertSame(1, (int) DB::connection('pgsql')->scalar("SELECT count(*) FROM usuario_sistema WHERE email = 'sindica@solar.exemplo'"));
        $this->assertSame(4, (int) DB::connection('pgsql')->scalar('SELECT count(*) FROM perfil_vinculo'));
    }

    public function test_assistente_valida_cnpj_e_endereco(): void
    {
        $this->entrarComoPlataforma();

        $this->post(route('plataforma.clientes.novo.etapa', ['etapa' => 1]), ['nome' => 'X', 'cnpj' => '11.111.111/1111-11'])
            ->assertSessionHasErrors(['cnpj' => 'CNPJ inválido. Confira os dígitos.', 'cep', 'logradouro', 'telefone', 'email_financeiro']);
        $this->get(route('plataforma.clientes.novo', ['etapa' => 3]))->assertRedirect(route('plataforma.clientes.novo', ['etapa' => 1]));
    }

    public function test_desativar_exige_digitar_o_nome_e_bloqueia_o_login_do_cliente(): void
    {
        $this->entrarComoPlataforma();
        [$cliente] = $this->clienteComAdmin('admin@cliente.exemplo');

        $this->post(route('plataforma.clientes.situacao', $cliente), ['ativo' => '0', 'confirmacao_texto' => 'outro nome'])
            ->assertSessionHasErrors('confirmacao_texto');
        $this->post(route('plataforma.clientes.situacao', $cliente), ['ativo' => '0', 'confirmacao_texto' => 'Cliente Teste Ltda'])
            ->assertRedirect(route('plataforma.clientes.show', $cliente));

        $this->post(route('sair'));
        $this->from(route('entrar'))->post(route('entrar'), ['email' => 'admin@cliente.exemplo', 'senha' => self::SENHA])
            ->assertSessionHas('erro_login');
        $this->assertGuest();
    }

    public function test_cliente_nao_acessa_a_plataforma_e_a_plataforma_nao_acessa_a_assinatura(): void
    {
        $this->clienteComAdmin('admin@cliente.exemplo');
        $this->post(route('entrar'), ['email' => 'admin@cliente.exemplo', 'senha' => self::SENHA]);
        $this->get(route('plataforma.clientes.index'))->assertForbidden();
        $this->get(route('assinatura.index'))->assertOk()->assertSee('Minha assinatura');
        $this->post(route('sair'));

        $this->entrarComoPlataforma();
        $this->get(route('assinatura.index'))->assertForbidden();
        $this->get(route('plataforma.clientes.index'))->assertOk()->assertSee('Cliente Teste Ltda');
    }

    public function test_cliente_troca_de_plano_e_atualiza_os_dados_da_empresa_sem_mexer_na_razao_social(): void
    {
        [$cliente] = $this->clienteComAdmin('admin@cliente.exemplo');
        $this->post(route('entrar'), ['email' => 'admin@cliente.exemplo', 'senha' => self::SENHA]);
        $empresarial = (string) DB::connection('pgsql')->scalar("SELECT id FROM plano WHERE codigo = 'empresarial'");

        $this->post(route('assinatura.plano'), ['plano_id' => $empresarial])->assertRedirect(route('assinatura.index'));
        $this->put(route('assinatura.empresa'), ['nome' => 'Nome Trocado', 'cnpj' => '00000000000000'] + self::CADASTRO)
            ->assertRedirect(route('assinatura.index'));

        $this->contexto()->definir(null, null, true);
        $cliente = Organizacao::query()->findOrFail($cliente);
        $this->assertSame('Cliente Teste Ltda', $cliente->nome, 'Razão social só a plataforma altera.');
        $this->assertSame('60115170', $cliente->cep);
        $this->assertSame('empresarial', $cliente->assinatura->plano->codigo);
    }

    public function test_limite_do_plano_bloqueia_o_cadastro_e_avisa_os_administradores(): void
    {
        Mail::fake();
        [$cliente] = $this->clienteComAdmin('admin@cliente.exemplo', 'basico');
        $this->contexto()->definir($cliente);

        $empresa = (string) DB::connection('pgsql')->scalar(<<<'SQL'
            INSERT INTO empresa (razao_social, cnpj, cep, logradouro, numero, bairro, municipio, uf, responsavel_nome, responsavel_email, responsavel_telefone)
            VALUES ('Empresa Teste', '11222333000181', '60000000', 'Rua A', '1', 'Centro', 'Fortaleza', 'CE', 'Resp', 'resp@exemplo.com', '85999990000')
            RETURNING id
            SQL);

        for ($i = 0; $i < 2; $i++) {
            DB::connection('pgsql')->insert(
                'INSERT INTO catraca (empresa_id, nome, identificador_dispositivo, token_hash) VALUES (?, ?, ?, ?)',
                [$empresa, 'Portaria '.$i, 'DISP-'.$i, hash('sha256', 'token'.$i)],
            );
        }

        $limites = app(LimitesPlano::class);
        $this->assertNotEmpty($limites->avisos(), 'Aviso na tela ao chegar no limite.');

        try {
            $limites->garantirCapacidade('catracas');
            $this->fail('Deveria bloquear a terceira catraca no plano Básico.');
        } catch (ValidationException $erro) {
            $this->assertStringContainsString('limite foi atingido', $erro->errors()['plano'][0]);
        }

        Mail::assertSent(LimiteDoPlano::class, fn (LimiteDoPlano $mail): bool => $mail->hasTo('admin@cliente.exemplo'));
    }

    public function test_regua_de_atraso_somente_leitura_recusa_alteracoes_no_servidor(): void
    {
        [$cliente] = $this->clienteComAdmin('admin@cliente.exemplo');
        $this->contexto()->definir(null, null, true);
        DB::connection('pgsql')->update(
            "UPDATE assinatura SET status = 'inadimplente', inadimplente_desde = current_date - 10 WHERE organizacao_id = ?",
            [$cliente],
        );
        $this->contexto()->limpar();

        $this->post(route('entrar'), ['email' => 'admin@cliente.exemplo', 'senha' => self::SENHA]);
        $this->get(route('inicio'))->assertOk()->assertSee('somente leitura');
        $this->post(route('senha.trocar'), [])->assertSessionHasErrors(); // trocar senha continua liberado
        $this->put(route('assinatura.empresa'), self::CADASTRO)->assertRedirect(route('assinatura.index')); // regularizar também
    }

    public function test_plataforma_troca_plano_com_motivo_e_o_historico_nao_mostra_dados_pessoais(): void
    {
        $this->entrarComoPlataforma();
        [$cliente] = $this->clienteComAdmin('admin@cliente.exemplo');
        $empresarial = (string) DB::connection('pgsql')->scalar("SELECT id FROM plano WHERE codigo = 'empresarial'");

        $this->post(route('plataforma.clientes.plano', $cliente), ['plano_id' => $empresarial, 'motivo' => ''])->assertSessionHasErrors('motivo');
        $this->post(route('plataforma.clientes.plano', $cliente), ['plano_id' => $empresarial, 'motivo' => 'Upgrade negociado'])
            ->assertRedirect(route('plataforma.clientes.show', [$cliente, 'plano']));
        $this->post(route('plataforma.clientes.notas', $cliente), ['texto' => 'Pediu orçamento de mais catracas.']);

        $this->get(route('plataforma.clientes.show', [$cliente, 'historico']))->assertOk()
            ->assertSee('Plano alterado: profissional → empresarial')
            ->assertSee('Motivo: Upgrade negociado')
            ->assertSee('Pediu orçamento de mais catracas.')
            ->assertDontSee('admin@cliente.exemplo');
    }

    private function entrarComoPlataforma(): void
    {
        $this->contexto()->definir(null, null, true);
        $id = (string) DB::connection('pgsql')->scalar(
            'INSERT INTO usuario_sistema (organizacao_id, nome, email, senha_hash) VALUES (NULL, ?, ?, ?) RETURNING id',
            ['Equipe', 'equipe@plataforma.exemplo', Hash::make(self::SENHA)],
        );
        DB::connection('pgsql')->insert("INSERT INTO usuario_papel (usuario_id, papel) VALUES (?, 'plataforma')", [$id]);
        $this->contexto()->limpar();
        $this->post(route('entrar'), ['email' => 'equipe@plataforma.exemplo', 'senha' => self::SENHA]);
    }

    /** @return array{0: string, 1: string} cliente e administrador */
    private function clienteComAdmin(string $email, string $plano = 'profissional'): array
    {
        $cliente = $this->criarCliente('Cliente Teste Ltda', $plano);
        $this->contexto()->definir($cliente);
        $admin = (string) DB::connection('pgsql')->scalar(
            'INSERT INTO usuario_sistema (nome, email, senha_hash) VALUES (?, ?, ?) RETURNING id',
            ['Admin Teste', $email, Hash::make(self::SENHA)],
        );
        DB::connection('pgsql')->insert('INSERT INTO usuario_papel (usuario_id, papel) VALUES (?, ?)', [$admin, Papel::Administrador->value]);
        $this->contexto()->limpar();

        return [$cliente, $admin];
    }
}
