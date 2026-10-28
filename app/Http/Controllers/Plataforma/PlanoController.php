<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Plano;
use App\Services\Auditoria;
use App\Services\Plataforma\IndicadoresClientes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Catálogo de planos (só a plataforma altera). Cartões lado a lado, com o
 * plano mais contratado em destaque.
 */
class PlanoController extends Controller
{
    public function index(IndicadoresClientes $indicadores): View
    {
        return view('plataforma.planos.index', [
            'planos' => $indicadores->planos(),
            'porPlano' => $indicadores->porPlano(),
            'maisContratado' => $indicadores->planoMaisContratado(),
        ]);
    }

    public function criar(): View
    {
        return view('plataforma.planos.formulario', ['plano' => new Plano(['disponivel_venda' => true, 'ordem' => 10])]);
    }

    public function salvar(Request $request, Auditoria $auditoria): RedirectResponse
    {
        $dados = $this->validar($request, null);

        DB::connection('pgsql')->transaction(function () use ($dados, $auditoria): void {
            $plano = Plano::query()->create($dados);
            $auditoria->registrar('criar', 'plano', $plano->id, null, $plano->only(['codigo', 'nome', 'preco_centavos', 'limite_pessoas', 'limite_catracas']));
        });

        return redirect()->route('plataforma.planos.index')->with('sucesso', 'Plano criado.');
    }

    public function editar(Plano $plano): View
    {
        return view('plataforma.planos.formulario', ['plano' => $plano]);
    }

    public function atualizar(Request $request, Plano $plano, Auditoria $auditoria): RedirectResponse
    {
        $dados = $this->validar($request, $plano);
        unset($dados['codigo']); // O código identifica o plano nos contratos: não muda.

        DB::connection('pgsql')->transaction(function () use ($plano, $dados, $auditoria): void {
            $antes = $plano->only(array_keys($dados));
            $plano->update($dados);
            [$de, $para] = Auditoria::diferenca($antes, $plano->only(array_keys($dados)));

            if ($para) {
                $auditoria->registrar('alterar', 'plano', $plano->id, $de, $para);
            }
        });

        return redirect()->route('plataforma.planos.index')->with('sucesso', 'Plano atualizado. O novo preço vale a partir da próxima cobrança.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?Plano $plano): array
    {
        // Preço digitado como "1.290,00" vira centavos.
        $preco = preg_replace('/[^\d,]/', '', (string) $request->input('preco', ''));
        $request->merge([
            'preco_centavos' => $preco === '' ? null : (int) round((float) str_replace(',', '.', $preco) * 100),
            'disponivel_venda' => $request->boolean('disponivel_venda'),
        ]);

        return $request->validate([
            'codigo' => $plano ? [] : ['required', 'regex:/^[a-z][a-z0-9_]{1,29}$/', Rule::unique('plano', 'codigo')],
            'nome' => ['required', 'string', 'min:2', 'max:60'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'preco_centavos' => ['required', 'integer', 'min:0', 'max:100000000'],
            'limite_pessoas' => ['required', 'integer', 'min:1', 'max:1000000'],
            'limite_catracas' => ['required', 'integer', 'min:1', 'max:10000'],
            'disponivel_venda' => ['boolean'],
            'ordem' => ['required', 'integer', 'min:0', 'max:999'],
        ], [
            'codigo.regex' => 'Use letras minúsculas, números e sublinhado, começando por letra (ex.: profissional).',
            'preco_centavos.required' => 'Informe o preço mensal.',
        ], [
            'codigo' => 'código', 'preco_centavos' => 'preço', 'limite_pessoas' => 'limite de pessoas',
            'limite_catracas' => 'limite de catracas', 'ordem' => 'ordem de exibição', 'descricao' => 'descrição',
        ]);
    }
}
