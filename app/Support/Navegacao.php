<?php

namespace App\Support;

use App\Enums\Papel;
use App\Models\Usuario;
use Illuminate\Support\Facades\Route;

/**
 * Menu lateral e atalhos do celular conforme quem está logado.
 *
 * - Plataforma: só a gestão dos clientes (não entra no ambiente deles).
 * - Cliente: cada item aparece para quem tem o papel (o administrador vê tudo).
 * Itens cuja tela ainda não existe (rota não registrada) não aparecem.
 */
class Navegacao
{
    /**
     * [rótulo, nome da rota, ícone, papel exigido (null = todos)]
     *
     * @var list<array{0: string, 1: string, 2: string, 3: Papel|null}>
     */
    private const MENU_CLIENTE = [
        ['Início', 'inicio', 'inicio', null],
        ['Pessoas', 'pessoas.index', 'pessoas', Papel::CadastroPessoa],
        ['Portaria ao vivo', 'portaria', 'portaria', Papel::Consulta],
        ['Eventos e alertas', 'eventos.index', 'eventos', Papel::Consulta],
        ['Empresas', 'empresas.index', 'empresas', Papel::CadastroEmpresa],
        ['Perfis de acesso', 'perfis.index', 'usuario', Papel::CadastroEmpresa],
        ['Catracas', 'catracas.index', 'catraca', Papel::CadastroEmpresa],
        ['Regras de acesso', 'regras.index', 'regras', Papel::CadastroEmpresa],
        ['Relatórios', 'relatorios.index', 'relatorios', Papel::Consulta],
        ['LGPD', 'lgpd.index', 'lgpd', Papel::Administrador],
        ['Usuários', 'usuarios.index', 'usuarios', Papel::Administrador],
        ['Auditoria', 'auditoria.index', 'auditoria', Papel::Administrador],
        ['Minha assinatura', 'assinatura.index', 'assinatura', Papel::Administrador],
    ];

    /** @var list<array{0: string, 1: string, 2: string, 3: Papel|null}> */
    private const MENU_PLATAFORMA = [
        ['Início', 'inicio', 'painel', null],
        ['Clientes', 'plataforma.clientes.index', 'clientes', null],
        ['Planos', 'plataforma.planos.index', 'planos', null],
    ];

    /** Atalhos da barra inferior do celular (até 4), na ordem do adendo. */
    private const ATALHOS_CLIENTE = ['inicio', 'pessoas.index', 'portaria', 'eventos.index'];

    /**
     * @return array{
     *     menu: list<array{rotulo: string, href: string, icone: string, ativo: bool}>,
     *     atalhos: list<array{rotulo: string, href: string, icone: string, ativo: bool}>,
     *     usuario: array{nome: string, papel: string},
     *     links: array<string, string>,
     *     contexto: null
     * }
     */
    public static function para(Usuario $usuario): array
    {
        $definicao = $usuario->daPlataforma() ? self::MENU_PLATAFORMA : self::MENU_CLIENTE;
        $menu = [];

        foreach ($definicao as [$rotulo, $rota, $icone, $papel]) {
            if (! Route::has($rota) || ($papel !== null && ! $usuario->temPapel($papel))) {
                continue;
            }

            $menu[] = [
                'rotulo' => $rotulo,
                'href' => route($rota),
                'icone' => $icone,
                'ativo' => self::ativo($rota),
                'rota' => $rota,
            ];
        }

        $atalhos = $usuario->daPlataforma() ? [] : array_values(array_filter(
            $menu,
            static fn (array $item): bool => in_array($item['rota'], self::ATALHOS_CLIENTE, true),
        ));

        $papeis = $usuario->papeis();

        return [
            'menu' => $menu,
            'atalhos' => array_slice($atalhos, 0, 4),
            'usuario' => [
                'nome' => $usuario->nome,
                'papel' => $papeis === [] ? '' : $papeis[0]->rotulo(),
            ],
            'links' => [
                'senha' => route('senha.trocar'),
                'perfil' => route('senha.trocar'),
                'sair' => route('sair'),
                'alertas' => Route::has('eventos.index') ? route('eventos.index') : '#',
            ],
            'contexto' => null,
        ];
    }

    private static function ativo(string $rota): bool
    {
        $atual = (string) request()->route()?->getName();
        $prefixo = preg_replace('/\.index$/', '', $rota);

        return $atual === $rota || ($rota !== 'inicio' && str_starts_with($atual, $prefixo.'.'));
    }
}
