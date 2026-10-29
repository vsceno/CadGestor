<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Cartão "Primeiros passos" do início do cliente: o que falta configurar,
 * com o link de cada passo. Some quando tudo estiver concluído.
 * Lê só o cliente do contexto (RLS).
 */
class PrimeirosPassos
{
    /**
     * @return list<array{titulo: string, descricao: string, concluido: bool, href: string|null}>
     */
    public function doCliente(): array
    {
        $banco = DB::connection('pgsql');
        $contar = static fn (string $tabela): int => (int) $banco->scalar("SELECT count(*) FROM {$tabela}");

        $empresa = (array) ($banco->selectOne('SELECT cep, telefone, email_financeiro FROM organizacao WHERE id = cg_org_atual()') ?? []);

        $passos = [
            ['Dados da empresa', 'Endereço, telefone e e-mail financeiro, usados na cobrança e na nota fiscal.',
                ! empty($empresa['cep']) && ! empty($empresa['telefone']) && ! empty($empresa['email_financeiro']), 'assinatura.empresa'],
            ['Empresas atendidas', 'Cadastre as empresas cujas pessoas vão ter acesso (a sua própria, prestadoras...).', $contar('empresa') > 0, 'empresas.index'],
            ['Catracas', 'Cadastre cada catraca e conecte o equipamento com o token gerado.', $contar('catraca') > 0, 'catracas.index'],
            ['Perfis de acesso', 'Confirme a base legal de cada perfil (Funcionário, Aluno, Faxineiro, Pedreiro...).',
                $contar('perfil_vinculo WHERE ativo') > 0 && $contar('perfil_vinculo WHERE ativo AND NOT base_legal_validada') === 0, 'perfis.index'],
            ['Pessoas', 'Cadastre as pessoas e o vínculo de cada uma com uma empresa.', $contar('pessoa') > 0, 'pessoas.index'],
            ['Regras de acesso', 'Defina em quais catracas e horários cada perfil passa.', $contar('regra_acesso') > 0, 'regras.index'],
        ];

        return array_map(static fn (array $p): array => [
            'titulo' => $p[0],
            'descricao' => $p[1],
            'concluido' => $p[2],
            'href' => Route::has($p[3]) ? route($p[3]) : null,
        ], $passos);
    }
}
