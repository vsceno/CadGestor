<?php

declare(strict_types=1);

namespace CadGestor\Acesso;

use CadGestor\Biometria\IdentificadorBiometrico;
use CadGestor\Biometria\TipoBiometria;
use CadGestor\Repositorios\LgpdRepositorio;
use CadGestor\Seguranca\CifradorBiometria;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Orquestra uma passagem na catraca: identifica a pessoa, pede a decisão à
 * DecisaoAcesso, grava o evento e gera alertas.
 *
 * Dois modos de identificação (depende do fabricante, ainda em aberto):
 *  - "referencia" + "score": o próprio dispositivo reconheceu a pessoa (1:N
 *    ou 1:1 no hardware) e informa a referência e a confiança;
 *  - "amostra": o servidor compara a amostra com os templates da empresa
 *    (1:N no servidor) usando o IdentificadorBiometrico.
 */
final class AcessoServico
{
    /** Negações seguidas na mesma catraca, em 2 minutos, que geram alerta de tentativas repetidas. */
    private const LIMITE_TENTATIVAS = 3;

    /** Confiança mínima para considerar que a identificação no servidor apontou alguém. */
    private const PISO_CANDIDATO = 0.30;

    public function __construct(
        private readonly PDO $pdo,
        private readonly DecisaoAcesso $decisao,
        private readonly LgpdRepositorio $lgpd,
        private readonly IdentificadorBiometrico $identificador,
        private readonly CifradorBiometria $cifrador,
        private readonly DateTimeZone $fuso,
    ) {
    }

    /**
     * @param array{id: string, empresa_id: string} $catraca catraca autenticada pelo token
     * @return array{resultado: ResultadoDecisao, evento_id: string, data_hora: DateTimeImmutable, score: float}
     */
    public function processar(
        array $catraca,
        TipoBiometria $tipo,
        ?string $referencia,
        ?float $score,
        ?string $amostra,
    ): array {
        $agora = new DateTimeImmutable('now', $this->fuso);

        if ($amostra !== null) {
            [$referencia, $score] = $this->identificarNoServidor((string) $catraca['empresa_id'], $tipo, $amostra);
        }

        $pessoaId = $referencia !== null && self::uuidValido($referencia) ? $referencia : null;
        $score = max(0.0, min(1.0, $score ?? 0.0));

        $resultado = $this->decisao->decidir(
            new SolicitacaoAcesso((string) $catraca['id'], $pessoaId, $tipo, $score, $agora),
        );

        $eventoId = $this->registrarEvento($resultado, $tipo, $score, $agora, false);

        if (!$resultado->liberado) {
            $this->alertarNegacao($resultado, $eventoId);
        }

        return ['resultado' => $resultado, 'evento_id' => $eventoId, 'data_hora' => $agora, 'score' => $score];
    }

    /**
     * Eventos decididos pela catraca enquanto estava sem servidor. São
     * gravados como offline, com a data/hora original.
     *
     * @param array{id: string, empresa_id: string} $catraca
     * @param list<array<string, mixed>> $eventos
     * @return array{gravados: int, recusados: int}
     */
    public function registrarOffline(array $catraca, array $eventos): array
    {
        $gravados = 0;
        $recusados = 0;
        $limiteAntigo = new DateTimeImmutable('-30 days', $this->fuso);
        $limiteFuturo = new DateTimeImmutable('+5 minutes', $this->fuso);

        foreach (array_slice($eventos, 0, 500) as $evento) {
            $tipo = TipoBiometria::tryFrom((string) ($evento['tipo'] ?? ''));
            $resultadoTexto = (string) ($evento['resultado'] ?? '');

            try {
                $quando = new DateTimeImmutable((string) ($evento['data_hora'] ?? ''), $this->fuso);
            } catch (\Exception) {
                $quando = null;
            }

            $pessoaId = isset($evento['referencia']) && self::uuidValido((string) $evento['referencia'])
                ? (string) $evento['referencia']
                : null;

            if (
                $tipo === null || $quando === null || $quando < $limiteAntigo || $quando > $limiteFuturo
                || !in_array($resultadoTexto, ['liberado', 'negado'], true)
            ) {
                $recusados++;
                continue;
            }

            $liberado = $resultadoTexto === 'liberado';
            $resultado = new ResultadoDecisao(
                $liberado,
                $liberado ? null : ResultadoDecisao::FALHA_SISTEMA,
                (string) $catraca['id'],
                (string) $catraca['empresa_id'],
                $pessoaId !== null && $this->pessoaExiste($pessoaId) ? $pessoaId : null,
            );

            $this->registrarEvento($resultado, $tipo, null, $quando, true);
            $gravados++;
        }

        return ['gravados' => $gravados, 'recusados' => $recusados];
    }

    /**
     * Token desconhecido: registra o evento negado (sem catraca) e um alerta,
     * com limites para que um ataque não encha as tabelas.
     */
    public function registrarDispositivoDesconhecido(string $ip): void
    {
        $recentes = (int) $this->pdo->query(<<<'SQL'
            SELECT count(*) FROM evento_acesso
             WHERE catraca_id IS NULL AND motivo_negacao = 'catraca_inexistente'
               AND data_hora > now() - interval '1 minute'
            SQL)->fetchColumn();

        if ($recentes >= 30) {
            return;
        }

        $resultado = ResultadoDecisao::negado(ResultadoDecisao::CATRACA_INEXISTENTE);
        $eventoId = $this->registrarEvento($resultado, TipoBiometria::Digital, null, new DateTimeImmutable(), false);

        $this->criarAlertaSemRepetir('dispositivo_desconhecido', null, null, $eventoId, sprintf(
            'Requisição à API com token de dispositivo inválido (origem %s).',
            $ip,
        ), 10);
    }

