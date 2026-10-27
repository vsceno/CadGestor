<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Seguranca;

use CadGestor\Seguranca\Papel;
use CadGestor\Seguranca\PoliticaSenha;
use CadGestor\Seguranca\UsuarioAutenticado;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PapeisESenhaTest extends TestCase
{
    public function testPapeisSaoPermissoesSeparadas(): void
    {
        $usuario = $this->usuario(Papel::CadastroPessoa);

        self::assertTrue($usuario->temPapel(Papel::CadastroPessoa));
        self::assertFalse($usuario->temPapel(Papel::CadastroEmpresa));
        self::assertFalse($usuario->temPapel(Papel::Consulta));
        self::assertFalse($usuario->temPapel(Papel::Administrador));
    }

    public function testTemPapelAceitaQualquerUmDaLista(): void
    {
        self::assertTrue($this->usuario(Papel::Consulta)->temPapel(Papel::CadastroEmpresa, Papel::Consulta));
    }

    public function testAdministradorTemTodosOsPapeis(): void
    {
        $admin = $this->usuario(Papel::Administrador);

        foreach (Papel::cases() as $papel) {
            self::assertTrue($admin->temPapel($papel));
        }
    }

    #[DataProvider('senhas')]
    public function testPoliticaDeSenha(string $senha, bool $aceita): void
    {
        self::assertSame($aceita, PoliticaSenha::validar($senha, 'maria.souza@exemplo.com') === null);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function senhas(): iterable
    {
        yield 'frase longa' => ['cavalo correto bateria grampo', true];
        yield 'curta' => ['Ab1!xyz', false];
        yield 'comum' => ['123456789012', false];
        yield 'repetida' => ['aaaaaaaaaaaaaaa', false];
        yield 'contém o e-mail' => ['maria.souza2026!', false];
        yield 'longa demais' => [str_repeat('ab', 70), false];
    }

    private function usuario(Papel ...$papeis): UsuarioAutenticado
    {
        return new UsuarioAutenticado('id', 'Maria Souza', 'maria@exemplo.com', $papeis, false);
    }
}
