<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Http\Requests\DadosClienteRequest;
use App\Models\Assinatura;
use App\Models\Organizacao;
use App\Models\Plano;
use App\Models\Usuario;
use App\Services\LimitadorTentativas;
use App\Services\Plataforma\GestaoClientes;
use App\Services\Plataforma\IndicadoresClientes;
use App\Support\Documento;
use App\Support\Formato;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Clientes, do ponto de vista da plataforma: lista com busca, detalhe em
 * abas (Dados, Plano, Cobrança, Histórico) e o assistente de novo cliente
 * em 4 etapas. A plataforma não entra no ambiente do cliente.
 */
class ClienteController extends Controller
{
    private const CHAVE_ASSISTENTE = 'assistente_novo_cliente';

    public const ABAS = ['dados' => 'Dados', 'plano' => 'Plano', 'cobranca' => 'Cobrança', 'historico' => 'Histórico'];

    public function __construct(private readonly GestaoClientes $gestao) {}

    /**
     * Lista. A busca vai por POST: o nome ou CNPJ pesquisado não fica na URL.
     */
    public function index(Request $request, IndicadoresClientes $indicadores): View
    {
        $filtros = [
            'termo' => mb_substr(trim((string) $request->input('termo', '')), 0, 100),
            'status' => in_array($request->input('status'), [...array_keys(Assinatura::ROTULOS), 'desativado'], true) ? (string) $request->input('status') : '',
            'plano' => (string) $request->input('plano', ''),
        ];

        $clientes = $indicadores->clientes();
        $totais = $indicadores->totais($clientes);
        $termo = mb_strtolower($filtros['termo']);
        $digitos = Documento::normalizarCnpj($filtros['termo']);

        $filtrados = $clientes->filter(function (Organizacao $c) use ($filtros, $termo, $digitos): bool {
            $status = $c->ativo ? $c->assinatura?->status : 'desativado';

            return ($filtros['status'] === '' || $status === $filtros['status'])
                && ($filtros['plano'] === '' || $c->assinatura?->plano?->codigo === $filtros['plano'])
                && ($termo === '' || str_contains(mb_strtolower($c->nome.' '.$c->nome_fantasia), $termo)
                    || ($digitos !== '' && str_contains((string) $c->cnpj, $digitos)));
        })->values();

        return view('plataforma.clientes.index', [
            'clientes' => $filtrados,
            'totais' => $totais,
            'filtros' => $filtros,
            'planos' => $indicadores->planos(),
        ]);
    }

    public function show(Request $request, Organizacao $cliente, string $aba = 'dados'): View
    {
        abort_unless(array_key_exists($aba, self::ABAS), 404);
        $cliente->load('assinatura.plano');

        $administradores = Usuario::query()->where('organizacao_id', $cliente->id)
            ->whereExists(fn ($q) => $q->from('usuario_papel')->whereColumn('usuario_papel.usuario_id', 'usuario_sistema.id')->where('papel', 'administrador'))
            ->orderByDesc('ativo')->orderBy('nome')->get();

        return view('plataforma.clientes.show', [
            'cliente' => $cliente,
            'aba' => $aba,
            'abas' => self::ABAS,
            'assinatura' => $cliente->assinatura,
            'situacao' => $cliente->assinatura?->situacao(),
            'administradores' => $administradores,
            'loginsTravados' => $administradores->mapWithKeys(fn (Usuario $u): array => [$u->id => app(LimitadorTentativas::class)->emailBloqueado($u->email)]),
            'faturas' => $aba === 'cobranca' ? DB::connection('pgsql')->table('fatura')->where('organizacao_id', $cliente->id)->orderByDesc('vencimento')->limit(36)->get() : collect(),
            'historico' => $aba === 'historico' ? $this->historico($cliente) : collect(),
            'planos' => Plano::query()->orderBy('ordem')->get(),
            'uso' => $aba === 'plano' ? collect(DB::connection('pgsql')->select('SELECT * FROM cg_resumo_organizacoes() WHERE organizacao_id = ?', [$cliente->id]))->first() : null,
        ]);
    }

