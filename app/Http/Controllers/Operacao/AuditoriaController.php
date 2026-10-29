<?php

namespace App\Http\Controllers\Operacao;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Trilha de auditoria do cliente (só leitura, só administrador). Os registros
 * já vêm sem segredos e sem dados pessoais: o que mudou, quem e quando.
 */
class AuditoriaController extends Controller
{
    /** Ações gravadas na trilha (espelha a restrição do banco). */
    public const ACOES = [
        'criar', 'alterar', 'excluir', 'anonimizar', 'login', 'logout', 'login_falhou', 'senha_alterada',
        'consentimento_aceito', 'consentimento_revogado', 'biometria_cadastrada', 'biometria_excluida',
        'exportar', 'job_manutencao', 'seed', 'acesso_suporte',
    ];

    private const LIMITE = 200;

    public function index(Request $request): View
    {
        $dados = $request->validate([
            'acao' => ['nullable', Rule::in(self::ACOES)],
            'tabela' => ['nullable', 'string', 'max:60'],
        ]);

        $registros = DB::connection('pgsql')->table('auditoria as a')
            ->leftJoin('usuario_sistema as u', 'u.id', '=', 'a.usuario_id')
            ->select('a.data_hora', 'a.acao', 'a.tabela', 'a.registro_id', 'a.dados_antes', 'a.dados_depois', 'a.ip', 'u.nome as usuario')
            ->when($dados['acao'] ?? null, fn ($consulta, string $acao) => $consulta->where('a.acao', $acao))
            ->when($dados['tabela'] ?? null, fn ($consulta, string $tabela) => $consulta->where('a.tabela', $tabela))
            ->orderByDesc('a.data_hora')
            ->limit(self::LIMITE)
            ->get();

        return view('auditoria.index', [
            'registros' => $registros,
            'acoes' => self::ACOES,
            'filtros' => ['acao' => $dados['acao'] ?? '', 'tabela' => $dados['tabela'] ?? ''],
        ]);
    }
}
