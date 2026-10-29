<?php

namespace Tests\Feature;

use App\Models\Alerta;
use App\Models\Catraca;
use App\Models\Empresa;
use App\Models\EventoAcesso;
use App\Models\PerfilVinculo;
use App\Models\Pessoa;
use App\Models\Vinculo;
use App\Services\Biometria\CifradorBiometria;
use App\Services\Cadastro\CatracaServico;
use App\Services\Cadastro\EmpresaServico;
use App\Services\Cadastro\PessoaServico;
use App\Services\Cadastro\VinculoServico;
use App\Services\Lgpd\BiometriaServico;
use App\Services\Lgpd\ConsentimentoServico;
use App\Services\Manutencao\ManutencaoServico;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Manutenção: vínculos vencidos encerrados, retenção (titular sem vínculo em aberto
 * anonimizado depois do prazo, com biometria apagada e eventos preservados) e aviso
 * de catraca sem contato, sem repetir o alerta.
 */
class ManutencaoTest extends TestCase
{
    private const TEMPLATE = 'template-biometrico-de-teste-0001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(CifradorBiometria::class, new CifradorBiometria([1 => random_bytes(SODIUM_CRYPTO_KDF_KEYBYTES)]));
        config(['manutencao.dias_retencao' => 30, 'manutencao.minutos_sem_contato' => 30]);
    }

    public function test_titular_sem_vinculo_em_aberto_e_anonimizado_depois_do_prazo(): void
    {
        $cliente = $this->cliente();
        [$empresa, $perfil] = $this->empresaComPerfil();
        $pessoa = $this->pessoa($this->cpf(60), 'Titular Antigo Teste', '1990-05-10');
        $vinculo = $this->vinculo($pessoa, $empresa, $perfil);
        app(ConsentimentoServico::class)->registrar($pessoa, ['canal' => 'presencial', 'aceite_titular' => '1'], '127.0.0.1');
        app(BiometriaServico::class)->cadastrar($pessoa, 'digital', 2, self::TEMPLATE);
        app(VinculoServico::class)->encerrar($vinculo, ['motivo' => 'Fim do contrato de teste']);

        $evento = EventoAcesso::query()->create([
            'catraca_id' => $this->catraca($empresa)->id, 'empresa_id' => $empresa->id, 'pessoa_id' => $pessoa->id,
            'data_hora' => now(), 'tipo_identificacao' => 'digital', 'resultado' => 'liberado', 'offline' => false,
        ]);

        // Trinta dias depois, o titular já passou do prazo de retenção.
        $totais = app(ManutencaoServico::class)->executarNaOrganizacao($cliente, ['retencao'], now()->addDays(31));

        $this->assertSame(['retencao' => 1], $totais);
        $this->contexto()->definir($cliente);
        $anonimizada = Pessoa::query()->findOrFail($pessoa->id);
        $this->assertNotNull($anonimizada->anonimizada_em);
        $this->assertSame('Titular anonimizado', $anonimizada->nome);
        $this->assertNull($anonimizada->cpf);
        $this->assertFalse($anonimizada->ativo);
        $this->assertSame(0, (int) DB::connection('pgsql')->scalar('SELECT count(*) FROM biometria WHERE pessoa_id = ? AND ativo', [$pessoa->id]));
        $this->assertNotNull(DB::connection('pgsql')->scalar('SELECT revogado_em FROM consentimento WHERE pessoa_id = ?', [$pessoa->id]));
        $this->assertSame($pessoa->id, EventoAcesso::query()->findOrFail($evento->id)->pessoa_id, 'O evento de acesso continua, ligado ao registro anonimizado.');
    }

    public function test_titular_com_vinculo_em_aberto_nao_e_anonimizado_mesmo_fora_do_prazo(): void
    {
        $cliente = $this->cliente();
        [$empresa, $perfil] = $this->empresaComPerfil();
        $pessoa = $this->pessoa($this->cpf(61), 'Titular Ativo Teste');
        $this->vinculo($pessoa, $empresa, $perfil);

        $totais = app(ManutencaoServico::class)->executarNaOrganizacao($cliente, ['retencao'], now()->addDays(365));

        $this->assertSame(['retencao' => 0], $totais);
    }

    public function test_titular_dentro_do_prazo_nao_e_anonimizado(): void
    {
        $cliente = $this->cliente();
        $this->pessoa($this->cpf(62), 'Titular Recente Teste');

        $totais = app(ManutencaoServico::class)->executarNaOrganizacao($cliente, ['retencao'], now());

        $this->assertSame(['retencao' => 0], $totais);
    }

    public function test_vinculo_com_validade_vencida_e_encerrado(): void
    {
        $cliente = $this->cliente();
        [$empresa, $perfil] = $this->empresaComPerfil();
        $vinculo = app(VinculoServico::class)->criar($this->pessoa($this->cpf(63), 'Validade Vencida Teste'), [
            'empresa_perfil' => $empresa->id.'|'.$perfil->id, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-10-27',
        ]);

        $totais = app(ManutencaoServico::class)->executarNaOrganizacao($cliente, ['vinculos'], now());

        $this->assertSame(['vinculos' => 1], $totais);
        $this->contexto()->definir($cliente);
        $this->assertSame('encerrado', Vinculo::query()->findOrFail($vinculo->id)->status);
    }

    public function test_catraca_sem_contato_gera_um_aviso_so_nao_repetido(): void
    {
        $cliente = $this->cliente();
        [$empresa] = $this->empresaComPerfil();
        $catraca = $this->catraca($empresa);
        DB::connection('pgsql')->update("UPDATE catraca SET ultimo_contato_em = now() - interval '1 hour' WHERE id = ?", [$catraca->id]);
        $servico = app(ManutencaoServico::class);

        $this->assertSame(['catracas' => 1], $servico->executarNaOrganizacao($cliente, ['catracas'], now()));
        $this->assertSame(['catracas' => 0], $servico->executarNaOrganizacao($cliente, ['catracas'], now()));

        $this->contexto()->definir($cliente);
        $this->assertSame(1, Alerta::query()->where('tipo', 'dispositivo_offline')->count());
    }

    /** Cria um cliente provisionado e deixa o contexto de banco apontado para ele. */
    private function cliente(): string
    {
        $cliente = $this->criarCliente();
        $this->contexto()->definir($cliente);

        return $cliente;
    }

    /** @return array{0: Empresa, 1: PerfilVinculo} */
    private function empresaComPerfil(): array
    {
        $empresa = app(EmpresaServico::class)->criar([
            'razao_social' => 'Clínica Manutenção Ltda', 'cnpj' => '11.222.333/0001-81', 'cep' => '01310-100',
            'logradouro' => 'Avenida Paulista', 'numero' => '1000', 'bairro' => 'Bela Vista',
            'municipio' => 'São Paulo', 'uf' => 'SP', 'responsavel_nome' => 'Ana Responsável',
            'responsavel_email' => 'responsavel@clinica.exemplo', 'responsavel_telefone' => '(11) 98888-7777',
        ]);
        $perfil = PerfilVinculo::query()->where('nome', 'Funcionário')->firstOrFail();
        app(EmpresaServico::class)->definirPerfis($empresa, [$perfil->id]);

        return [$empresa, $perfil];
    }

    private function catraca(Empresa $empresa): Catraca
    {
        return app(CatracaServico::class)->criar([
            'empresa_id' => $empresa->id, 'nome' => 'Portaria manutenção', 'identificador_dispositivo' => 'CAT-MANUT-1',
            'tipo_identificacao' => 'digital', 'modo_falha' => 'bloquear',
        ])['catraca'];
    }

    private function pessoa(string $cpf, string $nome, ?string $nascimento = null): Pessoa
    {
        return app(PessoaServico::class)->criar(['nome' => $nome, 'cpf' => $cpf, 'data_nascimento' => $nascimento]);
    }

    private function vinculo(Pessoa $pessoa, Empresa $empresa, PerfilVinculo $perfil): Vinculo
    {
        return app(VinculoServico::class)->criar($pessoa, [
            'empresa_perfil' => $empresa->id.'|'.$perfil->id, 'data_inicio' => '2026-10-01',
        ]);
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