    public function editar(Organizacao $cliente): View
    {
        return view('plataforma.clientes.editar', ['cliente' => $cliente, 'ufs' => DadosClienteRequest::UFS]);
    }

    public function atualizar(DadosClienteRequest $request, Organizacao $cliente): RedirectResponse
    {
        $this->gestao->atualizar($cliente, $request->validated());

        return redirect()->route('plataforma.clientes.show', $cliente)->with('sucesso', 'Cadastro do cliente atualizado.');
    }

    // --- Assistente de novo cliente (venda manual) -------------------------------

    public function assistente(Request $request, int $etapa = 1): View|RedirectResponse
    {
        $rascunho = $request->session()->get(self::CHAVE_ASSISTENTE, []);

        // Não pula etapas: cada uma depende da anterior.
        if ($etapa > 1 && ! isset($rascunho['dados']) || $etapa > 2 && ! isset($rascunho['plano_id']) || $etapa > 3 && ! isset($rascunho['admin'])) {
            return redirect()->route('plataforma.clientes.novo', ['etapa' => 1]);
        }

        return view('plataforma.clientes.assistente', [
            'etapa' => $etapa,
            'rascunho' => $rascunho,
            'planos' => Plano::query()->orderBy('ordem')->get(),
            'ufs' => DadosClienteRequest::UFS,
            'plano' => isset($rascunho['plano_id']) ? Plano::query()->find($rascunho['plano_id']) : null,
        ]);
    }

    public function salvarEtapa(Request $request, int $etapa): RedirectResponse
    {
        $rascunho = $request->session()->get(self::CHAVE_ASSISTENTE, []);

        if ($etapa === 1) {
            $formulario = DadosClienteRequest::createFrom($request);
            $formulario->setContainer(app())->setRedirector(app('redirect'));
            $formulario->validateResolved();
            $rascunho['dados'] = $formulario->validated();
        } elseif ($etapa === 2) {
            $rascunho['plano_id'] = $request->validate(['plano_id' => ['required', 'uuid', 'exists:plano,id']])['plano_id'];
        } elseif ($etapa === 3) {
            $rascunho['admin'] = Validator::make($request->all(), [
                'admin_nome' => ['required', 'string', 'min:3', 'max:150'],
                'admin_email' => ['required', 'email', 'max:254', function (string $a, mixed $valor, \Closure $falha): void {
                    if (DB::connection('pgsql')->scalar('SELECT cg_email_em_uso(?, NULL)', [mb_strtolower(trim((string) $valor))])) {
                        $falha('Este e-mail já está em uso no CadGestor.');
                    }
                }],
            ], [], ['admin_nome' => 'nome do administrador', 'admin_email' => 'e-mail do administrador'])->validate();
        }

        $request->session()->put(self::CHAVE_ASSISTENTE, $rascunho);

        return redirect()->route('plataforma.clientes.novo', ['etapa' => min(4, $etapa + 1)]);
    }

    public function concluir(Request $request): RedirectResponse
    {
        $rascunho = $request->session()->get(self::CHAVE_ASSISTENTE, []);

        if (! isset($rascunho['dados'], $rascunho['plano_id'], $rascunho['admin'])) {
            return redirect()->route('plataforma.clientes.novo', ['etapa' => 1]);
        }

        $cliente = $this->gestao->criar($rascunho['dados'], $rascunho['plano_id'], $rascunho['admin']['admin_nome'], $rascunho['admin']['admin_email']);
        $request->session()->forget(self::CHAVE_ASSISTENTE);

        return redirect()->route('plataforma.clientes.show', $cliente)
            ->with('sucesso', 'Cliente criado com a assinatura ativa. O administrador recebeu no e-mail um link para criar a própria senha.');
    }

    public function cancelarAssistente(Request $request): RedirectResponse
    {
        $request->session()->forget(self::CHAVE_ASSISTENTE);

        return redirect()->route('plataforma.clientes.index');
    }

    // --- Ações sobre o cliente ---------------------------------------------------

