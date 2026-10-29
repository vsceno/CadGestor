<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Alerta;
use App\Models\Catraca;
use App\Models\Empresa;
use App\Models\EventoAcesso;
use App\Models\PerfilVinculo;
use App\Models\Pessoa;
use App\Models\Vinculo;
use App\Services\Acesso\DecisaoAcesso;
use App\Services\Biometria\CifradorBiometria;
use App\Services\Cadastro\CatracaServico;
use App\Services\Cadastro\EmpresaServico;
use App\Services\Cadastro\PessoaServico;
use App\Services\Cadastro\RegraAcessoServico;
use App\Services\Cadastro\VinculoServico;
use App\Services\Lgpd\BiometriaServico;
use App\Services\Lgpd\ConsentimentoServico;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Acesso nas catracas: a decisão (vínculo > perfil > horário padrão), a API dos
 * dispositivos (token, heartbeat, acesso, sincronização e eventos offline) e a
 * portaria ao vivo. O relógio fica fixo numa segunda-feira, 10h, em Fortaleza.
 */
class AcessoDispositivoTest extends TestCase
{
    private const SENHA = 'frase de teste bem longa';

    private const TEMPLATE = 'template-biometrico-de-teste-0001';

    protected function setUp(): void
    {
        parent::setUp();

        // Chave de teste: a suíte não depende do arquivo real de chaves.
        $this->app->instance(CifradorBiometria::class, new CifradorBiometria([1 => random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES)]));
        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00', 'America/Fortaleza'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_horario_padrao_libera_no_horario_e_nega_fora_dele(): void
    {
        $cenario = $this->cenario();

        $this->assertLiberado($cenario);

        Carbon::setTestNow(Carbon::parse('2026-10-26 20:00:00', 'America/Fortaleza'));
        $this->assertNegado($cenario, 'fora_do_horario');

        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'America/Fortaleza'));
        $this->assertNegado($cenario, 'fora_do_horario');
    }

    public function test_regra_do_perfil_substitui_o_horario_padrao(): void
    {
        $cenario = $this->cenario();
        app(RegraAcessoServico::class)->salvar($cenario['empresa'], $cenario['perfil'], [
            ['dia' => '5', 'inicio' => '08:00', 'fim' => '12:00', 'catraca' => ''],
        ]);

        // Segunda às 10h: o perfil tem regra própria (só sexta), então o horário padrão não vale.
        $this->assertNegado($cenario, 'fora_do_horario');

        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00:00', 'America/Fortaleza'));
        $this->assertLiberado($cenario);
    }

    public function test_regra_so_de_outra_catraca_nega_por_perfil_nao_permitido(): void
    {
        $cenario = $this->cenario();
        $outra = app(CatracaServico::class)->criar([
            'empresa_id' => $cenario['empresa']->id,
            'nome' => 'Refeitório',
            'identificador_dispositivo' => 'CAT-REFEITORIO',
            'tipo_identificacao' => 'digital',
            'modo_falha' => 'bloquear',
        ])['catraca'];
        app(RegraAcessoServico::class)->salvar($cenario['empresa'], $cenario['perfil'], [
            ['dia' => '2', 'inicio' => '08:00', 'fim' => '18:00', 'catraca' => $outra->id],
        ]);

        $this->assertNegado($cenario, 'perfil_nao_permitido');
    }

    public function test_vinculo_suspenso_nao_libera(): void
    {
        $cenario = $this->cenario();
        app(VinculoServico::class)->suspender($cenario['vinculo']);

        $this->assertNegado($cenario, 'sem_vinculo_ativo');
    }

    public function test_pessoa_desativada_nao_passa(): void
    {
        $cenario = $this->cenario();
        app(PessoaServico::class)->definirAtivo($cenario['pessoa'], false);

        $this->assertNegado($cenario, 'pessoa_inativa');
    }

    public function test_sem_biometria_ou_com_biometria_desconhecida(): void
    {
        $cenario = $this->cenario();

        $this->assertSame('pessoa_nao_identificada', app(DecisaoAcesso::class)->decidir($cenario['catraca'], null, now())->motivo);
        $this->assertSame('biometria_nao_confere', app(DecisaoAcesso::class)->decidir($cenario['catraca'], '01a18753-0000-7000-8000-000000000000', now())->motivo);
    }

    public function test_catraca_desativada_nega(): void
    {
        $cenario = $this->cenario();
        app(CatracaServico::class)->alterarSituacao($cenario['catraca'], false);

        $this->assertNegado($cenario, 'catraca_inativa');
    }

    public function test_token_ausente_ou_invalido_recebe_401(): void
    {
        $this->postJson('/api/catracas/v1/heartbeat')->assertStatus(401)->assertJson(['erro' => 'token_invalido']);
        $this->withHeader('Authorization', 'Bearer cgt_'.str_repeat('0', 63))
            ->postJson('/api/catracas/v1/heartbeat')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer token-qualquer')
            ->postJson('/api/catracas/v1/heartbeat')->assertStatus(401);
    }

    public function test_heartbeat_com_token_atualiza_o_contato_e_informa_o_modo_de_falha(): void
    {
        $cenario = $this->cenario();

        $this->comToken($cenario['token'])->postJson('/api/catracas/v1/heartbeat')
            ->assertOk()
            ->assertJsonPath('modo_falha', 'bloquear')
            ->assertJsonPath('ativa', true);

        $this->contexto()->definir($cenario['cliente']);
        $this->assertNotNull(Catraca::query()->findOrFail($cenario['catraca']->id)->ultimo_contato_em);
    }

    public function test_acesso_pela_api_libera_registra_o_evento_e_responde_abrir(): void
    {
        $cenario = $this->cenario();

        $this->comToken($cenario['token'])->postJson('/api/catracas/v1/acessos', [
            'biometria_id' => $cenario['biometria'],
            'tipo_identificacao' => 'digital',
        ])->assertOk()->assertJson(['resultado' => 'liberado', 'abrir' => true]);

        $this->contexto()->definir($cenario['cliente']);
        $evento = EventoAcesso::query()->where('catraca_id', $cenario['catraca']->id)->firstOrFail();
        $this->assertSame('liberado', $evento->resultado);
        $this->assertSame($cenario['pessoa']->id, $evento->pessoa_id);
    }

    public function test_negacoes_repetidas_geram_um_so_alerta_na_janela(): void
    {
        $cenario = $this->cenario();
        app(PessoaServico::class)->definirAtivo($cenario['pessoa'], false);

        $this->comToken($cenario['token'])->postJson('/api/catracas/v1/acessos', [
            'biometria_id' => $cenario['biometria'], 'tipo_identificacao' => 'digital',
        ])->assertJson(['resultado' => 'negado', 'abrir' => false]);
        $this->comToken($cenario['token'])->postJson('/api/catracas/v1/acessos', [
            'biometria_id' => $cenario['biometria'], 'tipo_identificacao' => 'digital',
        ]);

        $this->contexto()->definir($cenario['cliente']);
        $this->assertSame(2, EventoAcesso::query()->where('catraca_id', $cenario['catraca']->id)->count());
        $this->assertSame(1, Alerta::query()->where('tipo', 'acesso_negado')->count());
    }

    public function test_cadastro_de_biometria_chega_a_catraca_com_o_template_e_o_resultado_fecha_a_operacao(): void
    {
        $cenario = $this->cenario();

        // A biometria foi cadastrada no cenário: a operação de envio já está na fila da catraca.
        $resposta = $this->comToken($cenario['token'])->getJson('/api/catracas/v1/sincronizacao')->assertOk();
        $operacao = $resposta->json('operacoes.0');

        $this->assertSame('cadastrar', $operacao['operacao']);
        $this->assertSame(self::TEMPLATE, base64_decode($operacao['template']));

        $this->comToken($cenario['token'])
            ->postJson("/api/catracas/v1/sincronizacao/{$operacao['id']}/resultado", ['sucesso' => true])
            ->assertOk();

        $this->contexto()->definir($cenario['cliente']);
        $this->assertSame('concluido', DB::connection('pgsql')->scalar('SELECT status FROM fila_sincronizacao WHERE id = ?', [$operacao['id']]));
    }

    public function test_resultado_de_outra_catraca_nao_fecha_a_operacao(): void
    {
        $cenario = $this->cenario();
        $operacao = $this->comToken($cenario['token'])->getJson('/api/catracas/v1/sincronizacao')->json('operacoes.0');
        $this->contexto()->definir($cenario['cliente']);
        $outra = app(CatracaServico::class)->criar([
            'empresa_id' => $cenario['empresa']->id, 'nome' => 'Outra', 'identificador_dispositivo' => 'CAT-OUTRA-2',
            'tipo_identificacao' => 'digital', 'modo_falha' => 'bloquear',
        ]);

        $this->comToken($outra['token'])
            ->postJson("/api/catracas/v1/sincronizacao/{$operacao['id']}/resultado", ['sucesso' => true])
            ->assertNotFound();
    }

    public function test_eventos_offline_sao_gravados_como_offline(): void
    {
        $cenario = $this->cenario();

        $this->comToken($cenario['token'])->postJson('/api/catracas/v1/eventos-offline', [
            'eventos' => [
                ['data_hora' => '2026-10-26T09:10:00-03:00', 'tipo_identificacao' => 'digital', 'resultado' => 'liberado', 'biometria_id' => $cenario['biometria']],
                ['data_hora' => '2026-10-26T09:12:00-03:00', 'tipo_identificacao' => 'facial', 'resultado' => 'negado', 'motivo' => 'biometria_nao_confere'],
            ],
        ])->assertOk()->assertJson(['recebidos' => 2]);

        $this->contexto()->definir($cenario['cliente']);
        $this->assertSame(2, EventoAcesso::query()->where('offline', true)->count());
        $this->assertSame($cenario['pessoa']->id, EventoAcesso::query()->where('resultado', 'liberado')->value('pessoa_id'));
    }

    public function test_portaria_mostra_as_passagens_para_quem_so_consulta(): void
    {
        $cenario = $this->cenario();
        $this->comToken($cenario['token'])->postJson('/api/catracas/v1/acessos', [
            'biometria_id' => $cenario['biometria'], 'tipo_identificacao' => 'digital',
        ]);

        $this->contexto()->limpar();
        $this->entrarComo($cenario['cliente'], [Papel::Consulta]);

        $this->get(route('portaria'))->assertOk()
            ->assertSee('Portaria principal')
            ->assertSee('Ana Lima Souza')
            ->assertSee('Liberado');
    }

    /**
     * Cenário padrão: cliente, empresa com o perfil Funcionário e horário padrão de
     * segunda das 8h às 18h, catraca, pessoa com consentimento, biometria e vínculo.
     *
     * @return array{cliente: string, empresa: Empresa, perfil: PerfilVinculo, catraca: Catraca, token: string, pessoa: Pessoa, vinculo: Vinculo, biometria: string}
     */
    private function cenario(): array
    {
        $cliente = $this->criarCliente();
        $this->contexto()->definir($cliente);

        $empresa = app(EmpresaServico::class)->criar([
            'razao_social' => 'Clínica Acesso Ltda', 'cnpj' => '11.222.333/0001-81', 'cep' => '01310-100',
            'logradouro' => 'Avenida Paulista', 'numero' => '1000', 'bairro' => 'Bela Vista',
            'municipio' => 'São Paulo', 'uf' => 'SP', 'responsavel_nome' => 'Ana Responsável',
            'responsavel_email' => 'responsavel@clinica.exemplo', 'responsavel_telefone' => '(11) 98888-7777',
        ]);
        $perfil = PerfilVinculo::query()->where('nome', 'Funcionário')->firstOrFail();
        app(EmpresaServico::class)->definirPerfis($empresa, [$perfil->id]);
        app(EmpresaServico::class)->definirHorarios($empresa, ['1'], ['08:00'], ['18:00']);

        ['catraca' => $catraca, 'token' => $token] = app(CatracaServico::class)->criar([
            'empresa_id' => $empresa->id, 'nome' => 'Portaria principal', 'identificador_dispositivo' => 'CAT-PORTARIA-1',
            'tipo_identificacao' => 'digital_facial', 'modo_falha' => 'bloquear',
        ]);

        $pessoa = app(PessoaServico::class)->criar(['nome' => 'Ana Lima Souza', 'cpf' => $this->cpf(40), 'data_nascimento' => '1990-05-10']);
        $vinculo = app(VinculoServico::class)->criar($pessoa, [
            'empresa_perfil' => $empresa->id.'|'.$perfil->id, 'data_inicio' => '2026-10-01',
        ]);
        app(ConsentimentoServico::class)->registrar($pessoa, ['canal' => 'presencial', 'aceite_titular' => '1'], '127.0.0.1');
        $biometria = app(BiometriaServico::class)->cadastrar($pessoa, 'digital', 2, self::TEMPLATE);

        return compact('cliente', 'empresa', 'perfil', 'catraca', 'token', 'pessoa', 'vinculo', 'biometria');
    }

    /** @param  array{cliente: string, catraca: Catraca, pessoa: Pessoa, biometria: string}  $cenario */
    private function assertLiberado(array $cenario): void
    {
        $this->assertTrue(app(DecisaoAcesso::class)->decidir($cenario['catraca'], $cenario['biometria'], now())->liberou());
    }

    /** @param  array{cliente: string, catraca: Catraca, biometria: string}  $cenario */
    private function assertNegado(array $cenario, string $motivo): void
    {
        $resultado = app(DecisaoAcesso::class)->decidir($cenario['catraca'], $cenario['biometria'], now());
        $this->assertSame($motivo, $resultado->motivo);
    }

    private function comToken(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    /** @param  list<Papel>  $papeis */
    private function entrarComo(string $cliente, array $papeis): void
    {
        $this->contexto()->definir($cliente);
        $id = (string) DB::connection('pgsql')->scalar(
            'INSERT INTO usuario_sistema (nome, email, senha_hash) VALUES (?, ?, ?) RETURNING id',
            ['Usuária Teste', 'ana@cliente.exemplo', Hash::make(self::SENHA)],
        );

        foreach ($papeis as $papel) {
            DB::connection('pgsql')->insert('INSERT INTO usuario_papel (usuario_id, papel) VALUES (?, ?)', [$id, $papel->value]);
        }

        $this->contexto()->limpar();
        $this->post(route('entrar'), ['email' => 'ana@cliente.exemplo', 'senha' => self::SENHA])->assertRedirect(route('inicio'));
    }

    /** CPF válido gerado a partir de um número (os dígitos verificadores são calculados). */
    private function cpf(int $numero): string
    {
        $base = str_pad((string) $numero, 9, '0', STR_PAD_LEFT);
        $primeiro = $this->digitoCpf($base, 10);
        $segundo = $this->digitoCpf($base.$primeiro, 11);

        return $base.$primeiro.$segundo;
    }

    private function digitoCpf(string $digitos, int $peso): int
    {
        $soma = 0;

        foreach (str_split($digitos) as $digito) {
            $soma += (int) $digito * $peso;
            $peso--;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