    /** @return array{0: ?string, 1: float} [referência, score] */
    private function identificarNoServidor(string $empresaId, TipoBiometria $tipo, string $amostra): array
    {
        $candidatos = [];

        foreach ($this->lgpd->candidatosDaEmpresa($empresaId, $tipo->value) as $linha) {
            $dedo = $linha['dedo'] === null ? null : (int) $linha['dedo'];
            $chave = $linha['pessoa_id'] . '#' . $linha['id'];
            $candidatos[$chave] = $this->cifrador->decifrar(
                (string) $linha['template_cifrado'],
                (string) $linha['nonce'],
                (int) $linha['versao_chave'],
                CifradorBiometria::dadosAssociados((string) $linha['pessoa_id'], $tipo->value, $dedo),
            );
        }

        $resultado = $this->identificador->identificar($tipo, $amostra, $candidatos);

        foreach ($candidatos as &$template) {
            sodium_memzero($template);
        }

        sodium_memzero($amostra);

        // Abaixo do piso, o "melhor candidato" é só ruído: tratar como ninguém
        // reconhecido evita atribuir o evento à pessoa errada.
        if ($resultado->referencia === null || $resultado->score < self::PISO_CANDIDATO) {
            return [null, $resultado->score];
        }

        return [explode('#', $resultado->referencia)[0], $resultado->score];
    }

    private function registrarEvento(
        ResultadoDecisao $r,
        TipoBiometria $tipo,
        ?float $score,
        DateTimeImmutable $quando,
        bool $offline,
    ): string {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO evento_acesso (catraca_id, empresa_id, pessoa_id, vinculo_id, perfil_id, data_hora,
                                       tipo_identificacao, resultado, motivo_negacao, score, offline)
            VALUES (:catraca, :empresa, :pessoa, :vinculo, :perfil, :quando, :tipo, :resultado, :motivo,
                    :score, :offline)
            RETURNING id
            SQL);
        $consulta->execute([
            'catraca' => $r->catracaId,
            'empresa' => $r->empresaId,
            'pessoa' => $r->pessoaId,
            'vinculo' => $r->vinculoId,
            'perfil' => $r->perfilId,
            'quando' => $quando->format('Y-m-d H:i:s.uP'),
            'tipo' => $tipo->value,
            'resultado' => $r->liberado ? 'liberado' : 'negado',
            'motivo' => $r->motivo,
            'score' => $score === null ? null : number_format($score, 4, '.', ''),
            'offline' => $offline ? 'true' : 'false',
        ]);

        if ($r->catracaId !== null) {
            $this->pdo->prepare('UPDATE catraca SET ultimo_contato_em = now() WHERE id = :id')
                ->execute(['id' => $r->catracaId]);
        }

        return (string) $consulta->fetchColumn();
    }

    private function alertarNegacao(ResultadoDecisao $r, string $eventoId): void
    {
        $this->criarAlerta('acesso_negado', $r->catracaId, $r->pessoaId, $eventoId, 'Acesso negado: '
            . ResultadoDecisao::rotuloMotivo($r->motivo) . '.');

        if ($r->catracaId === null) {
            return;
        }

        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT count(*) FROM evento_acesso
             WHERE catraca_id = :c AND resultado = 'negado' AND data_hora > now() - interval '2 minutes'
            SQL);
        $consulta->execute(['c' => $r->catracaId]);

        if ((int) $consulta->fetchColumn() >= self::LIMITE_TENTATIVAS) {
            $this->criarAlertaSemRepetir('tentativas_repetidas', $r->catracaId, null, $eventoId, sprintf(
                '%d ou mais acessos negados em menos de 2 minutos nesta catraca.',
                self::LIMITE_TENTATIVAS,
            ), 5);
        }
    }

    private function criarAlertaSemRepetir(
        string $tipo,
        ?string $catracaId,
        ?string $pessoaId,
        ?string $eventoId,
        string $descricao,
        int $janelaMinutos,
    ): void {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT 1 FROM alerta
             WHERE tipo = :tipo AND catraca_id IS NOT DISTINCT FROM CAST(:catraca AS uuid) AND tratado_em IS NULL
               AND data_hora > now() - make_interval(mins => :janela)
            SQL);
        $consulta->execute(['tipo' => $tipo, 'catraca' => $catracaId, 'janela' => $janelaMinutos]);

        if ($consulta->fetchColumn() === false) {
            $this->criarAlerta($tipo, $catracaId, $pessoaId, $eventoId, $descricao);
        }
    }

    private function criarAlerta(
        string $tipo,
        ?string $catracaId,
        ?string $pessoaId,
        ?string $eventoId,
        string $descricao,
    ): void {
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO alerta (tipo, descricao, catraca_id, pessoa_id, evento_id)
            VALUES (:tipo, :descricao, :catraca, :pessoa, :evento)
            SQL)->execute([
            'tipo' => $tipo,
            'descricao' => mb_substr($descricao, 0, 500),
            'catraca' => $catracaId,
            'pessoa' => $pessoaId,
            'evento' => $eventoId,
        ]);
    }

    private function pessoaExiste(string $pessoaId): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM pessoa WHERE id = :id');
        $consulta->execute(['id' => $pessoaId]);

        return $consulta->fetchColumn() !== false;
    }

    private static function uuidValido(string $valor): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $valor) === 1;
    }
}
