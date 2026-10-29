<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Empresa;
use App\Models\PerfilVinculo;
use App\Models\Pessoa;
use App\Models\Vinculo;
use App\Services\Cadastro\EmpresaServico;
use App\Services\Cadastro\PessoaServico;
use App\Services\Cadastro\VinculoServico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Pessoas e vínculos: CPF único por cliente e mascarado nas telas, limite de
 * pessoas do plano, vínculo só com empresa e perfil aceitos, validade, encerramento
 * que fica no histórico e auditoria sem dados pessoais.
 */
class PessoasVinculosTest extends TestCase
{
    private const SENHA = 'frase de teste bem longa';

    public function test_cpf_invalido_e_recusado(): void
    {
        $this->cliente();

        try {
            $this->pessoa('123.456.789-00');
            $this->fail('CPF inválido devia ser recusado.');
        } catch (ValidationException $erro) {
            $this->assertSame(['CPF inválido.'], $erro->errors()['cpf']);
        }
    }

    public function test_cpf_repetido_no_mesmo_cliente_e_recusado_mas_aceito_em_outro(): void
    {
        $this->cliente();
        $this->pessoa($this->cpf(10));

        try {
            $this->pessoa($this->cpf(10), 'Outra Pessoa');
            $this->fail('Mesmo CPF no mesmo cliente devia ser recusado.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('cpf', $erro->errors());
        }

        // Outro cliente pode ter a mesma pessoa: o CPF é único por cliente, não no banco inteiro.
        $this->cliente('profissional', 'Outro cliente de teste');
        $this->assertNotNull($this->pessoa($this->cpf(10), 'Maria Aparecida Costa'));
    }

    public function test_nome_precisa_ter_sobrenome(): void
    {
        $this->cliente();

        try {
            $this->pessoa($this->cpf(11), 'Maria');
            $this->fail('Nome sem sobrenome devia ser recusado.');
        } catch (ValidationException $erro) {
            $this->assertSame(['Informe o nome completo (nome e sobrenome).'], $erro->errors()['nome']);
        }
    }

    public function test_limite_de_pessoas_do_plano_para_cadastro_e_reativacao(): void
    {
        Mail::fake();
        $this->cliente('basico');
        $servico = app(PessoaServico::class);
        $limite = (int) DB::connection('pgsql')->scalar("SELECT limite_pessoas FROM plano WHERE codigo = 'basico'");

        $primeira = null;

        for ($i = 1; $i <= $limite; $i++) {
            $pessoa = $this->pessoa($this->cpf($i), 'Pessoa Número '.$i);
            $primeira ??= $pessoa;
        }

        try {
            $this->pessoa($this->cpf($limite + 1), 'Pessoa Excedente');
            $this->fail('Acima do limite do plano, o cadastro devia parar.');
        } catch (ValidationException $erro) {
            $this->assertStringContainsString('troque de plano', $erro->errors()['plano'][0]);
        }

        $servico->definirAtivo($primeira, false);
        $this->pessoa($this->cpf($limite + 1), 'Pessoa Excedente');

        try {
            $servico->definirAtivo($primeira, true);
            $this->fail('Reativar sem vaga no plano devia ser recusado.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('plano', $erro->errors());
        }
    }

    public function test_vinculo_so_com_empresa_e_perfil_que_a_empresa_aceita(): void
    {
        $this->cliente();
        [$empresa] = $this->empresaComPerfil('Funcionário');
        $aluno = $this->perfil('Aluno');
        $pessoa = $this->pessoa($this->cpf(20));

        try {
            $this->vinculo($pessoa, $empresa, $aluno);
            $this->fail('Perfil que a empresa não aceita devia ser recusado.');
        } catch (ValidationException $erro) {
            $this->assertSame(['Selecione uma empresa ativa e um perfil aceito por ela.'], $erro->errors()['empresa_perfil']);
        }
    }

    public function test_vinculo_ativo_repetido_e_recusado_mas_outro_perfil_na_mesma_empresa_e_permitido(): void
    {
        $this->cliente();
        [$empresa, $funcionario] = $this->empresaComPerfil('Funcionário');
        $aluno = $this->perfil('Aluno');
        app(EmpresaServico::class)->definirPerfis($empresa, [$funcionario->id, $aluno->id]);
        $pessoa = $this->pessoa($this->cpf(21));

        $this->vinculo($pessoa, $empresa, $funcionario);

        try {
            $this->vinculo($pessoa, $empresa, $funcionario);
            $this->fail('Dois vínculos ativos iguais devem ser recusados.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('empresa_perfil', $erro->errors());
        }

        $this->assertNotNull($this->vinculo($pessoa, $empresa, $aluno));
    }

    public function test_validade_antes_do_inicio_e_recusada(): void
    {
        $this->cliente();
        [$empresa, $funcionario] = $this->empresaComPerfil('Funcionário');
        $pessoa = $this->pessoa($this->cpf(22));

        try {
            $this->vinculo($pessoa, $empresa, $funcionario, '2026-10-10', '2026-10-01');
            $this->fail('Validade antes do início devia ser recusada.');
        } catch (ValidationException $erro) {
            $this->assertSame(['A validade deve ser igual ou posterior ao início.'], $erro->errors()['data_fim']);
        }
    }

    public function test_encerrar_guarda_o_historico_e_nao_permite_mais_alteracoes(): void
    {
        $this->cliente();
        [$empresa, $funcionario] = $this->empresaComPerfil('Funcionário');
        $pessoa = $this->pessoa($this->cpf(23));
        $vinculo = $this->vinculo($pessoa, $empresa, $funcionario);
        $servico = app(VinculoServico::class);

        $servico->encerrar($vinculo, ['motivo' => 'Fim do contrato']);
        $vinculo->refresh();

        $this->assertSame('encerrado', $vinculo->status);
        $this->assertSame('Fim do contrato', $vinculo->motivo_encerramento);
        $this->assertNotNull($vinculo->encerrado_em);
        $this->assertNotNull($vinculo->data_fim);

        try {
            $servico->suspender($vinculo);
            $this->fail('Vínculo encerrado não pode mudar de situação.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('status', $erro->errors());
        }
    }

    public function test_suspender_e_reativar_seguem_a_ordem(): void
    {
        $this->cliente();
        [$empresa, $funcionario] = $this->empresaComPerfil('Funcionário');
        $vinculo = $this->vinculo($this->pessoa($this->cpf(24)), $empresa, $funcionario);
        $servico = app(VinculoServico::class);

        $servico->suspender($vinculo);
        $this->assertSame('suspenso', $vinculo->refresh()->status);

        try {
            $servico->suspender($vinculo);
            $this->fail('Suspender um vínculo já suspenso devia ser recusado.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('status', $erro->errors());
        }

        $servico->reativar($vinculo);
        $this->assertSame('ativo', $vinculo->refresh()->status);
    }

    public function test_auditoria_do_cadastro_nao_guarda_dados_pessoais(): void
    {
        $this->cliente();
        $cpf = $this->cpf(25);
        $pessoa = $this->pessoa($cpf, 'Joana Pereira Lima');

        $depois = DB::connection('pgsql')->scalar(
            "SELECT dados_depois::text FROM auditoria WHERE tabela = 'pessoa' AND registro_id = ? AND acao = 'criar'",
            [$pessoa->id],
        );

        $this->assertSame(['cadastro' => true], json_decode((string) $depois, true));
        $this->assertStringNotContainsString($cpf, (string) $depois);
        $this->assertStringNotContainsString('Joana', (string) $depois);
    }

    public function test_cadastro_de_pessoa_mostra_cpf_mascarado_e_busca_por_post(): void
    {
        $cliente = $this->cliente();
        $cpf = $this->cpf(26);
        $pessoa = $this->pessoa($cpf, 'Maria Aparecida Costa');
        $this->contexto()->limpar();
        $this->entrarComo($cliente, [Papel::CadastroPessoa]);

        $this->get(route('pessoas.index'))->assertOk()->assertSee('Maria Aparecida Costa')->assertDontSee($cpf);

        $this->post(route('pessoas.busca'), ['termo' => 'Maria'])
            ->assertOk()
            ->assertSee('Maria Aparecida Costa')
            ->assertDontSee($cpf);

        $this->get(route('pessoas.show', $pessoa))->assertOk()->assertDontSee($cpf)->assertSee('Novo vínculo');
    }

    public function test_consulta_nao_abre_pessoas(): void
    {
        $cliente = $this->cliente();
        $this->contexto()->limpar();
        $this->entrarComo($cliente, [Papel::Consulta]);

        $this->get(route('pessoas.index'))->assertForbidden();
        $this->get(route('pessoas.criar'))->assertForbidden();
    }

    public function test_vinculo_de_outra_pessoa_nao_abre_pela_url_desta(): void
    {
        $cliente = $this->cliente();
        [$empresa, $funcionario] = $this->empresaComPerfil('Funcionário');
        $dona = $this->pessoa($this->cpf(27), 'Dona Do Vínculo');
        $outra = $this->pessoa($this->cpf(28), 'Outra Pessoa Teste');
        $vinculo = $this->vinculo($dona, $empresa, $funcionario);
        $this->contexto()->limpar();
        $this->entrarComo($cliente, [Papel::CadastroPessoa]);

        $this->get(route('pessoas.vinculos.editar', [$outra, $vinculo]))->assertNotFound();
    }

    /** Cria um cliente provisionado e deixa o contexto de banco apontado para ele. */
    private function cliente(string $plano = 'profissional', string $nome = 'Cliente de teste'): string
    {
        $cliente = $this->criarCliente($nome, $plano);
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

    /**
     * Cria a empresa aceitando um perfil. Devolve [empresa, perfil].
     *
     * @return array{0: Empresa, 1: PerfilVinculo}
     */
    private function empresaComPerfil(string $nomePerfil): array
    {
        $empresa = app(EmpresaServico::class)->criar($this->dadosEmpresa());
        $perfil = $this->perfil($nomePerfil);
        app(EmpresaServico::class)->definirPerfis($empresa, [$perfil->id]);

        return [$empresa, $perfil];
    }

    private function perfil(string $nome): PerfilVinculo
    {
        return PerfilVinculo::query()->where('nome', $nome)->firstOrFail();
    }

    private function pessoa(string $cpf, string $nome = 'Ana Lima Souza'): Pessoa
    {
        return app(PessoaServico::class)->criar(['nome' => $nome, 'cpf' => $cpf]);
    }

    private function vinculo(Pessoa $pessoa, Empresa $empresa, PerfilVinculo $perfil, string $inicio = '2026-10-01', ?string $fim = null): Vinculo
    {
        return app(VinculoServico::class)->criar($pessoa, [
            'empresa_perfil' => $empresa->id.'|'.$perfil->id,
            'data_inicio' => $inicio,
            'data_fim' => $fim,
        ]);
    }

    /** @return array<string, string> */
    private function dadosEmpresa(): array
    {
        return [
            'razao_social' => 'Clínica Exemplo Ltda',
            'cnpj' => '11.222.333/0001-81',
            'cep' => '01310-100',
            'logradouro' => 'Avenida Paulista',
            'numero' => '1000',
            'bairro' => 'Bela Vista',
            'municipio' => 'São Paulo',
            'uf' => 'SP',
            'responsavel_nome' => 'Ana Responsável',
            'responsavel_email' => 'responsavel@clinica.exemplo',
            'responsavel_telefone' => '(11) 98888-7777',
        ];
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
