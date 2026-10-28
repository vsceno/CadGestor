<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Acesso\AcessoServico;
use CadGestor\Acesso\SincronizacaoDispositivo;
use CadGestor\Banco\ContextoBanco;
use CadGestor\Biometria\TipoBiometria;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\CatracaRepositorio;
use CadGestor\Servicos\CatracaServico;

/**
 * API das catracas (JSON, autenticada por token do dispositivo).
 *
 * Respostas não carregam dados pessoais: só decisão, motivo técnico e IDs.
 */
final class ApiCatracaController
{
    private const TAMANHO_MAXIMO_AMOSTRA = 512 * 1024;

    public function __construct(
        private readonly CatracaRepositorio $catracas,
        private readonly ContextoBanco $contextoBanco,
        private readonly AcessoServico $acesso,
        private readonly SincronizacaoDispositivo $sincronizacao,
        private readonly float $limiarConfianca,
        private readonly string $fuso,
    ) {
    }

    /** POST /api/v1/acesso */
    public function acesso(Requisicao $requisicao): Resposta
    {
        $catraca = $this->autenticar($requisicao);

        if ($catraca instanceof Resposta) {
            return $catraca;
        }

        $dados = $requisicao->json();

        if ($dados === null) {
            return self::erro(400, 'json_invalido', 'Envie um corpo JSON (Content-Type: application/json).');
        }

        $tipo = TipoBiometria::tryFrom((string) ($dados['tipo'] ?? ''));

        if ($tipo === null) {
            return self::erro(400, 'tipo_invalido', 'Campo "tipo" deve ser "digital" ou "facial".');
        }

        $referencia = isset($dados['referencia']) && is_string($dados['referencia']) ? $dados['referencia'] : null;
        $score = isset($dados['score']) && is_numeric($dados['score']) ? (float) $dados['score'] : null;
        $amostra = null;

        if (isset($dados['amostra'])) {
            $amostra = is_string($dados['amostra']) && strlen($dados['amostra']) <= self::TAMANHO_MAXIMO_AMOSTRA
                ? base64_decode($dados['amostra'], true)
                : false;

            if ($amostra === false || $amostra === '') {
                return self::erro(400, 'amostra_invalida', 'Campo "amostra" deve ser base64 válido de até 384 KB.');
            }
        } elseif ($referencia === null && $score === null) {
            return self::erro(400, 'identificacao_ausente', 'Informe "referencia" e "score", ou "amostra".');
        }

        $r = $this->acesso->processar($catraca, $tipo, $referencia, $score, $amostra);

        return Resposta::json([
            'decisao' => $r['resultado']->liberado ? 'liberado' : 'negado',
            'motivo' => $r['resultado']->motivo,
            'evento_id' => $r['evento_id'],
            'data_hora' => $r['data_hora']->format(DATE_ATOM),
        ]);
    }

    /** GET /api/v1/configuracao */
    public function configuracao(Requisicao $requisicao): Resposta
    {
        $catraca = $this->autenticar($requisicao);

        if ($catraca instanceof Resposta) {
            return $catraca;
        }

        $this->catracas->registrarContato((string) $catraca['id']);

        return Resposta::json([
            'catraca_id' => $catraca['id'],
            'ativa' => $catraca['ativo'] === true && $catraca['empresa_ativa'] === true,
            'modo_falha' => $catraca['modo_falha'],
            'tipo_identificacao' => $catraca['tipo_identificacao'],
            'limiar_confianca' => $this->limiarConfianca,
            'fuso' => $this->fuso,
            'servidor_data_hora' => (new \DateTimeImmutable('now', new \DateTimeZone($this->fuso)))->format(DATE_ATOM),
        ]);
    }

    /** GET /api/v1/sincronizacao */
    public function sincronizacao(Requisicao $requisicao): Resposta
    {
        $catraca = $this->autenticar($requisicao);

        if ($catraca instanceof Resposta) {
            return $catraca;
        }

        $this->catracas->registrarContato((string) $catraca['id']);
        $limite = (int) ($requisicao->consulta('limite', '50'));

        return Resposta::json(['operacoes' => $this->sincronizacao->pendentes((string) $catraca['id'], $limite)]);
    }

    /**
     * POST /api/v1/sincronizacao/{id}
     *
     * @param array<string, string> $parametros
     */
    public function confirmarSincronizacao(Requisicao $requisicao, array $parametros): Resposta
    {
        $catraca = $this->autenticar($requisicao);

        if ($catraca instanceof Resposta) {
            return $catraca;
        }

        $dados = $requisicao->json();

        if ($dados === null || !isset($dados['sucesso']) || !is_bool($dados['sucesso'])) {
            return self::erro(400, 'json_invalido', 'Envie {"sucesso": true|false, "erro": "..."}.');
        }

        $erro = isset($dados['erro']) && is_string($dados['erro']) ? $dados['erro'] : null;

        if (!$this->sincronizacao->confirmar((string) $catraca['id'], $parametros['id'], $dados['sucesso'], $erro)) {
            return self::erro(404, 'operacao_inexistente', 'Operação não encontrada para esta catraca.');
        }

        return Resposta::json(['status' => 'ok']);
    }

    /** POST /api/v1/eventos-offline */
    public function eventosOffline(Requisicao $requisicao): Resposta
    {
        $catraca = $this->autenticar($requisicao);

        if ($catraca instanceof Resposta) {
            return $catraca;
        }

        $dados = $requisicao->json();

        if ($dados === null || !isset($dados['eventos']) || !is_array($dados['eventos'])) {
            return self::erro(400, 'json_invalido', 'Envie {"eventos": [ ... ]}.');
        }

        return Resposta::json($this->acesso->registrarOffline($catraca, array_values($dados['eventos'])));
    }

    /** @return array<string, mixed>|Resposta catraca autenticada, ou a resposta 401 */
    private function autenticar(Requisicao $requisicao): array|Resposta
    {
        $cabecalho = $requisicao->cabecalho('Authorization') ?? '';

        $padrao = '/^Bearer\s+(' . preg_quote(CatracaServico::PREFIXO_TOKEN, '/') . '[0-9a-f]{64})$/';

        if (preg_match($padrao, $cabecalho, $m) === 1) {
            // O token identifica a catraca e, com ela, a organização: daqui em
            // diante a requisição só enxerga os dados dessa organização.
            $conta = $this->catracas->localizarPorToken(CatracaServico::hashToken($m[1]));

            if ($conta !== null) {
                $this->contextoBanco->definir($conta['organizacao_id']);
                $catraca = $this->catracas->buscar($conta['catraca_id']);

                if ($catraca !== null) {
                    return $catraca;
                }
            }
        }

        if ($cabecalho !== '') {
            $this->acesso->registrarDispositivoDesconhecido($requisicao->ip());
        }

        return self::erro(401, 'nao_autorizado', 'Token de dispositivo ausente ou inválido.')
            ->comCabecalho('WWW-Authenticate', 'Bearer realm="cadgestor"');
    }

    private static function erro(int $status, string $codigo, string $mensagem): Resposta
    {
        return Resposta::json(['erro' => $codigo, 'mensagem' => $mensagem], $status);
    }
}
