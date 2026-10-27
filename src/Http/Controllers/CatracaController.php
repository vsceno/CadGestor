<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\CatracaRepositorio;
use CadGestor\Repositorios\EmpresaRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\CatracaServico;

/**
 * Cadastro de catracas e gestão do token de dispositivo.
 */
final class CatracaController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly CatracaRepositorio $catracas,
        private readonly EmpresaRepositorio $empresas,
        private readonly CatracaServico $servico,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        $empresaId = $requisicao->consulta('empresa');
        $empresaId = preg_match('/^[0-9a-f-]{36}$/i', $empresaId) === 1 ? $empresaId : null;

        return $this->pagina('catracas/index', [
            'titulo' => 'Catracas',
            'catracas' => $this->catracas->listar($empresaId),
            'empresas' => $this->empresas->opcoes(),
            'empresaId' => $empresaId,
        ]);
    }

    /** @param array<string, string> $parametros */
    public function novo(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->formulario(null, [
            'empresa_id' => $requisicao->consulta('empresa'),
            'tipo_identificacao' => 'digital_facial',
            'modo_falha' => 'bloquear',
        ], []);
    }

    /** @param array<string, string> $parametros */
    public function criar(Requisicao $requisicao, array $parametros): Resposta
    {
        $entrada = $this->entrada($requisicao);

        try {
            $resultado = $this->servico->criar($entrada);
        } catch (ErroValidacao $erro) {
            return $this->formulario(null, $entrada, $erro->erros, 422);
        }

        return $this->tokenGerado($resultado['id'], $resultado['token'], 'Catraca cadastrada');
    }

    /** @param array<string, string> $parametros */
    public function editar(Requisicao $requisicao, array $parametros): Resposta
    {
        $catraca = $this->catracas->buscar($parametros['id']);

        return $catraca === null ? $this->naoEncontrado() : $this->formulario($parametros['id'], $catraca, []);
    }

    /** @param array<string, string> $parametros */
    public function atualizar(Requisicao $requisicao, array $parametros): Resposta
    {
        $entrada = $this->entrada($requisicao);

        try {
            $this->servico->atualizar($parametros['id'], $entrada);
        } catch (ErroValidacao $erro) {
            $atual = $this->catracas->buscar($parametros['id']) ?? [];

            return $this->formulario($parametros['id'], $entrada + $atual, $erro->erros, 422);
        }

        return $this->redirecionar("/catracas/{$parametros['id']}/editar", 'Catraca atualizada.');
    }

    /** @param array<string, string> $parametros */
    public function renovarToken(Requisicao $requisicao, array $parametros): Resposta
    {
        if ($this->catracas->buscar($parametros['id']) === null) {
            return $this->naoEncontrado();
        }

        $token = $this->servico->renovarToken($parametros['id']);

        return $this->tokenGerado($parametros['id'], $token, 'Novo token gerado');
    }

    /** @param array<string, string> $parametros */
    public function alterarSituacao(Requisicao $requisicao, array $parametros): Resposta
    {
        $ativar = $requisicao->entrada('ativo') === '1';
        $this->servico->definirAtivo($parametros['id'], $ativar);

        return $this->redirecionar(
            "/catracas/{$parametros['id']}/editar",
            $ativar ? 'Catraca reativada.' : 'Catraca inativada: ela passa a negar todos os acessos.',
        );
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(?string $id, array $dados, array $erros, int $status = 200): Resposta
    {
        return $this->pagina('catracas/formulario', [
            'titulo' => $id === null ? 'Nova catraca' : 'Catraca: ' . ($dados['nome'] ?? ''),
            'id' => $id,
            'dados' => $dados,
            'erros' => $erros,
            'empresas' => $this->empresas->opcoes(),
            'tipos' => CatracaServico::TIPOS_IDENTIFICACAO,
            'modos' => CatracaServico::MODOS_FALHA,
        ], $status);
    }

    /** O token aparece só nesta resposta; o banco guarda apenas o hash. */
    private function tokenGerado(string $id, string $token, string $titulo): Resposta
    {
        return $this->pagina('catracas/token', [
            'titulo' => $titulo,
            'catracaId' => $id,
            'token' => $token,
        ]);
    }

    /** @return array<string, string> */
    private function entrada(Requisicao $requisicao): array
    {
        $dados = [];

        foreach (CatracaRepositorio::CAMPOS as $campo) {
            $dados[$campo] = $requisicao->entrada($campo);
        }

        return $dados;
    }
}
