<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Nucleo;

use CadGestor\Nucleo\Ambiente;
use Dotenv\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AmbienteTest extends TestCase
{
    private string $diretorio;

    protected function setUp(): void
    {
        $this->diretorio = sys_get_temp_dir() . '/cadgestor-teste-' . bin2hex(random_bytes(4));
        mkdir($this->diretorio);
    }

    protected function tearDown(): void
    {
        @unlink($this->diretorio . '/.env');
        @rmdir($this->diretorio);
    }

    public function testCarregaArquivoValido(): void
    {
        $ambiente = Ambiente::carregar($this->criarEnv());

        self::assertSame('cadgestor', $ambiente->texto('DB_BANCO'));
        self::assertSame(5432, $ambiente->inteiro('DB_PORTA'));
        self::assertFalse($ambiente->emProducao());
    }

    public function testNaoPoluiVariaveisGlobais(): void
    {
        Ambiente::carregar($this->criarEnv());

        self::assertArrayNotHasKey('DB_BANCO', $_ENV);
        self::assertFalse(getenv('DB_BANCO'));
    }

    public function testFalhaSemVariavelObrigatoria(): void
    {
        $this->expectException(ValidationException::class);

        Ambiente::carregar($this->criarEnv(['DB_HOST' => '']));
    }

    public function testRejeitaAmbienteDesconhecido(): void
    {
        $this->expectException(ValidationException::class);

        Ambiente::carregar($this->criarEnv(['APP_AMBIENTE' => 'homologacao']));
    }

    public function testRejeitaPortaNaoNumerica(): void
    {
        $this->expectException(ValidationException::class);

        Ambiente::carregar($this->criarEnv(['DB_PORTA' => 'abc']));
    }

    public function testTextoUsaPadraoQuandoAusente(): void
    {
        $ambiente = new Ambiente([]);

        self::assertSame('padrao', $ambiente->texto('NAO_EXISTE', 'padrao'));
    }

    public function testTextoSemPadraoLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);

        (new Ambiente([]))->texto('NAO_EXISTE');
    }

    public function testInteiroInvalidoLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);

        (new Ambiente(['X' => '12a']))->inteiro('X');
    }

    #[DataProvider('booleanos')]
    public function testBooleano(string $valor, bool $esperado): void
    {
        self::assertSame($esperado, (new Ambiente(['X' => $valor]))->booleano('X'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function booleanos(): iterable
    {
        yield 'true' => ['true', true];
        yield '1' => ['1', true];
        yield 'on' => ['on', true];
        yield 'false' => ['false', false];
        yield '0' => ['0', false];
        yield 'off' => ['off', false];
    }

    public function testBooleanoInvalidoLancaExcecao(): void
    {
        $this->expectException(RuntimeException::class);

        (new Ambiente(['X' => 'talvez']))->booleano('X');
    }

    public function testDetectaProducao(): void
    {
        self::assertTrue((new Ambiente(['APP_AMBIENTE' => 'producao']))->emProducao());
    }

    /** @param array<string, string> $sobrescrever */
    private function criarEnv(array $sobrescrever = []): string
    {
        $valores = $sobrescrever + [
            'APP_AMBIENTE' => 'teste',
            'APP_FUSO' => 'America/Fortaleza',
            'DB_HOST' => '127.0.0.1',
            'DB_PORTA' => '5432',
            'DB_BANCO' => 'cadgestor',
            'DB_USUARIO' => 'cadgestor_app',
            'DB_SCHEMA' => 'cadgestor',
        ];

        $conteudo = '';
        foreach ($valores as $nome => $valor) {
            $conteudo .= "{$nome}={$valor}\n";
        }

        file_put_contents($this->diretorio . '/.env', $conteudo);

        return $this->diretorio;
    }
}
