<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Catraca;
use App\Models\Empresa;
use App\Models\PerfilVinculo;
use App\Models\RegraAcesso;
use App\Services\Cadastro\CatracaServico;
use App\Services\Cadastro\EmpresaServico;
use App\Services\Cadastro\PerfilServico;
use App\Services\Cadastro\RegraAcessoServico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Cadastros de acesso: empresas, perfis, catracas e regras. Confere as regras
 * de negócio (CNPJ único, limite de catracas do plano, horários sem sobreposição,
 * perfil aceito pela empresa) e quem pode abrir cada tela.
 */
class CadastrosAcessoTest extends TestCase
{
    private const SENHA = 'frase de teste bem longa';

    private const CNPJ = '11.222.333/0001-81';

    private const CNPJ_OUTRA = '11.444.777/0001-61';

    public function test_empresa_com_cnpj_repetido_no_cliente_e_recusada(): void
    {
        $this->cliente();
        $this->empresa();

        try {
            $this->empresa();
            $this->fail('Um CNPJ repetido devia ser recusado.');
        } catch (ValidationException $erro) {
            $this->assertSame(['Já existe uma empresa com este CNPJ.'], $erro->errors()['cnpj']);
        }
    }

    public function test_cnpj_invalido_e_recusado(): void
    {
        $this->cliente();

        $this->expectException(ValidationException::class);
        $this->empresa('11.222.333/0001-82');
    }

    public function test_empresa_desativada_nao_e_excluida(): void
    {
        $this->cliente();
        $empresa = $this->empresa();

        app(EmpresaServico::class)->alterarSituacao($empresa, false);

        $this->assertFalse(Empresa::query()->findOrFail($empresa->id)->ativo);
    }

    public function test_nome_de_perfil_repetido_ignora_maiusculas(): void
    {
        $this->cliente();
        $servico = app(PerfilServico::class);
        $servico->criar(['nome' => 'Visitante', 'base_legal' => 'consentimento', 'ativo' => true]);

        $this->expectException(ValidationException::class);
        $servico->criar(['nome' => 'VISITANTE', 'base_legal' => 'consentimento']);
    }

