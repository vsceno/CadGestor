<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Consentimento;
use App\Models\Empresa;
use App\Models\PerfilVinculo;
use App\Models\Pessoa;
use App\Services\Biometria\CifradorBiometria;
use App\Services\Cadastro\CatracaServico;
use App\Services\Cadastro\EmpresaServico;
use App\Services\Cadastro\PessoaServico;
use App\Services\Cadastro\VinculoServico;
use App\Services\Lgpd\BiometriaServico;
use App\Services\Lgpd\ConsentimentoServico;
use App\Services\Lgpd\TermoServico;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * LGPD e biometria: cifra dos templates, consentimento (termo, idade e
 * responsável), trava de biometria sem consentimento no banco, revogação com
 * remoção nas catracas, termo imutável e painel só para o administrador.
 */
class LgpdBiometriaTest extends TestCase
{
    private const SENHA = 'frase de teste bem longa';

    private const TEMPLATE = 'template-biometrico-de-teste-0001';

    protected function setUp(): void
    {
        parent::setUp();

        // Chave de teste: o arquivo real (storage/chaves) não é necessário para rodar a suíte.
        $this->app->instance(CifradorBiometria::class, new CifradorBiometria([1 => random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES)]));
    }

    public function test_template_so_decifra_com_o_mesmo_registro(): void
    {
        $cifrador = app(CifradorBiometria::class);
        $dados = CifradorBiometria::dadosAssociados('pessoa-a', 'digital', 3);
        $cifra = $cifrador->cifrar(self::TEMPLATE, $dados);

        $this->assertSame(self::TEMPLATE, $cifrador->decifrar($cifra['cifrado'], $cifra['nonce'], $cifra['versao'], $dados));

        $this->expectException(RuntimeException::class);
        $cifrador->decifrar($cifra['cifrado'], $cifra['nonce'], $cifra['versao'], CifradorBiometria::dadosAssociados('pessoa-a', 'digital', 4));
    }

    public function test_rotacao_de_chave_mantem_os_templates_antigos(): void
    {
        $antiga = random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES);
        $nova = random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES);
        $dados = CifradorBiometria::dadosAssociados('pessoa-b', 'facial', null);

        $cifra = (new CifradorBiometria([1 => $antiga]))->cifrar(self::TEMPLATE, $dados);
        $rotacionado = new CifradorBiometria([1 => $antiga, 2 => $nova]);

        $this->assertSame(2, $rotacionado->versaoAtual());
        $this->assertSame(self::TEMPLATE, $rotacionado->decifrar($cifra['cifrado'], $cifra['nonce'], 1, $dados));
        $this->assertSame(2, $rotacionado->cifrar(self::TEMPLATE, $dados)['versao']);
    }

    public function test_consentimento_exige_data_de_nascimento(): void
    {
        $this->cliente();
        $pessoa = $this->pessoa($this->cpf(30));

        try {
            app(ConsentimentoServico::class)->registrar($pessoa, ['canal' => 'presencial', 'aceite_titular' => '1'], '127.0.0.1');
            $this->fail('Sem data de nascimento não há como saber se precisa de responsável.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('data_nascimento', $erro->errors());
        }
    }

    public function test_adulto_aceita_pelo_titular_e_menor_exige_o_responsavel(): void
    {
        $this->cliente();
        $servico = app(ConsentimentoServico::class);
        $adulto = $this->pessoa($this->cpf(31), 'Adulta Teste Silva', '1990-05-10');
        $menor = $this->pessoa($this->cpf(32), 'Menor Teste Silva', '2015-03-01');

        $consentimento = $servico->registrar($adulto, ['canal' => 'presencial', 'aceite_titular' => '1'], '127.0.0.1');
        $this->assertFalse($consentimento->titular_menor);

        try {
            $servico->registrar($menor, ['canal' => 'presencial'], '127.0.0.1');
            $this->fail('Menor sem responsável não pode ter consentimento registrado.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('responsavel_nome', $erro->errors());
            $this->assertArrayHasKey('responsavel_cpf', $erro->errors());
            $this->assertArrayHasKey('responsavel_parentesco', $erro->errors());
            $this->assertArrayHasKey('aceite_responsavel', $erro->errors());
        }

        $consentimentoMenor = $servico->registrar($menor, $this->dadosResponsavel(), '127.0.0.1');
        $this->assertTrue($consentimentoMenor->titular_menor);
    }

    public function test_biometria_sem_consentimento_e_recusada_pelo_servico(): void
    {
        $this->cliente();
        $pessoa = $this->pessoa($this->cpf(33), 'Sem Consentimento Teste', '1990-05-10');

        try {
            app(BiometriaServico::class)->cadastrar($pessoa, 'digital', 2, self::TEMPLATE);
            $this->fail('Biometria sem consentimento não pode ser cadastrada.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('consentimento', $erro->errors());
        }
    }

    public function test_banco_recusa_biometria_ativa_sem_consentimento_ativo(): void
    {
        $this->cliente();
        $pessoa = $this->pessoa($this->cpf(34), 'Revogada Teste Silva', '1990-05-10');
        $consentimento = $this->aceitar($pessoa);
        app(ConsentimentoServico::class)->revogar($pessoa, '127.0.0.1');

        // Mesmo gravando direto no banco, o trigger não deixa biometria ativa com consentimento revogado.
        $this->expectException(QueryException::class);
        DB::connection('pgsql')->insert(<<<'SQL'
            INSERT INTO biometria (pessoa_id, consentimento_id, tipo, dedo, template_cifrado, nonce, versao_chave, hash_integridade)
            VALUES (?, ?, 'digital', 2, decode('00', 'hex'), decode('00', 'hex'), 1, ?)
            SQL, [$pessoa->id, $consentimento->id, str_repeat('0', 64)]);
    }

    public function test_nova_digital_substitui_a_anterior_e_o_template_nao_fica_em_claro(): void
    {
        $this->cliente();
        $pessoa = $this->pessoa($this->cpf(35), 'Substitui Teste Silva', '1990-05-10');
        $this->aceitar($pessoa);
        $servico = app(BiometriaServico::class);

        $primeira = $servico->cadastrar($pessoa, 'digital', 2, 'primeiro-template-de-teste-1');
        $segunda = $servico->cadastrar($pessoa, 'digital', 2, 'segundo-template-de-teste-2');

        $this->assertSame(1, DB::connection('pgsql')->scalar('SELECT count(*) FROM biometria WHERE pessoa_id = ? AND ativo', [$pessoa->id]));
        $this->assertSame('segundo-template-de-teste-2', $servico->template($segunda));

        $linha = DB::connection('pgsql')->selectOne(
            "SELECT motivo_exclusao, template_cifrado, encode(template_cifrado, 'hex') AS hex FROM biometria WHERE id = ?",
            [$primeira],
        );
        $this->assertSame('substituicao', $linha->motivo_exclusao);
        $this->assertNull($linha->template_cifrado);
        $this->assertStringNotContainsString(bin2hex('segundo-template-de-teste-2'), (string) DB::connection('pgsql')->scalar(
            "SELECT encode(template_cifrado, 'hex') FROM biometria WHERE id = ?",
            [$segunda],
        ));
    }

    public function test_revogar_apaga_as_biometrias_e_agenda_a_remocao_nas_catracas(): void
    {
        $this->cliente();
        [$empresa, $funcionario] = $this->empresaComPerfil();
        $pessoa = $this->pessoa($this->cpf(36), 'Revogacao Teste Silva', '1990-05-10');
        app(VinculoServico::class)->criar($pessoa, [
            'empresa_perfil' => $empresa->id.'|'.$funcionario->id,
            'data_inicio' => '2026-10-01',
        ]);
        app(CatracaServico::class)->criar([
            'empresa_id' => $empresa->id,
            'nome' => 'Portaria',
            'identificador_dispositivo' => 'CAT-LGPD-01',
            'tipo_identificacao' => 'digital_facial',
            'modo_falha' => 'bloquear',
        ]);
        $this->aceitar($pessoa);
        $biometrias = app(BiometriaServico::class);
        $biometrias->cadastrar($pessoa, 'digital', 2, self::TEMPLATE);
        $biometrias->cadastrar($pessoa, 'facial', null, 'rosto-de-teste-0000000001');

        $apagadas = app(ConsentimentoServico::class)->revogar($pessoa, '127.0.0.1');

        $this->assertSame(2, $apagadas);
        $this->assertSame(0, (int) DB::connection('pgsql')->scalar('SELECT count(*) FROM biometria WHERE pessoa_id = ? AND ativo', [$pessoa->id]));
        $this->assertSame(0, (int) DB::connection('pgsql')->scalar(
            'SELECT count(*) FROM biometria WHERE pessoa_id = ? AND template_cifrado IS NOT NULL',
            [$pessoa->id],
        ));
        $this->assertSame(2, (int) DB::connection('pgsql')->scalar(
            "SELECT count(*) FROM fila_sincronizacao WHERE pessoa_id = ? AND operacao = 'remover' AND status = 'pendente'",
            [$pessoa->id],
        ));
        $this->assertNotNull(DB::connection('pgsql')->scalar(
            'SELECT revogado_em FROM consentimento WHERE pessoa_id = ? ORDER BY aceito_em DESC LIMIT 1',
            [$pessoa->id],
        ));
    }

    public function test_depois_de_revogar_a_pessoa_pode_dar_um_novo_consentimento(): void
    {
        $this->cliente();
        $pessoa = $this->pessoa($this->cpf(37), 'Novo Aceite Teste Silva', '1990-05-10');
        $servico = app(ConsentimentoServico::class);
        $this->aceitar($pessoa);
        $servico->revogar($pessoa, '127.0.0.1');

        $this->assertNotNull($this->aceitar($pessoa));
    }

    public function test_termo_publicado_nao_muda_e_versao_repetida_e_recusada(): void
    {
        $this->cliente();
        $termos = app(TermoServico::class);
        $vigente = $termos->vigente();

        try {
            // Dentro de um savepoint: a falha esperada não derruba a transação do teste.
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')
                ->update('UPDATE termo_consentimento SET texto = ? WHERE id = ?', [str_repeat('Alterado. ', 30), $vigente->id]));
            $this->fail('Termo publicado não pode ser alterado no banco.');
        } catch (QueryException $erro) {
            $this->assertStringContainsString('Termo publicado não pode ser alterado', $erro->getMessage());
        }

        $novo = $termos->publicar('1.1', str_repeat('Texto revisado do termo de consentimento. ', 10));
        $this->assertSame($novo->id, $termos->vigente()->id);

        try {
            $termos->publicar('1.1', str_repeat('Outro texto de teste. ', 15));
            $this->fail('Versão já publicada devia ser recusada.');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('versao', $erro->errors());
        }
    }

    public function test_trilha_do_aceite_do_menor_nao_guarda_dados_do_responsavel(): void
    {
        $this->cliente();
        $menor = $this->pessoa($this->cpf(38), 'Menor Trilha Teste Silva', '2015-03-01');
        $dados = $this->dadosResponsavel();
        app(ConsentimentoServico::class)->registrar($menor, $dados, '127.0.0.1');

        $depois = (string) DB::connection('pgsql')->scalar(
            "SELECT dados_depois::text FROM auditoria WHERE acao = 'consentimento_aceito' AND registro_id IS NOT NULL AND dados_depois::text LIKE ?",
            ['%'.$menor->id.'%'],
        );

        $this->assertStringNotContainsString($dados['responsavel_cpf'], $depois);
        $this->assertStringNotContainsString('Carla', $depois);
        $this->assertTrue(json_decode($depois, true)['titular_menor']);
    }

    public function test_datas_do_aceite_ficam_no_instante_certo(): void
    {
        $this->cliente();
        $pessoa = $this->pessoa($this->cpf(39), 'Fuso Horario Teste Silva', '1990-05-10');
        $this->aceitar($pessoa);

        // Sem o fuso da sessão igual ao da aplicação, o aceite ficava 3 horas no passado.
        $epoch = (int) DB::connection('pgsql')->scalar(
            'SELECT extract(epoch FROM aceito_em)::int FROM consentimento WHERE pessoa_id = ?',
            [$pessoa->id],
        );

        $this->assertLessThan(120, abs($epoch - time()));
    }

    public function test_painel_lgpd_so_para_o_administrador(): void
    {
        $cliente = $this->cliente();
        $this->contexto()->limpar();
        $this->entrarComo($cliente, [Papel::CadastroPessoa]);

        $this->get(route('lgpd.index'))->assertForbidden();
    }

    public function test_administrador_ve_o_termo_vigente_no_painel(): void
    {
        $cliente = $this->cliente();
        $this->contexto()->limpar();
        $this->entrarComo($cliente, [Papel::Administrador]);

        $this->get(route('lgpd.index'))->assertOk()->assertSee('Termo vigente')->assertSee('Publicar nova versão');
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

    private function pessoa(string $cpf, string $nome = 'Ana Lima Souza', ?string $nascimento = null): Pessoa
    {
        return app(PessoaServico::class)->criar(['nome' => $nome, 'cpf' => $cpf, 'data_nascimento' => $nascimento]);
    }

    private function aceitar(Pessoa $pessoa): Consentimento
    {
        return app(ConsentimentoServico::class)->registrar($pessoa, ['canal' => 'presencial', 'aceite_titular' => '1'], '127.0.0.1');
    }

    /** @return array{canal: string, responsavel_nome: string, responsavel_cpf: string, responsavel_parentesco: string, aceite_responsavel: string} */
    private function dadosResponsavel(): array
    {
        return [
            'canal' => 'presencial',
            'responsavel_nome' => 'Carla Souza Lima',
            'responsavel_cpf' => $this->cpf(900),
            'responsavel_parentesco' => 'mae',
            'aceite_responsavel' => '1',
        ];
    }

    /** @return array{0: Empresa, 1: PerfilVinculo} */
    private function empresaComPerfil(): array
    {
        $empresa = app(EmpresaServico::class)->criar([
            'razao_social' => 'Clínica LGPD Ltda', 'cnpj' => '11.222.333/0001-81', 'cep' => '01310-100',
            'logradouro' => 'Avenida Paulista', 'numero' => '1000', 'bairro' => 'Bela Vista',
            'municipio' => 'São Paulo', 'uf' => 'SP', 'responsavel_nome' => 'Ana Responsável',
            'responsavel_email' => 'responsavel@clinica.exemplo', 'responsavel_telefone' => '(11) 98888-7777',
        ]);
        $funcionario = PerfilVinculo::query()->where('nome', 'Funcionário')->firstOrFail();
        app(EmpresaServico::class)->definirPerfis($empresa, [$funcionario->id]);

        return [$empresa, $funcionario];
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
