<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\Alerta;
use App\Models\Catraca;
use App\Models\EventoAcesso;
use App\Models\Pessoa;
use App\Services\Cadastro\CatracaServico;
use App\Services\Cadastro\EmpresaServico;
use App\Services\Cadastro\PessoaServico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Operação: eventos e alertas (com o tratamento registrado), relatório, exportação
 * (só administrador, registrada na auditoria) e a auditoria (só administrador).
 */
class OperacaoTest extends TestCase
{
    private const SENHA = 'frase de teste bem longa';

    public function test_eventos_mostram_as_passagens_e_os_alertas_pendentes(): void
    {
        $cenario = $this->cenario();
        $this->contexto()->limpar();
        $this->entrarComo($cenario['cliente'], [Papel::Consulta]);

        $this->get(route('eventos.index'))->assertOk()
            ->assertSee('Ana Operação Teste')
            ->assertSee('fora do horário permitido')
            ->assertSee('Marcar como tratado');
    }

    public function test_tratar_alerta_tira_da_lista_e_nao_se_repete(): void
    {
        $cenario = $this->cenario();
        $this->contexto()->limpar();
        $this->entrarComo($cenario['cliente'], [Papel::Consulta]);

        $this->post(route('alertas.tratar', $cenario['alerta']), ['observacao' => 'Porteiro conferiu o cadastro.'])
            ->assertRedirect(route('eventos.index'))
            ->assertSessionHas('sucesso', 'Alerta tratado.');

        $this->contexto()->definir($cenario['cliente']);
        $this->assertNotNull(Alerta::query()->findOrFail($cenario['alerta'])->tratado_em);

        $this->post(route('alertas.tratar', $cenario['alerta']), [])
            ->assertSessionHas('atencao', 'Este alerta já foi tratado.');
    }

    public function test_relatorio_conta_passagens_liberadas_e_negadas(): void
    {
        $cenario = $this->cenario();
        $this->contexto()->limpar();
        $this->entrarComo($cenario['cliente'], [Papel::Consulta]);

        $this->get(route('relatorios.index'))->assertOk()
            ->assertViewHas('totais', ['passagens' => 3, 'liberadas' => 1, 'negadas' => 2]);
    }

    public function test_exportar_passagens_e_so_para_administrador(): void
    {
        $cenario = $this->cenario();
        $this->contexto()->limpar();
        $this->entrarComo($cenario['cliente'], [Papel::Consulta]);

        $this->post(route('relatorios.exportar'), [])->assertForbidden();
    }

    public function test_administrador_exporta_csv_e_o_download_fica_na_auditoria(): void
    {
        $cenario = $this->cenario();
        $this->contexto()->limpar();
        $this->entrarComo($cenario['cliente'], [Papel::Administrador]);

        $resposta = $this->post(route('relatorios.exportar'), [])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('Ana Operação Teste', $resposta->getContent());
        $this->assertStringContainsString('Negado', $resposta->getContent());

        $this->contexto()->definir($cenario['cliente']);
        $this->assertSame(1, (int) DB::connection('pgsql')->scalar("SELECT count(*) FROM auditoria WHERE acao = 'exportar'"));
    }

    public function test_auditoria_so_para_administrador(): void
    {
        $cenario = $this->cenario();
        $this->contexto()->limpar();
        $this->entrarComo($cenario['cliente'], [Papel::Consulta]);

        $this->get(route('auditoria.index'))->assertForbidden();
    }

    public function test_administrador_ve_a_auditoria_com_as_acoes_do_cadastro(): void
    {
        $cenario = $this->cenario();
        $this->contexto()->limpar();
        $this->entrarComo($cenario['cliente'], [Papel::Administrador]);

        $this->get(route('auditoria.index'))->assertOk()
            ->assertSee('criar')
            ->assertSee('pessoa');
    }

    /**
     * Cliente com uma catraca, uma pessoa, uma passagem liberada, duas negadas e um
     * alerta pendente.
     *
     * @return array{cliente: string, catraca: Catraca, pessoa: Pessoa, alerta: string}
     */
    private function cenario(): array
    {
        $cliente = $this->criarCliente();
        $this->contexto()->definir($cliente);

        $empresa = app(EmpresaServico::class)->criar([
            'razao_social' => 'Clínica Operação Ltda', 'cnpj' => '11.222.333/0001-81', 'cep' => '01310-100',
            'logradouro' => 'Avenida Paulista', 'numero' => '1000', 'bairro' => 'Bela Vista',
            'municipio' => 'São Paulo', 'uf' => 'SP', 'responsavel_nome' => 'Ana Responsável',
            'responsavel_email' => 'responsavel@clinica.exemplo', 'responsavel_telefone' => '(11) 98888-7777',
        ]);
        $catraca = app(CatracaServico::class)->criar([
            'empresa_id' => $empresa->id, 'nome' => 'Portaria principal',
            'identificador_dispositivo' => 'CAT-OPERACAO-1', 'tipo_identificacao' => 'digital', 'modo_falha' => 'bloquear',
        ])['catraca'];
        $pessoa = app(PessoaServico::class)->criar(['nome' => 'Ana Operação Teste', 'cpf' => $this->cpf(50)]);

        $base = ['catraca_id' => $catraca->id, 'empresa_id' => $empresa->id, 'tipo_identificacao' => 'digital', 'offline' => false];
        EventoAcesso::query()->create($base + ['pessoa_id' => $pessoa->id, 'data_hora' => now(), 'resultado' => 'liberado']);
        EventoAcesso::query()->create($base + ['data_hora' => now(), 'resultado' => 'negado', 'motivo_negacao' => 'fora_do_horario']);
        EventoAcesso::query()->create($base + ['data_hora' => now(), 'resultado' => 'negado', 'motivo_negacao' => 'pessoa_nao_identificada']);

        $alerta = Alerta::query()->create([
            'tipo' => 'acesso_negado',
            'descricao' => 'Acesso negado na catraca: fora do horário permitido.',
            'catraca_id' => $catraca->id,
        ]);

        return ['cliente' => $cliente, 'catraca' => $catraca, 'pessoa' => $pessoa, 'alerta' => (string) $alerta->id];
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
