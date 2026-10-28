<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Nucleo\Contexto;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\CatracaRepositorio;
use CadGestor\Repositorios\EmpresaRepositorio;
use CadGestor\Repositorios\PerfilRepositorio;
use CadGestor\Repositorios\RegraAcessoRepositorio;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\CatracaServico;
use CadGestor\Servicos\EmpresaServico;
use CadGestor\Servicos\RegraAcessoServico;

/**
 * Empresas, catracas e regras de acesso (Fases 3 e 4).
 */
final class CadastrosEmpresaTest extends BancoTestCase
{
    private EmpresaServico $empresas;
    private CatracaServico $catracas;
    private RegraAcessoServico $regras;
    private EmpresaRepositorio $empresaRepo;
    private CatracaRepositorio $catracaRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $auditoria = new Auditoria($this->pdo, new Contexto('127.0.0.1'));
        $this->empresaRepo = new EmpresaRepositorio($this->pdo);
        $this->catracaRepo = new CatracaRepositorio($this->pdo);
        $regraRepo = new RegraAcessoRepositorio($this->pdo);

        $perfilRepo = new PerfilRepositorio($this->pdo);

        $this->empresas = new EmpresaServico($this->pdo, $this->empresaRepo, $perfilRepo, $auditoria);
        $this->catracas = new CatracaServico($this->pdo, $this->catracaRepo, $this->empresaRepo, $auditoria);
        $this->regras = new RegraAcessoServico(
            $this->pdo,
            $regraRepo,
            $this->empresaRepo,
            $this->catracaRepo,
            $auditoria,
        );
    }

    public function testCriaEmpresaComMascarasNormalizadas(): void
    {
        $id = $this->empresas->criar($this->dadosEmpresa());
        $empresa = $this->empresaRepo->buscar($id);

        self::assertSame('12ABC34501DE35', $empresa['cnpj']);
        self::assertSame('60115000', $empresa['cep']);
        self::assertSame('85988887777', $empresa['responsavel_telefone']);
    }

    public function testValidaCamposDaEmpresa(): void
    {
        try {
            $this->empresas->criar(['cnpj' => '11222333000182', 'uf' => 'XX', 'cep' => '123'] + $this->dadosEmpresa());
            self::fail('Deveria recusar.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('cnpj', $erro->erros);
            self::assertArrayHasKey('uf', $erro->erros);
            self::assertArrayHasKey('cep', $erro->erros);
        }
    }

    public function testRecusaCnpjDuplicadoMesmoComMascaraDiferente(): void
    {
        $this->empresas->criar($this->dadosEmpresa());

        $this->expectException(ErroValidacao::class);
        $this->empresas->criar(['cnpj' => '12abc34501de35'] + $this->dadosEmpresa());
    }

    public function testHorariosPadraoRecusamSobreposicao(): void
    {
        $id = $this->empresas->criar($this->dadosEmpresa());

        $this->expectException(ErroValidacao::class);
        $this->empresas->definirHorarios($id, ['1', '1'], ['07:00', '11:00'], ['12:00', '13:00']);
    }

    public function testCatracaGuardaSoOHashDoToken(): void
    {
        $empresa = $this->empresas->criar($this->dadosEmpresa());
        $resultado = $this->catracas->criar($this->dadosCatraca($empresa));
        $catraca = $this->catracaRepo->buscar($resultado['id']);

        self::assertStringStartsWith(CatracaServico::PREFIXO_TOKEN, $resultado['token']);
        self::assertSame(hash('sha256', $resultado['token']), $catraca['token_hash']);
        $encontrada = $this->catracaRepo->localizarPorToken(hash('sha256', $resultado['token']));
        self::assertSame($resultado['id'], $encontrada['catraca_id']);
    }

    public function testRenovarTokenInvalidaOAnterior(): void
    {
        $empresa = $this->empresas->criar($this->dadosEmpresa());
        $resultado = $this->catracas->criar($this->dadosCatraca($empresa));
        $novo = $this->catracas->renovarToken($resultado['id']);

        self::assertNull($this->catracaRepo->localizarPorToken(CatracaServico::hashToken($resultado['token'])));
        self::assertNotNull($this->catracaRepo->localizarPorToken(CatracaServico::hashToken($novo)));
    }

    public function testIdentificadorDeDispositivoUnico(): void
    {
        $empresa = $this->empresas->criar($this->dadosEmpresa());
        $this->catracas->criar($this->dadosCatraca($empresa));

        $this->expectException(ErroValidacao::class);
        $this->catracas->criar(['nome' => 'Outra'] + $this->dadosCatraca($empresa));
    }

    public function testRegraDePerfilExigePerfilAceitoECatracaDaEmpresa(): void
    {
        $empresa = $this->empresas->criar($this->dadosEmpresa());
        $aluno = $this->idPerfil('Aluno');

        try {
            $this->regras->definirDoPerfil($empresa, $aluno, ['1'], ['07:00'], ['12:00'], ['']);
            self::fail('Perfil não aceito deveria ser recusado.');
        } catch (ErroValidacao) {
            self::assertTrue(true);
        }

        $this->empresas->definirPerfis($empresa, [$aluno]);
        $outraEmpresa = $this->empresas->criar(['cnpj' => '11222333000181'] + $this->dadosEmpresa());
        $catracaAlheia = $this->catracas->criar($this->dadosCatraca($outraEmpresa))['id'];

        try {
            $this->regras->definirDoPerfil($empresa, $aluno, ['1'], ['07:00'], ['12:00'], [$catracaAlheia]);
            self::fail('Catraca de outra empresa deveria ser recusada.');
        } catch (ErroValidacao $erro) {
            self::assertStringContainsString('não pertence', $erro->erros['regras']);
        }

        $this->regras->definirDoPerfil($empresa, $aluno, ['1', '2'], ['07:00', '07:00'], ['12:00', '00:00'], ['', '']);
        $salvas = (new RegraAcessoRepositorio($this->pdo))->porPerfilDaEmpresa($empresa)[$aluno];

        self::assertCount(2, $salvas);
        self::assertSame('24:00:00', $salvas[1]['hora_fim']);
    }

    /** @return array<string, string> */
    private function dadosEmpresa(): array
    {
        return [
            'razao_social' => 'Empresa de Teste Ltda',
            'nome_fantasia' => '',
            'cnpj' => '12.ABC.345/01DE-35',
            'cep' => '60115-000',
            'logradouro' => 'Rua de Teste',
            'numero' => '10',
            'complemento' => '',
            'bairro' => 'Centro',
            'municipio' => 'Fortaleza',
            'uf' => 'CE',
            'responsavel_nome' => 'Responsável Teste',
            'responsavel_cargo' => '',
            'responsavel_email' => 'RESP@EXEMPLO.COM',
            'responsavel_telefone' => '(85) 98888-7777',
        ];
    }

    /** @return array<string, string> */
    private function dadosCatraca(string $empresaId): array
    {
        return [
            'empresa_id' => $empresaId,
            'nome' => 'Portaria',
            'localizacao' => '',
            'identificador_dispositivo' => 'DEV-' . substr($empresaId, -6),
            'tipo_identificacao' => 'digital_facial',
            'modo_falha' => 'bloquear',
            'fabricante' => '',
            'modelo' => '',
        ];
    }
}
