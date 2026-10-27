<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Biometria\IdentificadorSimulado;
use CadGestor\Nucleo\Contexto;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\LgpdRepositorio;
use CadGestor\Repositorios\PessoaRepositorio;
use CadGestor\Seguranca\CifradorBiometria;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\BiometriaServico;
use CadGestor\Servicos\ConsentimentoServico;
use CadGestor\Servicos\SincronizacaoServico;

/**
 * Regras LGPD da Fase 6: consentimento, menores, coleta e revogação.
 */
final class ConsentimentoBiometriaTest extends BancoTestCase
{
    private ConsentimentoServico $consentimentos;
    private BiometriaServico $biometrias;
    private LgpdRepositorio $lgpd;

    protected function setUp(): void
    {
        parent::setUp();
        $contexto = new Contexto('192.0.2.10');
        $auditoria = new Auditoria($this->pdo, $contexto);
        $pessoas = new PessoaRepositorio($this->pdo);
        $sincronizacao = new SincronizacaoServico($this->pdo);
        $this->lgpd = new LgpdRepositorio($this->pdo);

        $this->consentimentos = new ConsentimentoServico($this->pdo, $this->lgpd, $pessoas, $auditoria, $sincronizacao, $contexto);
        $this->biometrias = new BiometriaServico(
            $this->pdo,
            $this->lgpd,
            $pessoas,
            new IdentificadorSimulado(),
            new CifradorBiometria([1 => sodium_crypto_kdf_keygen()]),
            $auditoria,
            $sincronizacao,
        );
    }

    public function testColetaBloqueadaSemConsentimento(): void
    {
        $pessoa = $this->criarPessoa();
        $amostra = 'leitura';

        self::assertStringContainsString('Consentimento pendente', (string) $this->biometrias->motivoBloqueio($pessoa));

        $this->expectException(ErroValidacao::class);
        $this->biometrias->cadastrar($pessoa, 'digital', 2, $amostra);
    }

    public function testColetaBloqueadaQuandoIdadeNaoVerificavel(): void
    {
        $pessoa = $this->criarPessoa('52998224725', null);

        self::assertStringContainsString('Idade não verificável', (string) $this->biometrias->motivoBloqueio($pessoa));

        $this->expectException(ErroValidacao::class);
        $this->consentimentos->registrar($pessoa, ['aceite_titular' => '1']);
    }

    public function testConsentimentoGuardaVersaoHashEIp(): void
    {
        $pessoa = $this->criarPessoa();
        $this->consentimentos->registrar($pessoa, ['aceite_titular' => '1']);
        $consentimento = $this->lgpd->consentimentoAtivo($pessoa);
        $termo = $this->lgpd->termoVigente();

        self::assertSame($termo['versao'], $consentimento['versao_termo']);
        self::assertSame($termo['hash_texto'], $consentimento['hash_termo']);
        self::assertSame('192.0.2.10', $consentimento['ip']);
        self::assertFalse($consentimento['titular_menor']);
    }

    public function testMenorExigeResponsavelLegal(): void
    {
        $menor = $this->criarPessoa('11144477735', (new \DateTimeImmutable('-10 years'))->format('Y-m-d'));

        try {
            $this->consentimentos->registrar($menor, ['aceite_titular' => '1']);
            self::fail('Menor sem responsável deveria ser recusado.');
        } catch (ErroValidacao $erro) {
            self::assertArrayHasKey('responsavel_nome', $erro->erros);
            self::assertArrayHasKey('aceite_responsavel', $erro->erros);
        }

        $this->consentimentos->registrar($menor, [
            'aceite_responsavel' => '1',
            'responsavel_nome' => 'Ana Responsável Teste',
            'responsavel_cpf' => '529.982.247-25',
            'responsavel_parentesco' => 'mae',
        ]);

        self::assertTrue($this->lgpd->consentimentoAtivo($menor)['titular_menor']);
        self::assertNull($this->biometrias->motivoBloqueio($menor));
    }

    public function testBiometriaGuardaSoTemplateCifradoESubstituiMesmoDedo(): void
    {
        $pessoa = $this->criarPessoa();
        $this->consentimentos->registrar($pessoa, ['aceite_titular' => '1']);

        $amostra = 'leitura-bruta-do-sensor';
        $primeira = $this->biometrias->cadastrar($pessoa, 'digital', 2, $amostra);
        self::assertSame('', $amostra, 'A amostra deve ser descartada após gerar o template.');

        $registro = $this->lgpd->biometria($primeira);
        $cifrado = stream_get_contents($registro['template_cifrado']);
        self::assertStringNotContainsString('leitura-bruta', $cifrado);
        self::assertStringNotContainsString('SIMTPL', $cifrado);

        $outra = 'nova-leitura';
        $this->biometrias->cadastrar($pessoa, 'digital', 2, $outra);

        $ativas = $this->lgpd->biometriasAtivas($pessoa);
        self::assertCount(1, $ativas, 'Mesmo dedo: a anterior é substituída.');
        self::assertSame('substituicao', $this->lgpd->biometria($primeira)['motivo_exclusao']);
    }

    public function testRevogacaoApagaBiometriasEAgendaRemocao(): void
    {
        $pessoa = $this->criarPessoa();
        $empresa = $this->criarEmpresa();
        $perfil = $this->idPerfil('Funcionário');
        $this->pdo->prepare('INSERT INTO empresa_perfil (empresa_id, perfil_id) VALUES (:e, :p)')
            ->execute(['e' => $empresa, 'p' => $perfil]);
        $this->pdo->prepare("INSERT INTO vinculo (pessoa_id, empresa_id, perfil_id, data_inicio) VALUES (:p, :e, :f, '2026-01-01')")
            ->execute(['p' => $pessoa, 'e' => $empresa, 'f' => $perfil]);
        $this->pdo->prepare("INSERT INTO catraca (empresa_id, nome, identificador_dispositivo, token_hash) VALUES (:e, 'C1', 'DEV-REV', :t)")
            ->execute(['e' => $empresa, 't' => hash('sha256', 'x')]);

        $this->consentimentos->registrar($pessoa, ['aceite_titular' => '1']);
        $a = 'a';
        $b = 'b';
        $this->biometrias->cadastrar($pessoa, 'digital', 1, $a);
        $this->biometrias->cadastrar($pessoa, 'facial', null, $b);

        self::assertSame(2, $this->consentimentos->revogar($pessoa));
        self::assertNull($this->lgpd->consentimentoAtivo($pessoa));
        self::assertSame([], $this->lgpd->biometriasAtivas($pessoa));

        $restos = (int) $this->pdo->query(
            "SELECT count(*) FROM biometria WHERE template_cifrado IS NOT NULL OR nonce IS NOT NULL"
        )->fetchColumn();
        self::assertSame(0, $restos, 'Nenhum template pode sobrar após a revogação.');

        $operacoes = $this->pdo->prepare(
            "SELECT operacao FROM fila_sincronizacao WHERE pessoa_id = :p AND status = 'pendente'"
        );
        $operacoes->execute(['p' => $pessoa]);
        self::assertSame(['remover'], $operacoes->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testTermoPublicadoComVersaoNova(): void
    {
        $texto = str_repeat('Texto do termo revisado pelo jurídico. ', 10);
        $this->consentimentos->publicarTermo('9.0', $texto);

        self::assertSame('9.0', $this->lgpd->termoVigente()['versao']);

        $this->expectException(ErroValidacao::class);
        $this->consentimentos->publicarTermo('9.0', $texto);
    }
}
