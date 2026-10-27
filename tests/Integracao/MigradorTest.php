<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use CadGestor\Banco\Migrador;
use RuntimeException;

final class MigradorTest extends BancoTestCase
{
    private string $diretorio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diretorio = sys_get_temp_dir() . '/cadgestor-mig-' . bin2hex(random_bytes(4));
        mkdir($this->diretorio);

        foreach (glob(self::raiz() . '/database/migracoes/*.sql') ?: [] as $arquivo) {
            copy($arquivo, $this->diretorio . '/' . basename($arquivo));
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->diretorio . '/*') ?: []);
        @rmdir($this->diretorio);
        parent::tearDown();
    }

    public function testBancoDeTesteEstaComTodasAsMigracoes(): void
    {
        $migrador = new Migrador($this->pdo, self::raiz() . '/database/migracoes');

        self::assertSame([], $migrador->pendentes());
        self::assertCount(count($migrador->disponiveis()), $migrador->aplicadas());
    }

    public function testDetectaMigracaoAlteradaDepoisDeAplicada(): void
    {
        file_put_contents($this->diretorio . '/0001_funcoes_base.sql', "\n-- alteração indevida", FILE_APPEND);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('alterada depois de aplicada');

        (new Migrador($this->pdo, $this->diretorio))->verificarIntegridade();
    }

    public function testDetectaMigracaoAplicadaQueSumiuDoDisco(): void
    {
        unlink($this->diretorio . '/0007_auditoria_permissoes.sql');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('arquivo não existe');

        (new Migrador($this->pdo, $this->diretorio))->verificarIntegridade();
    }

    public function testListaNovaMigracaoComoPendente(): void
    {
        file_put_contents($this->diretorio . '/0999_nova.sql', 'SELECT 1;');

        self::assertSame(['0999'], (new Migrador($this->pdo, $this->diretorio))->pendentes());
    }

    public function testRejeitaNomeForaDoPadrao(): void
    {
        file_put_contents($this->diretorio . '/nova-migracao.sql', 'SELECT 1;');

        $this->expectException(RuntimeException::class);

        (new Migrador($this->pdo, $this->diretorio))->disponiveis();
    }

    public function testChecksumIgnoraDiferencaDeFimDeLinha(): void
    {
        self::assertSame(Migrador::checksum("a\nb\n"), Migrador::checksum("a\r\nb\r\n"));
    }
}
