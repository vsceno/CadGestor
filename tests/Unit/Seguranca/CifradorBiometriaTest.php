<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Seguranca;

use CadGestor\Biometria\IdentificadorSimulado;
use CadGestor\Biometria\TipoBiometria;
use CadGestor\Seguranca\CifradorBiometria;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CifradorBiometriaTest extends TestCase
{
    private const AAD = 'cadgestor:biometria:pessoa-1:digital:2';

    public function testCifraEDecifraComXChaCha20(): void
    {
        $cifrador = new CifradorBiometria([1 => sodium_crypto_kdf_keygen()]);
        $resultado = $cifrador->cifrar('template-de-teste', self::AAD);

        self::assertNotSame('template-de-teste', $resultado['cifrado']);
        self::assertStringNotContainsString('template', $resultado['cifrado']);
        self::assertSame(24, strlen($resultado['nonce']));
        self::assertSame('template-de-teste', $cifrador->decifrar($resultado['cifrado'], $resultado['nonce'], 1, self::AAD));
    }

    public function testNonceAleatorioACadaCifra(): void
    {
        $cifrador = new CifradorBiometria([1 => sodium_crypto_kdf_keygen()]);

        self::assertNotSame(
            $cifrador->cifrar('igual', self::AAD)['cifrado'],
            $cifrador->cifrar('igual', self::AAD)['cifrado'],
        );
    }

    public function testDadosAssociadosDeOutraPessoaImpedemDecifrar(): void
    {
        $cifrador = new CifradorBiometria([1 => sodium_crypto_kdf_keygen()]);
        $r = $cifrador->cifrar('template', CifradorBiometria::dadosAssociados('pessoa-1', 'digital', 2));

        $this->expectException(RuntimeException::class);
        $cifrador->decifrar($r['cifrado'], $r['nonce'], 1, CifradorBiometria::dadosAssociados('pessoa-2', 'digital', 2));
    }

    public function testConteudoAdulteradoEDetectado(): void
    {
        $cifrador = new CifradorBiometria([1 => sodium_crypto_kdf_keygen()]);
        $r = $cifrador->cifrar('template', self::AAD);
        $r['cifrado'][0] = chr(ord($r['cifrado'][0]) ^ 1);

        $this->expectException(RuntimeException::class);
        $cifrador->decifrar($r['cifrado'], $r['nonce'], 1, self::AAD);
    }

    public function testRotacaoDeChaveMantemDecifragemDasAntigas(): void
    {
        $chaveAntiga = sodium_crypto_kdf_keygen();
        $antigo = (new CifradorBiometria([1 => $chaveAntiga]))->cifrar('antigo', self::AAD);

        $rotacionado = new CifradorBiometria([1 => $chaveAntiga, 2 => sodium_crypto_kdf_keygen()]);
        $novo = $rotacionado->cifrar('novo', self::AAD);

        self::assertSame(2, $novo['versao']);
        self::assertSame('antigo', $rotacionado->decifrar($antigo['cifrado'], $antigo['nonce'], 1, self::AAD));
        self::assertSame('novo', $rotacionado->decifrar($novo['cifrado'], $novo['nonce'], 2, self::AAD));
    }

    public function testHashDeIntegridadeTemChaveENaoEOSha256Simples(): void
    {
        $cifrador = new CifradorBiometria([1 => sodium_crypto_kdf_keygen()]);
        $hash = $cifrador->hashIntegridade('template');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame($hash, $cifrador->hashIntegridade('template'));
        self::assertNotSame(hash('sha256', 'template'), $hash);
        self::assertNotSame($hash, (new CifradorBiometria([1 => sodium_crypto_kdf_keygen()]))->hashIntegridade('template'));
    }

    public function testRecusaChaveDeTamanhoErrado(): void
    {
        $this->expectException(RuntimeException::class);
        new CifradorBiometria([1 => 'curta']);
    }

    public function testLeArquivoDeChavesComVersoes(): void
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'cgk');
        file_put_contents($arquivo, "# comentário\n" . CifradorBiometria::gerarLinhaChave(1) . "\n" . CifradorBiometria::gerarLinhaChave(3) . "\n");

        try {
            self::assertSame(3, CifradorBiometria::doArquivo($arquivo)->versaoAtual());
        } finally {
            unlink($arquivo);
        }
    }

    public function testIdentificadorSimuladoReconheceAmostraIgualERejeitaDiferente(): void
    {
        $id = new IdentificadorSimulado();
        $template = $id->extrairTemplate(TipoBiometria::Digital, 'leitura-A');

        self::assertStringNotContainsString('leitura-A', $template, 'A amostra não pode ser recuperável do template.');

        $igual = $id->identificar(TipoBiometria::Digital, 'leitura-A', ['p1' => $template, 'p2' => 'outro']);
        self::assertSame('p1', $igual->referencia);
        self::assertSame(1.0, $igual->score);

        $diferente = $id->identificar(TipoBiometria::Digital, 'leitura-B', ['p1' => $template]);
        self::assertLessThan(0.5, $diferente->score);
    }
}
