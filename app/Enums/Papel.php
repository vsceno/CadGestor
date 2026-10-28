<?php

namespace App\Enums;

/**
 * Papéis (permissões) dos usuários. São independentes entre si: cadastrar
 * empresas não dá direito a cadastrar pessoas, e vice-versa. O administrador
 * tem acesso a tudo dentro do próprio cliente.
 *
 * "Plataforma" é quem opera o CadGestor como serviço: só controla os clientes
 * (cadastro, plano, cobrança). Não é papel de cliente e não entra no ambiente dele.
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
            self::Plataforma => 'Equipe da plataforma',
            self::Administrador => 'Administrador',
            self::CadastroEmpresa => 'Cadastro de empresas e catracas',
            self::CadastroPessoa => 'Cadastro de pessoas',
            self::Consulta => 'Consulta e relatórios',
        };
    }

    /** @return list<self> papéis que um usuário de cliente pode receber */
    public static function doCliente(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $papel): bool => $papel !== self::Plataforma));
    }
}
