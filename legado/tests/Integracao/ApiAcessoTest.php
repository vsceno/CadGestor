<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Acesso\AcessoServico;
use CadGestor\Acesso\DecisaoAcesso;
use CadGestor\Acesso\FonteDadosAcessoPdo;
use CadGestor\Acesso\ResultadoDecisao;
use CadGestor\Acesso\SincronizacaoDispositivo;
use CadGestor\Biometria\IdentificadorSimulado;
use CadGestor\Biometria\TipoBiometria;
use CadGestor\Repositorios\LgpdRepositorio;
use CadGestor\Seguranca\CifradorBiometria;
use DateTimeZone;

/**
 * Passagem completa na catraca contra o banco: decisão, evento, alertas e
 * sincronização com o dispositivo.
 */
final class ApiAcessoTest extends BancoTestCase
{
    private AcessoServico $acesso;
    private SincronizacaoDispositivo $sincronizacao;
    private CifradorBiometria $cifrador;
    private IdentificadorSimulado $identificador;

    /** @var array{id: string, empresa_id: string} */
    private array $catraca;
    private string $pessoa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cifrador = new CifradorBiometria([1 => sodium_crypto_kdf_keygen()]);
        $this->identificador = new IdentificadorSimulado();
        $this->acesso = new AcessoServico(
            $this->pdo,
            new DecisaoAcesso(new FonteDadosAcessoPdo($this->pdo), 0.80),
            new LgpdRepositorio($this->pdo),
            $this->identificador,
            $this->cifrador,
            new DateTimeZone('America/Fortaleza'),
        );
        $this->sincronizacao = new SincronizacaoDispositivo($this->pdo, $this->cifrador);
        $this->montarCenario();
    }

    public function testLiberaEGravaEventoCompleto(): void
    {
        $r = $this->acesso->processar($this->catraca, TipoBiometria::Digital, $this->pessoa, 0.97, null);

        self::assertTrue($r['resultado']->liberado);
        $evento = $this->evento($r['evento_id']);
        self::assertSame('liberado', $evento['resultado']);
        self::assertSame($this->pessoa, $evento['pessoa_id']);
        self::assertSame($this->catraca['empresa_id'], $evento['empresa_id']);
        self::assertNotNull($evento['vinculo_id']);
        self::assertNotNull($evento['perfil_id']);
        self::assertSame('0.9700', $evento['score']);
    }

    public function testNegacaoGravaMotivoEAlerta(): void
    {
        $r = $this->acesso->processar($this->catraca, TipoBiometria::Digital, $this->pessoa, 0.30, null);

        self::assertSame(ResultadoDecisao::BIOMETRIA_NAO_CONFERE, $r['resultado']->motivo);
        self::assertSame('biometria_nao_confere', $this->evento($r['evento_id'])['motivo_negacao']);

        $alerta = $this->pdo->prepare('SELECT tipo FROM alerta WHERE evento_id = :e');
        $alerta->execute(['e' => $r['evento_id']]);
        self::assertSame('acesso_negado', $alerta->fetchColumn());
    }

    public function testTresNegacoesGeramUmAlertaDeTentativasRepetidas(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->acesso->processar($this->catraca, TipoBiometria::Digital, null, 0.0, null);
        }

        $total = (int) $this->pdo->query("SELECT count(*) FROM alerta WHERE tipo = 'tentativas_repetidas'")->fetchColumn();
        self::assertSame(1, $total, 'O alerta de tentativas não deve se repetir a cada negação.');
    }

    public function testIdentificacaoNoServidorPelaAmostra(): void
    {
        $r = $this->acesso->processar($this->catraca, TipoBiometria::Digital, null, null, 'leitura-correta');
        self::assertTrue($r['resultado']->liberado);
        self::assertSame($this->pessoa, $r['resultado']->pessoaId);

        $r = $this->acesso->processar($this->catraca, TipoBiometria::Digital, null, null, 'leitura-de-estranho');
        self::assertSame(ResultadoDecisao::PESSOA_NAO_IDENTIFICADA, $r['resultado']->motivo);
    }

    public function testReferenciaQueNaoEUuidENinguemReconhecido(): void
    {
        $r = $this->acesso->processar($this->catraca, TipoBiometria::Digital, "x' OR 1=1 --", 1.0, null);

        self::assertSame(ResultadoDecisao::PESSOA_NAO_IDENTIFICADA, $r['resultado']->motivo);
    }

    public function testEventosOfflineValidamDataEResultado(): void
    {
        $resultado = $this->acesso->registrarOffline($this->catraca, [
            ['tipo' => 'digital', 'referencia' => $this->pessoa, 'resultado' => 'liberado', 'data_hora' => date('c', time() - 3600)],
            ['tipo' => 'digital', 'resultado' => 'liberado', 'data_hora' => '2019-01-01T00:00:00Z'],
            ['tipo' => 'iris', 'resultado' => 'liberado', 'data_hora' => date('c')],
        ]);

        self::assertSame(['gravados' => 1, 'recusados' => 2], $resultado);
        self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM evento_acesso WHERE offline')->fetchColumn());
    }

    public function testSincronizacaoEntregaTemplateDecifradoSoParaACatracaDona(): void
    {
        $operacoes = $this->sincronizacao->pendentes($this->catraca['id']);

        self::assertCount(1, $operacoes);
        self::assertSame('cadastrar', $operacoes[0]['operacao']);
        self::assertSame($this->pessoa, $operacoes[0]['referencia']);
        self::assertSame(
            $this->identificador->extrairTemplate(TipoBiometria::Digital, 'leitura-correta'),
            base64_decode($operacoes[0]['template']),
        );

        // Outra catraca não confirma operação alheia; a dona confirma uma vez só.
        self::assertFalse($this->sincronizacao->confirmar('00000000-0000-0000-0000-000000000000', $operacoes[0]['id'], true, null));
        self::assertTrue($this->sincronizacao->confirmar($this->catraca['id'], $operacoes[0]['id'], true, null));
        self::assertFalse($this->sincronizacao->confirmar($this->catraca['id'], $operacoes[0]['id'], true, null));
        self::assertSame([], $this->sincronizacao->pendentes($this->catraca['id']));
    }

    public function testFalhaDeSincronizacaoReagendaComAtraso(): void
    {
        $operacao = $this->sincronizacao->pendentes($this->catraca['id'])[0];
        $this->sincronizacao->confirmar($this->catraca['id'], $operacao['id'], false, 'memoria cheia <script>');

        $linha = $this->pdo->prepare('SELECT status, ultimo_erro, agendado_para > now() AS adiada FROM fila_sincronizacao WHERE id = :id');
        $linha->execute(['id' => $operacao['id']]);
        $dados = $linha->fetch();

        self::assertSame('pendente', $dados['status']);
        self::assertTrue($dados['adiada']);
        self::assertStringNotContainsString('<', $dados['ultimo_erro']);
    }

    /** Empresa, catraca, pessoa com vínculo 24x7 e biometria digital "leitura-correta". */
    private function montarCenario(): void
    {
        $empresa = $this->criarEmpresa();
        $perfil = $this->idPerfil('Funcionário');
        $this->pdo->prepare('INSERT INTO empresa_perfil (empresa_id, perfil_id) VALUES (:e, :p)')
            ->execute(['e' => $empresa, 'p' => $perfil]);

        $regra = $this->pdo->prepare(<<<'SQL'
            INSERT INTO regra_acesso (empresa_id, perfil_id, dia_semana, hora_inicio, hora_fim)
            VALUES (:e, :p, :d, '00:00', '24:00')
            SQL);

        foreach (range(1, 7) as $dia) {
            $regra->execute(['e' => $empresa, 'p' => $perfil, 'd' => $dia]);
        }

        $catracaId = $this->inserir(
            "INSERT INTO catraca (empresa_id, nome, identificador_dispositivo, token_hash)
             VALUES (:e, 'Portaria', 'DEV-API', :t) RETURNING id",
            ['e' => $empresa, 't' => hash('sha256', 'token')],
        );
        $this->catraca = ['id' => $catracaId, 'empresa_id' => $empresa];

        $this->pessoa = $this->criarPessoa();
        $this->pdo->prepare("INSERT INTO vinculo (pessoa_id, empresa_id, perfil_id, data_inicio) VALUES (:p, :e, :f, '2020-01-01')")
            ->execute(['p' => $this->pessoa, 'e' => $empresa, 'f' => $perfil]);

        $consentimento = $this->criarConsentimento($this->pessoa, $this->criarTermo());
        $template = $this->identificador->extrairTemplate(TipoBiometria::Digital, 'leitura-correta');
        $cifra = $this->cifrador->cifrar($template, CifradorBiometria::dadosAssociados($this->pessoa, 'digital', 2));
        $biometria = (new LgpdRepositorio($this->pdo))->inserirBiometria(
            $this->pessoa,
            $consentimento,
            'digital',
            2,
            $cifra['cifrado'],
            $cifra['nonce'],
            1,
            $this->cifrador->hashIntegridade($template),
        );
        $this->pdo->prepare("INSERT INTO fila_sincronizacao (catraca_id, pessoa_id, biometria_id, operacao) VALUES (:c, :p, :b, 'cadastrar')")
            ->execute(['c' => $catracaId, 'p' => $this->pessoa, 'b' => $biometria]);
    }

    /** @return array<string, mixed> */
    private function evento(string $id): array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM evento_acesso WHERE id = :id');
        $consulta->execute(['id' => $id]);

        return $consulta->fetch();
    }
}
