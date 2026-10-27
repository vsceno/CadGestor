<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

/**
 * Papéis (permissões) dos usuários do sistema. São independentes entre si:
 * cadastrar empresas não dá direito a cadastrar pessoas, e vice-versa.
 * O administrador tem acesso a tudo.
 */
enum Papel: string
{
    case Administrador = 'administrador';
    case CadastroEmpresa = 'cadastro_empresa';
    case CadastroPessoa = 'cadastro_pessoa';
    case Consulta = 'consulta';

    public function rotulo(): string
    {
        return match ($this) {
            self::Administrador => 'Administrador',
            self::CadastroEmpresa => 'Cadastro de empresas',
            self::CadastroPessoa => 'Cadastro de pessoas',
            self::Consulta => 'Consulta de relatórios',
        };
    }
}