    public function situacao(Request $request, Organizacao $cliente): RedirectResponse
    {
        $ativo = $request->boolean('ativo');
        $this->gestao->alterarSituacao($cliente, $ativo, (string) $request->input('confirmacao_texto'));

        return redirect()->route('plataforma.clientes.show', $cliente)->with('sucesso', $ativo
            ? 'Cliente reativado.'
            : 'Cliente desativado: ninguém do cliente entra e as catracas são recusadas. Nada foi apagado.');
    }

    public function plano(Request $request, Organizacao $cliente): RedirectResponse
    {
        $this->gestao->trocarPlano($cliente, (string) $request->input('plano_id'), (string) $request->input('motivo'));

        return redirect()->route('plataforma.clientes.show', [$cliente, 'plano'])->with('sucesso', 'Plano do cliente alterado.');
    }

    public function estenderTeste(Request $request, Organizacao $cliente): RedirectResponse
    {
        $fim = $this->gestao->estenderTeste($cliente, (int) $request->input('dias'), (string) $request->input('motivo'));

        return redirect()->route('plataforma.clientes.show', [$cliente, 'plano'])
            ->with('sucesso', 'Teste grátis estendido até '.Formato::data($fim).'.');
    }

    public function baixa(Request $request, Organizacao $cliente, string $fatura): RedirectResponse
    {
        $this->gestao->darBaixa($cliente, $fatura, (string) $request->input('motivo'));

        return redirect()->route('plataforma.clientes.show', [$cliente, 'cobranca'])->with('sucesso', 'Pagamento registrado. A fatura está paga.');
    }

    public function anotar(Request $request, Organizacao $cliente): RedirectResponse
    {
        $this->gestao->anotar($cliente, (string) $request->input('texto'));

        return redirect()->route('plataforma.clientes.show', [$cliente, 'historico'])->with('sucesso', 'Anotação registrada.');
    }

    public function novoAdministrador(Request $request, Organizacao $cliente): RedirectResponse
    {
        $dados = $request->validate([
            'admin_nome' => ['required', 'string', 'min:3', 'max:150'],
            'admin_email' => ['required', 'email', 'max:254'],
        ], [], ['admin_nome' => 'nome', 'admin_email' => 'e-mail']);
        $this->gestao->criarAdministrador($cliente, $dados['admin_nome'], $dados['admin_email']);

        return redirect()->route('plataforma.clientes.show', $cliente)
            ->with('sucesso', 'Administrador criado. Ele recebeu no e-mail um link para criar a própria senha (vale 72 horas).');
    }

    public function linkSenha(Organizacao $cliente, string $usuario): RedirectResponse
    {
        $this->gestao->enviarLinkSenha($cliente, $usuario);

        return redirect()->route('plataforma.clientes.show', $cliente)
            ->with('sucesso', 'Link de nova senha enviado para o e-mail do administrador (vale 1 hora).');
    }

    /**
     * Histórico comercial (função do banco que só devolve ações comerciais e
     * chaves de uma lista fechada) e anotações internas.
     *
     * @return Collection<int, object>
     */
    private function historico(Organizacao $cliente): Collection
    {
        return collect(DB::connection('pgsql')->select(<<<'SQL'
            SELECT data_hora, acao, origem, tabela, antes::text AS antes, depois::text AS depois, NULL AS texto, NULL AS autor
              FROM cg_historico_comercial(:org, 100)
            UNION ALL
            SELECT n.created_at, 'nota', 'plataforma', 'organizacao_nota', NULL, NULL, n.texto, u.nome
              FROM organizacao_nota n JOIN usuario_sistema u ON u.id = n.autor_id
             WHERE n.organizacao_id = :org
             ORDER BY 1 DESC
             LIMIT 100
            SQL, ['org' => $cliente->id]))->map(function (object $linha): object {
            $linha->antes = json_decode((string) $linha->antes, true) ?: [];
            $linha->depois = json_decode((string) $linha->depois, true) ?: [];

            return $linha;
        });
    }
}
