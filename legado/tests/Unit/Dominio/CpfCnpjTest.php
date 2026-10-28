<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Dominio;

use CadGestor\Dominio\Cnpj;
use CadGestor\Dominio\Cpf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mesmos casos da validação no banco: PHP e PostgreSQL precisam concordar.
 */
final class CpfCnpjTest extends TestCase
{
    #[DataProvider('cpfs')]
    public function testCpf(string $cpf, bool $valido): void
    {
        self::assertSame($valido, Cpf::valido($cpf));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cpfs(): iterable
    {
        yield 'válido' => ['52998224725', true];
        yield 'válido com máscara' => ['529.982.247-25', true];
        yield 'válido 2' => ['111.444.777-35', true];
        yield 'primeiro DV errado' => ['52998224715', false];
        yield 'segundo DV errado' => ['52998224724', false];
        yield 'repetido' => ['111.111.111-11', false];
        yield 'curto' => ['5299822472', false];
        yield 'vazio' => ['', false];
    }

    #[DataProvider('cnpjs')]
    public function testCnpj(string $cnpj, bool $valido): void
    {
        self::assertSame($valido, Cnpj::valido($cnpj));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cnpjs(): iterable
    {
        yield 'numérico' => ['11.222.333/0001-81', true];
        yield 'numérico 2' => ['11444777000161', true];
        yield 'alfanumérico (exemplo oficial)' => ['12.ABC.345/01DE-35', true];
        yield 'alfanumérico minúsculo é normalizado' => ['12.abc.345/01de-35', true];
        yield 'alfanumérico DV errado' => ['12ABC34501DE36', false];
        yield 'DV com letra' => ['12ABC34501DE3A', false];
        yield 'numérico DV errado' => ['11222333000182', false];
        yield 'zeros' => ['00.000.000/0000-00', false];
        yield 'curto' => ['1122233300018', false];
    }

    public function testNormalizacao(): void
    {
        self::assertSame('52998224725', Cpf::normalizar('529.982.247-25'));
        self::assertSame('12ABC34501DE35', Cnpj::normalizar('12.abc.345/01de-35'));
    }
}
