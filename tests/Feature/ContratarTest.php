<?php

namespace Tests\Feature;

use App\Mail\ConfirmacaoEmail;
use App\Services\LimitadorTentativas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Contratação pelo site: teste grátis de 14 dias, confirmação de e-mail antes
 * do primeiro acesso, validações e limite de cadastros por rede.
 */
class ContratarTest extends TestCase
{
    private const SENHA = 'frase de teste bem longa';

    private const CNPJ = '45.723.174/0001-10';

    private const EMAIL = 'diretora@escola.exemplo';

    /**
     * @param  array<string, string>  $troca
     * @return array<string, string>
     */
    private function dadosCadastro(array $troca = []): array
    {
        return $troca + [
            'plano' => 'profissional',
            'razao_social' => 'Escola Fictícia Aprender Ltda',
            'cnpj' => self::CNPJ,
            'telefone' => '(85) 3333-4444',
            'responsavel_nome' => 'Diretora Fictícia',
            'email' => self::EMAIL,
            'senha' => self::SENHA,
            'senha_confirmation' => self::SENHA,
            'aceite' => '1',
        ];
    }

    private function tokenDoEmail(): string
    {
        $token = '';

        Mail::assertSent(ConfirmacaoEmail::class, function (ConfirmacaoEmail $mail) use (&$token): bool {
            parse_str((string) parse_url($mail->link, PHP_URL_QUERY), $query);
            $token = $query['token'];

            return true;
        });

        return $token;
    }

    public function test_formulario_mostra_os_planos_e_o_teste_gratis(): void
    {
        $this->get(route('contratar'))
            ->assertOk()
            ->assertSee('14 dias grátis')
            ->assertSee('Profissional')
            ->assertSee('Básico')
            ->assertSee('Empresarial');
    }

    public function test_cadastro_cria_cliente_em_teste_com_primeira_fatura_e_pede_a_confirmacao(): void
    {
        Mail::fake();

        $this->post(route('contratar'), $this->dadosCadastro())->assertRedirect(route('contratar.enviado'));

        $this->contexto()->definir(null, null, true);
        $cliente = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT o.id, o.termos_versao, a.status, a.teste_ate::text AS teste_ate
              FROM organizacao o JOIN assinatura a ON a.organizacao_id = o.id
             WHERE o.cnpj = '45723174000110'
            SQL);

        $this->assertNotNull($cliente);
        $this->assertSame('teste', $cliente->status);
        $this->assertSame(now()->startOfDay()->addDays(14)->toDateString(), $cliente->teste_ate);
        $this->assertSame('1.0-modelo', $cliente->termos_versao);

        $fatura = DB::connection('pgsql')->selectOne(
            'SELECT vencimento::text AS vencimento, status FROM fatura WHERE organizacao_id = ?',
            [$cliente->id],
        );
        $this->assertSame($cliente->teste_ate, now()->startOfDay()->addDays(14)->toDateString());
        $this->assertSame(now()->startOfDay()->addDays(15)->toDateString(), $fatura->vencimento);
        $this->assertSame('pendente', $fatura->status);

        $administrador = DB::connection('pgsql')->selectOne(
            'SELECT u.email_verificado_em FROM usuario_sistema u WHERE u.email = ?',
            [self::EMAIL],
        );
        $this->assertNull($administrador->email_verificado_em, 'O e-mail ainda não foi confirmado.');

        Mail::assertSent(ConfirmacaoEmail::class, fn (ConfirmacaoEmail $mail): bool => $mail->hasTo(self::EMAIL));
    }

    public function test_cliente_nao_entra_antes_de_confirmar_o_email_e_entra_depois(): void
    {
        Mail::fake();
        $this->post(route('contratar'), $this->dadosCadastro())->assertRedirect(route('contratar.enviado'));
        $token = $this->tokenDoEmail();

        $this->post(route('entrar'), ['email' => self::EMAIL, 'senha' => self::SENHA])
            ->assertSessionHas('erro_login', 'Confirme seu e-mail pelo link que enviamos antes de entrar.');
        $this->assertGuest();

        $this->get(route('email.confirmar', ['token' => $token]))->assertOk()->assertSee('E-mail confirmado');
        $this->get(route('email.confirmar', ['token' => $token]))->assertSee('Link inválido');

        $this->post(route('entrar'), ['email' => self::EMAIL, 'senha' => self::SENHA])
            ->assertRedirect(route('inicio'));
        $this->assertAuthenticated();
        $this->get(route('inicio'))->assertOk()->assertSee('Primeiros passos');
    }

    public function test_recusa_cnpj_invalido_cnpj_repetido_e_falta_de_aceite(): void
    {
        Mail::fake();

        $this->post(route('contratar'), $this->dadosCadastro(['cnpj' => '11.111.111/1111-11']))
            ->assertSessionHasErrors(['cnpj' => 'CNPJ inválido. Confira os dígitos.']);

        $this->post(route('contratar'), $this->dadosCadastro(['aceite' => '']))
            ->assertSessionHasErrors('aceite');

        $this->post(route('contratar'), $this->dadosCadastro())->assertRedirect(route('contratar.enviado'));

        $this->post(route('contratar'), $this->dadosCadastro(['email' => 'outra@escola.exemplo']))
            ->assertSessionHasErrors(['cnpj' => 'Este CNPJ já tem cadastro. Entre com o e-mail do administrador ou fale com o comercial.']);
    }

    public function test_limite_de_cadastros_por_rede_por_hora(): void
    {
        Mail::fake();

        for ($i = 0; $i < 5; $i++) {
            app(LimitadorTentativas::class)->registrarUso('cadastro_cliente', '127.0.0.1', '127.0.0.1');
        }

        $this->post(route('contratar'), $this->dadosCadastro())
            ->assertSessionHas('erro', 'Muitos cadastros a partir desta rede. Tente de novo mais tarde.');
        Mail::assertNothingSent();
    }

    public function test_documentos_juridicos_sao_modelos_para_revisao(): void
    {
        foreach (['documentos.termos', 'documentos.privacidade', 'documentos.contrato-dados'] as $rota) {
            $this->get(route($rota))->assertOk()->assertSee('MODELO para revisão jurídica');
        }
    }
}
