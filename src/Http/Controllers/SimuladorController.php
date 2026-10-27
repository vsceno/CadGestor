<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Acesso\AcessoServico;
use CadGestor\Acesso\ResultadoDecisao;
use CadGestor\Biometria\TipoBiometria;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\CatracaRepositorio;
use CadGestor\Repositorios\EventoRepositorio;
use CadGestor\Seguranca\Sessao;
use PDO;

/**
 * Simulador de catraca (somente fora de produção, administrador).
 *
 * Usa o MESMO AcessoServico da API, só que sem o token: serve para testar
 * regras, horários e motivos de negação pelo navegador. Os eventos gerados
 * são reais (ficam no banco de desenvolvimento).
 */
final class SimuladorController extends Controlador
{
    public const CENARIOS = [
        'confere' => 'Biometria reconhecida com alta confiança (0,97)',
        'baixa' => 'Reconhecida com baixa confiança (0,40)',
        'ninguem' => 'Ninguém reconhecido',
    ];

    public function __construct(
        Sessao $sessao,
        private readonly PDO $pdo,
        private readonly CatracaRepositorio $catracas,
        private readonly EventoRepositorio $eventos,
        private readonly AcessoServico $acesso,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->tela([], null);
    }

    /** @param array<string, string> $parametros */
    public function simular(Requisicao $requisicao, array $parametros): Resposta
    {
        $entrada = [
            'catraca' => $requisicao->entrada('catraca'),
            'pessoa' => $requisicao->entrada('pessoa'),
            'tipo' => $requisicao->entrada('tipo', 'digital'),
            'cenario' => $requisicao->entrada('cenario', 'confere'),
        ];
        $catraca = preg_match('/^[0-9a-f-]{36}$/', $entrada['catraca']) === 1
            ? $this->catracas->buscar($entrada['catraca'])
            : null;
        $tipo = TipoBiometria::tryFrom($entrada['tipo']) ?? TipoBiometria::Digital;

        if ($catraca === null) {
            return $this->redirecionar('/simulador', 'Selecione uma catraca.', 'erro');
        }

        [$pessoa, $score] = match ($entrada['cenario']) {
            'baixa' => [$entrada['pessoa'] ?: null, 0.40],
            'ninguem' => [null, 0.0],
            default => [$entrada['pessoa'] ?: null, 0.97],
        };

        $r = $this->acesso->processar($catraca, $tipo, $pessoa, $score, null);

        return $this->tela($entrada, [
            'liberado' => $r['resultado']->liberado,
            'motivo' => ResultadoDecisao::rotuloMotivo($r['resultado']->motivo),
            'codigo' => $r['resultado']->motivo,
            'evento_id' => $r['evento_id'],
            'data_hora' => $r['data_hora']->format('d/m/Y H:i:s'),
        ]);
    }

    /**
     * @param array<string, string>      $entrada
     * @param array<string, mixed>|null  $resultado
     */
    private function tela(array $entrada, ?array $resultado): Resposta
    {
        $pessoas = $this->pdo->query(<<<'SQL'
            SELECT DISTINCT p.id, p.nome FROM pessoa p
              JOIN vinculo v ON v.pessoa_id = p.id
             WHERE p.anonimizada_em IS NULL
             ORDER BY p.nome
            SQL)->fetchAll(PDO::FETCH_ASSOC);

        return $this->pagina('simulador/index', [
            'titulo' => 'Simulador de catraca',
            'catracas' => $this->catracas->listar(),
            'pessoas' => $pessoas,
            'cenarios' => self::CENARIOS,
            'entrada' => $entrada,
            'resultado' => $resultado,
            'eventos' => $this->eventos->recentes(15),
        ]);
    }
}
