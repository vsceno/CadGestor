<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

/**
 * Papéis (permissões) dos usuários do sistema. São independentes entre si:
 * cadastrar empresas não dá direito a cadastrar pessoas, e vice-versa.
 * O administrador tem acesso a tudo dentro da sua organização.
 *
 * "Plataforma" é o papel de quem opera o CadGestor como serviço: cadastra
 * organizações (clientes) e seus administradores. Não é um papel de
 * organização e não pode ser atribuído pela tela de usuários.
 */
enum Papel: string
{
    case Plataforma = 'plataforma';
    case Administrador = 'administrador';
    case CadastroEmpresa = 'cadastro_empresa';
    case CadastroPessoa = 'cadastro_pessoa';
    case Consulta = 'consulta';

    public function rotulo(): string
    {
        return match ($this) {
            self::Plataforma => 'Administrador da plataforma',
            self::Administrador => 'Administrador',
            self::CadastroEmpresa => 'Cadastro de empresas',
            self::CadastroPessoa => 'Cadastro de pessoas',
            self::Consulta => 'Consulta de relatórios',
        };
    }

    /** @return list<self> papéis que um usuário de organização pode receber */
    public static function daOrganizacao(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $papel): bool => $papel !== self::Plataforma));
    }
}
