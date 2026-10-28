<?php

declare(strict_types=1);

namespace CadGestor\Testes\Integracao;

use PHPUnit\Framework\Attributes\DataProvider;

final class ValidacaoDocumentosBancoTest extends BancoTestCase
{
    #[DataProvider('cpfs')]
    public function testCpfValido(string $cpf, bool $esperado): void
    {
        $consulta = $this->pdo->prepare('SELECT cpf_valido(:cpf)');
        $consulta->execute(['cpf' => $cpf]);

        self::assertSame($esperado, $consulta->fetchColumn());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cpfs(): iterable
    {
        yield 'válido 1' => ['52998224725', true];
        yield 'válido 2' => ['11144477735', true];
        yield 'dígito verificador errado' => ['52998224724', false];
        yield 'sequência repetida' => ['11111111111', false];
        yield 'tamanho menor' => ['5299822472', false];
        yield 'com máscara' => ['529.982.247-25', false];
        yield 'com letras' => ['5299822472A', false];
    }

    #[DataProvider('cnpjs')]
    public function testCnpjValido(string $cnpj, bool $esperado): void
    {
        $consulta = $this->pdo->prepare('SELECT cnpj_valido(:cnpj)');
        $consulta->execute(['cnpj' => $cnpj]);

        self::assertSame($esperado, $consulta->fetchColumn());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cnpjs(): iterable
    {
        yield 'numérico válido 1' => ['11222333000181', true];
        yield 'numérico válido 2' => ['11444777000161', true];
        yield 'alfanumérico válido (exemplo da Receita)' => ['12ABC34501DE35', true];
        yield 'alfanumérico com DV errado' => ['12ABC34501DE36', false];
        yield 'alfanumérico minúsculo' => ['12abc34501de35', false];
        yield 'DV com letra' => ['12ABC34501DE3A', false];
        yield 'numérico com DV errado' => ['11222333000182', false];
        yield 'zeros' => ['00000000000000', false];
        yield 'com máscara' => ['11.222.333/0001-81', false];
    }

    public function testEmpresaComCnpjInvalidoERejeitada(): void
    {
        $empresa = $this->criarEmpresa();
        self::assertNotSame('', $empresa);

        self::assertSame('23514', $this->esperarViolacao(
            'UPDATE empresa SET cnpj = :cnpj WHERE id = :id',
            ['cnpj' => '11222333000182', 'id' => $empresa],
        ));
    }

    public function testCnpjDuplicadoERejeitado(): void
    {
        $this->criarEmpresa();

        self::assertSame('23505', $this->esperarViolacao(<<<'SQL'
            INSERT INTO empresa (razao_social, cnpj, cep, logradouro, numero, bairro, municipio, uf,
                                 responsavel_nome, responsavel_email, responsavel_telefone)
            VALUES ('Outra', '11222333000181', '60000000', 'Rua', '1', 'B', 'Fortaleza', 'CE',
                    'R', 'r@exemplo.com', '8533330000')
            SQL));
    }

    public function testPessoaComCpfInvalidoERejeitada(): void
    {
        self::assertSame('23514', $this->esperarViolacao(
            "INSERT INTO pessoa (nome, cpf) VALUES ('X', '52998224724')",
        ));
    }

    public function testCpfDuplicadoERejeitado(): void
    {
        $this->criarPessoa();

        self::assertSame('23505', $this->esperarViolacao(
            "INSERT INTO pessoa (nome, cpf) VALUES ('Outra', '52998224725')",
        ));
    }

    public function testPessoaSoFicaSemCpfQuandoAnonimizada(): void
    {
        self::assertSame('23514', $this->esperarViolacao("INSERT INTO pessoa (nome) VALUES ('Sem CPF')"));

        $id = $this->inserir(
            "INSERT INTO pessoa (nome, anonimizada_em) VALUES ('Titular anonimizado', now()) RETURNING id",
        );
        self::assertNotSame('', $id);
    }
}