    public function test_base_legal_desconhecida_e_recusada(): void
    {
        $this->cliente();

        try {
            app(PerfilServico::class)->criar(['nome' => 'Visitante', 'base_legal' => 'interesse_proprio']);
            $this->fail('Base legal fora da lista devia ser recusada.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('base_legal', $erro->errors());
        }
    }

    public function test_catraca_gera_token_e_guarda_so_o_hash(): void
    {
        $this->cliente();

        ['catraca' => $catraca, 'token' => $token] = app(CatracaServico::class)
            ->criar($this->dadosCatraca($this->empresa(), 'CAT-001'));

        $this->assertMatchesRegularExpression('/^cgt_[0-9a-f]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $this->hashNoBanco($catraca));
    }

    public function test_limite_de_catracas_do_plano_para_cadastro_e_reativacao(): void
    {
        Mail::fake();
        $this->cliente();
        $limite = (int) DB::connection('pgsql')->scalar("SELECT limite_catracas FROM plano WHERE codigo = 'profissional'");
        $empresa = $this->empresa();
        $servico = app(CatracaServico::class);

        $primeira = null;

        for ($i = 1; $i <= $limite; $i++) {
            $resultado = $servico->criar($this->dadosCatraca($empresa, sprintf('CAT-%03d', $i)));
            $primeira ??= $resultado['catraca'];
        }

        try {
            $servico->criar($this->dadosCatraca($empresa, 'CAT-EXTRA'));
            $this->fail('Acima do limite do plano, o cadastro devia parar.');
        } catch (ValidationException $erro) {
            $this->assertStringContainsString('troque de plano', $erro->errors()['plano'][0]);
        }

        $servico->alterarSituacao($primeira, false);
        $servico->criar($this->dadosCatraca($empresa, 'CAT-EXTRA'));

        try {
            $servico->alterarSituacao($primeira, true);
            $this->fail('Reativar sem vaga no plano devia ser recusado.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('plano', $erro->errors());
        }
    }

    public function test_renovar_token_invalida_o_anterior(): void
    {
        $this->cliente();
        $servico = app(CatracaServico::class);
        ['catraca' => $catraca, 'token' => $antigo] = $servico->criar($this->dadosCatraca($this->empresa(), 'CAT-RENOVA'));

        $novo = $servico->renovarToken($catraca);

        $this->assertNotSame($antigo, $novo);
        $this->assertSame(CatracaServico::hashToken($novo), $this->hashNoBanco($catraca));
    }

    public function test_horarios_sobrepostos_no_mesmo_dia_sao_recusados(): void
    {
        $this->expectException(ValidationException::class);

        EmpresaServico::montarIntervalos(['1', '1'], ['08:00', '11:00'], ['12:00', '13:00']);
    }

    public function test_turno_que_termina_a_meia_noite_vai_ate_o_fim_do_dia(): void
    {
        $intervalos = EmpresaServico::montarIntervalos(['7'], ['22:00'], ['00:00']);

        $this->assertSame('24:00:00', $intervalos[0]->fim);
    }

    public function test_regra_so_usa_perfil_aceito_e_catraca_da_propria_empresa(): void
    {
        $this->cliente();
        $empresa = $this->empresa();
        $outra = $this->empresa(self::CNPJ_OUTRA);
        $catracaDaOutra = app(CatracaServico::class)->criar($this->dadosCatraca($outra, 'CAT-OUTRA'))['catraca'];
        app(EmpresaServico::class)->definirPerfis($empresa, [$this->perfil('Funcionário')->id]);
        $regras = app(RegraAcessoServico::class);

        try {
            $regras->salvar($empresa, $this->perfil('Funcionário'), [
                ['dia' => '2', 'inicio' => '08:00', 'fim' => '12:00', 'catraca' => $catracaDaOutra->id],
            ]);
            $this->fail('Catraca de outra empresa devia ser recusada.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('regras', $erro->errors());
        }

        try {
            $regras->salvar($empresa, $this->perfil('Aluno'), [
                ['dia' => '2', 'inicio' => '08:00', 'fim' => '12:00', 'catraca' => ''],
            ]);
            $this->fail('Perfil não aceito pela empresa devia ser recusado.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('perfil', $erro->errors());
        }
    }

    public function test_regras_sobrepostas_sao_recusadas_e_a_gravacao_substitui_as_anteriores(): void
    {
        $this->cliente();
        $empresa = $this->empresa();
        $funcionario = $this->perfil('Funcionário');
        app(EmpresaServico::class)->definirPerfis($empresa, [$funcionario->id]);
        $regras = app(RegraAcessoServico::class);

        try {
            $regras->salvar($empresa, $funcionario, [
                ['dia' => '2', 'inicio' => '08:00', 'fim' => '12:00', 'catraca' => ''],
                ['dia' => '2', 'inicio' => '11:00', 'fim' => '13:00', 'catraca' => ''],
            ]);
            $this->fail('Horários sobrepostos na mesma catraca devem ser recusados.');
        } catch (ValidationException $erro) {
            $this->assertSame(['Há horários sobrepostos na terça-feira.'], $erro->errors()['regras']);
        }

        $regras->salvar($empresa, $funcionario, [
            ['dia' => '2', 'inicio' => '08:00', 'fim' => '12:00', 'catraca' => ''],
            ['dia' => '6', 'inicio' => '08:00', 'fim' => '12:00', 'catraca' => ''],
        ]);
        $this->assertSame(2, RegraAcesso::query()->where('perfil_id', $funcionario->id)->count());

        $regras->salvar($empresa, $funcionario, [['dia' => '3', 'inicio' => '08:00', 'fim' => '12:00', 'catraca' => '']]);
        $this->assertSame(1, RegraAcesso::query()->where('perfil_id', $funcionario->id)->count());
    }

    public function test_consulta_nao_abre_os_cadastros_de_acesso(): void
    {
        $cliente = $this->cliente();
        $this->contexto()->limpar();
        $this->entrarComo($cliente, [Papel::Consulta]);

        $this->get(route('empresas.index'))->assertForbidden();
        $this->get(route('catracas.index'))->assertForbidden();
        $this->get(route('regras.index'))->assertForbidden();
        $this->get(route('perfis.index'))->assertForbidden();
    }

    public function test_cadastro_de_empresa_ve_perfis_mas_nao_os_altera(): void
    {
        $cliente = $this->cliente();
        $this->contexto()->limpar();
        $this->entrarComo($cliente, [Papel::CadastroEmpresa]);

        $this->get(route('perfis.index'))->assertOk()->assertDontSee('Novo perfil');
        $this->get(route('perfis.criar'))->assertForbidden();
    }

    public function test_administrador_cadastra_empresa_e_configura_perfis_e_horario(): void
    {
        $cliente = $this->cliente();
        $funcionarioId = $this->perfil('Funcionário')->id;
        $this->contexto()->limpar();
        $this->entrarComo($cliente, [Papel::Administrador]);

        $this->get(route('empresas.criar'))->assertOk()->assertSee('Nova empresa');
        $url = $this->post(route('empresas.salvar'), $this->dadosEmpresa())->assertRedirect()->headers->get('Location');
        $this->assertMatchesRegularExpression('#/empresas/[0-9a-f-]{36}/configurar$#', $url);
        $this->get($url)->assertOk()->assertSee('Perfis aceitos')->assertSee('Horário padrão');

        $empresaId = $this->idDaUrl($url);
        $this->put(route('empresas.perfis.salvar', $empresaId), ['perfis' => [$funcionarioId]])->assertRedirect();
        $this->put(route('empresas.horarios.salvar', $empresaId), [
            'dias' => ['2'], 'inicios' => ['08:00'], 'fins' => ['12:00'],
        ])->assertRedirect();

        $this->contexto()->definir($cliente);
        $this->assertSame(1, (int) DB::connection('pgsql')->scalar('SELECT count(*) FROM empresa_perfil WHERE empresa_id = ?', [$empresaId]));
        $this->assertSame(1, (int) DB::connection('pgsql')->scalar('SELECT count(*) FROM empresa_horario_padrao WHERE empresa_id = ?', [$empresaId]));
    }

    public function test_token_da_catraca_aparece_uma_unica_vez(): void
    {
        $cliente = $this->cliente();
        $empresaId = $this->empresa()->id;
        $this->contexto()->limpar();
        $this->entrarComo($cliente, [Papel::Administrador]);

        $url = $this->post(route('catracas.salvar'), [
            'empresa_id' => $empresaId,
            'nome' => 'Portaria',
            'localizacao' => '',
            'identificador_dispositivo' => 'CAT-PORTARIA-1',
            'tipo_identificacao' => 'digital_facial',
            'modo_falha' => 'bloquear',
            'fabricante' => '',
            'modelo' => '',
        ])->assertRedirect()->headers->get('Location');

        $html = $this->get($url)->assertOk()->assertSee('Token de conexão')->getContent();
        preg_match('/cgt_[0-9a-f]{64}/', $html, $token);
        $this->assertCount(1, $token);

        $this->get($url)->assertOk()->assertDontSee($token[0]);
    }

    public function test_regras_de_perfil_nao_aceito_pela_empresa_dao_404(): void
    {
        $cliente = $this->cliente();
        $empresa = $this->empresa();
        $aluno = $this->perfil('Aluno');
        $this->contexto()->limpar();
        $this->entrarComo($cliente, [Papel::Administrador]);

        $this->get(route('regras.editar', [$empresa, $aluno]))->assertNotFound();
    }

    /** Cria um cliente provisionado e deixa o contexto de banco apontado para ele. */
    private function cliente(): string
    {
        $cliente = $this->criarCliente();
        $this->contexto()->definir($cliente);

        return $cliente;
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

    private function empresa(string $cnpj = self::CNPJ): Empresa
    {
        return app(EmpresaServico::class)->criar($this->dadosEmpresa(['cnpj' => $cnpj]));
    }

    private function perfil(string $nome): PerfilVinculo
    {
        return PerfilVinculo::query()->where('nome', $nome)->firstOrFail();
    }

    /** @param  array<string, string>  $extra  @return array<string, string> */
    private function dadosEmpresa(array $extra = []): array
    {
        return $extra + [
            'razao_social' => 'Clínica Exemplo Ltda',
            'nome_fantasia' => 'Clínica Exemplo',
            'cnpj' => self::CNPJ,
            'cep' => '01310-100',
            'logradouro' => 'Avenida Paulista',
            'numero' => '1000',
            'complemento' => '',
            'bairro' => 'Bela Vista',
            'municipio' => 'São Paulo',
            'uf' => 'SP',
            'responsavel_nome' => 'Ana Responsável',
            'responsavel_cargo' => '',
            'responsavel_email' => 'responsavel@clinica.exemplo',
            'responsavel_telefone' => '(11) 98888-7777',
        ];
    }

    /** @return array<string, string> */
    private function dadosCatraca(Empresa $empresa, string $identificador): array
    {
        return [
            'empresa_id' => $empresa->id,
            'nome' => 'Portaria',
            'localizacao' => '',
            'identificador_dispositivo' => $identificador,
            'tipo_identificacao' => 'digital_facial',
            'modo_falha' => 'bloquear',
            'fabricante' => '',
            'modelo' => '',
        ];
    }

    private function hashNoBanco(Catraca $catraca): string
    {
        return (string) DB::connection('pgsql')->scalar('SELECT token_hash FROM catraca WHERE id = ?', [$catraca->id]);
    }

    private function idDaUrl(string $url): string
    {
        preg_match('#/empresas/([0-9a-f-]{36})/#', $url, $encontrado);

        return $encontrado[1];
    }
}
