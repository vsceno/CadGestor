<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use PDO;

/**
 * Acesso a termo_consentimento, consentimento e biometria.
 *
 * Nenhum método de listagem devolve template, nonce ou hash: só metadados.
 */
final class LgpdRepositorio
{
    public const FINALIDADE = 'controle_acesso_biometrico';

    public function __construct(private readonly PDO $pdo)
    {
    }

    // --- Termos ---------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function termos(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT t.id, t.versao, t.finalidade, t.texto, t.hash_texto, t.vigente_desde, t.ativo,
                   (SELECT count(*) FROM consentimento c WHERE c.termo_id = t.id) AS aceites
              FROM termo_consentimento t
             ORDER BY t.vigente_desde DESC
            SQL)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null versão ativa mais recente */
    public function termoVigente(): ?array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT * FROM termo_consentimento
             WHERE finalidade = :f AND ativo AND vigente_desde <= now()
             ORDER BY vigente_desde DESC LIMIT 1
            SQL);
        $consulta->execute(['f' => self::FINALIDADE]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function versaoTermoExiste(string $versao): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM termo_consentimento WHERE finalidade = :f AND versao = :v');
        $consulta->execute(['f' => self::FINALIDADE, 'v' => $versao]);

        return $consulta->fetchColumn() !== false;
    }

    public function inserirTermo(string $versao, string $texto): string
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO termo_consentimento (versao, finalidade, texto, hash_texto)
            VALUES (:v, :f, :t, :h) RETURNING id
            SQL);
        $consulta->execute(['v' => $versao, 'f' => self::FINALIDADE, 't' => $texto, 'h' => hash('sha256', $texto)]);

        return (string) $consulta->fetchColumn();
    }

    // --- Consentimentos -------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function consentimentoAtivo(string $pessoaId): ?array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT c.*,
                   COALESCE(u.nome, CASE WHEN c.registrado_por IS NOT NULL THEN 'Suporte da plataforma' END)
                       AS registrado_por_nome
              FROM consentimento c LEFT JOIN usuario_sistema u ON u.id = c.registrado_por
             WHERE c.pessoa_id = :p AND c.finalidade = :f AND c.revogado_em IS NULL
            SQL);
        $consulta->execute(['p' => $pessoaId, 'f' => self::FINALIDADE]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> histórico de consentimentos (inclusive revogados) */
    public function consentimentos(string $pessoaId): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT id, versao_termo, aceito_em, revogado_em, canal, titular_menor
              FROM consentimento WHERE pessoa_id = :p ORDER BY aceito_em DESC
            SQL);
        $consulta->execute(['p' => $pessoaId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $dados */
    public function inserirConsentimento(array $dados): string
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO consentimento (pessoa_id, termo_id, finalidade, versao_termo, hash_termo, ip, canal,
                                       titular_menor, responsavel_nome, responsavel_cpf, responsavel_parentesco,
                                       registrado_por)
            VALUES (:pessoa, :termo, :finalidade, :versao, :hash, CAST(:ip AS inet), :canal,
                    :menor, :resp_nome, :resp_cpf, :resp_parentesco, :registrado_por)
            RETURNING id
            SQL);
        $consulta->execute($dados + ['finalidade' => self::FINALIDADE]);

        return (string) $consulta->fetchColumn();
    }

    public function revogarConsentimento(string $id, string $ip): void
    {
        $this->pdo->prepare(<<<'SQL'
            UPDATE consentimento SET revogado_em = now(), revogacao_ip = CAST(:ip AS inet)
             WHERE id = :id AND revogado_em IS NULL
            SQL)->execute(['id' => $id, 'ip' => $ip]);
    }

    // --- Biometrias -----------------------------------------------------------

    /** @return list<array<string, mixed>> metadados das biometrias ativas (sem template) */
    public function biometriasAtivas(string $pessoaId): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT id, tipo, dedo, versao_chave, created_at
              FROM biometria WHERE pessoa_id = :p AND ativo ORDER BY tipo, dedo
            SQL);
        $consulta->execute(['p' => $pessoaId]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null registro completo (inclui template cifrado) */
    public function biometria(string $id): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM biometria WHERE id = :id');
        $consulta->execute(['id' => $id]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Templates cifrados ativos de um tipo, de pessoas com vínculo não encerrado
     * na empresa (candidatos para identificação 1:N no servidor).
     *
     * @return list<array<string, mixed>>
     */
    public function candidatosDaEmpresa(string $empresaId, string $tipo): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT DISTINCT b.id, b.pessoa_id, b.tipo, b.dedo, b.template_cifrado, b.nonce, b.versao_chave
              FROM biometria b
              JOIN vinculo v ON v.pessoa_id = b.pessoa_id AND v.empresa_id = :e AND v.status <> 'encerrado'
             WHERE b.ativo AND b.tipo = :t
            SQL);
        $consulta->execute(['e' => $empresaId, 't' => $tipo]);

        return array_map(static function (array $linha): array {
            foreach (['template_cifrado', 'nonce'] as $campo) {
                if (is_resource($linha[$campo])) {
                    $linha[$campo] = stream_get_contents($linha[$campo]);
                }
            }

            return $linha;
        }, $consulta->fetchAll(PDO::FETCH_ASSOC));
    }

    public function existeBiometriaAtiva(string $pessoaId, string $tipo): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM biometria WHERE pessoa_id = :p AND tipo = :t AND ativo');
        $consulta->execute(['p' => $pessoaId, 't' => $tipo]);

        return $consulta->fetchColumn() !== false;
    }

    public function inserirBiometria(
        string $pessoaId,
        string $consentimentoId,
        string $tipo,
        ?int $dedo,
        string $cifrado,
        string $nonce,
        int $versaoChave,
        string $hash,
    ): string {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO biometria (pessoa_id, consentimento_id, tipo, dedo, template_cifrado, nonce, versao_chave,
                                   hash_integridade)
            VALUES (:pessoa, :consentimento, :tipo, :dedo, :cifrado, :nonce, :versao, :hash)
            RETURNING id
            SQL);
        $consulta->bindValue('pessoa', $pessoaId);
        $consulta->bindValue('consentimento', $consentimentoId);
        $consulta->bindValue('tipo', $tipo);
        $consulta->bindValue('dedo', $dedo, $dedo === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $consulta->bindValue('cifrado', $cifrado, PDO::PARAM_LOB);
        $consulta->bindValue('nonce', $nonce, PDO::PARAM_LOB);
        $consulta->bindValue('versao', $versaoChave, PDO::PARAM_INT);
        $consulta->bindValue('hash', $hash);
        $consulta->execute();

        return (string) $consulta->fetchColumn();
    }

    /**
     * Exclusão DE FATO: apaga template, nonce e hash (não é só uma marcação).
     *
     * @return list<string> IDs das biometrias excluídas
     */
    public function excluirBiometrias(string $pessoaId, string $motivo, ?string $somenteId = null): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            UPDATE biometria
               SET ativo = false, excluido_em = now(), motivo_exclusao = :motivo,
                   template_cifrado = NULL, nonce = NULL, hash_integridade = NULL
             WHERE pessoa_id = :p AND ativo AND (CAST(:id AS uuid) IS NULL OR id = CAST(:id AS uuid))
            RETURNING id
            SQL);
        $consulta->execute(['p' => $pessoaId, 'motivo' => $motivo, 'id' => $somenteId]);

        return array_map('strval', $consulta->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return int operações de sincronização pendentes da pessoa */
    public function sincronizacoesPendentes(string $pessoaId): int
    {
        $consulta = $this->pdo->prepare(
            "SELECT count(*) FROM fila_sincronizacao WHERE pessoa_id = :p AND status IN ('pendente', 'falhou')"
        );
        $consulta->execute(['p' => $pessoaId]);

        return (int) $consulta->fetchColumn();
    }
}
