<?php

declare(strict_types=1);

namespace CadGestor\Repositorios;

use PDO;

/**
 * Planos, assinatura e faturas.
 *
 * Planos são um catálogo único; assinatura e faturas seguem o RLS: o cliente
 * só vê as dele, a plataforma vê todas.
 */
final class AssinaturaRepositorio
{
    public const CAMPOS_PLANO = [
        'codigo', 'nome', 'descricao', 'preco_centavos', 'limite_pessoas', 'limite_catracas', 'disponivel_venda',
        'ordem',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    // --- Planos ------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function planos(bool $somenteAVenda = false): array
    {
        $filtro = $somenteAVenda ? 'WHERE disponivel_venda' : '';

        return $this->pdo->query("SELECT * FROM plano {$filtro} ORDER BY ordem, preco_centavos")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function plano(string $id): ?array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) !== 1) {
            return null;
        }

        $consulta = $this->pdo->prepare('SELECT * FROM plano WHERE id = :id');
        $consulta->execute(['id' => $id]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string, mixed>|null */
    public function planoPorCodigo(string $codigo): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM plano WHERE codigo = :codigo');
        $consulta->execute(['codigo' => $codigo]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param array<string, mixed> $dados */
    public function inserirPlano(array $dados): string
    {
        $colunas = implode(', ', self::CAMPOS_PLANO);
        $valores = implode(', ', array_map(static fn (string $c): string => ':' . $c, self::CAMPOS_PLANO));
        $consulta = $this->pdo->prepare("INSERT INTO plano ({$colunas}) VALUES ({$valores}) RETURNING id");
        $consulta->execute(self::parametrosPlano($dados));

        return (string) $consulta->fetchColumn();
    }

    /** @param array<string, mixed> $dados */
    public function atualizarPlano(string $id, array $dados): void
    {
        $campos = array_diff(self::CAMPOS_PLANO, ['codigo']);
        $atribuicoes = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", $campos));
        $this->pdo->prepare("UPDATE plano SET {$atribuicoes} WHERE id = :id")
            ->execute(array_diff_key(self::parametrosPlano($dados), ['codigo' => true]) + ['id' => $id]);
    }

    // --- Assinatura --------------------------------------------------------

    /**
     * Assinatura da organização do contexto (ou a informada, no modo plataforma), com o plano.
     *
     * @return array<string, mixed>|null
     */
    public function daOrganizacao(?string $organizacaoId = null): ?array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT a.*, p.codigo AS plano_codigo, p.nome AS plano_nome, p.preco_centavos,
                   p.limite_pessoas, p.limite_catracas
              FROM assinatura a JOIN plano p ON p.id = a.plano_id
             WHERE a.organizacao_id = COALESCE(CAST(:organizacao AS uuid), cg_org_atual())
            SQL);
        $consulta->execute(['organizacao' => $organizacaoId]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Status de todas as assinaturas (painel da plataforma). @return array<string, array<string, mixed>> */
    public function resumoPorOrganizacao(): array
    {
        $linhas = $this->pdo->query(<<<'SQL'
            SELECT a.organizacao_id, a.status, a.teste_ate::text AS teste_ate,
                   a.inadimplente_desde::text AS inadimplente_desde, p.nome AS plano_nome, p.preco_centavos
              FROM assinatura a JOIN plano p ON p.id = a.plano_id
            SQL)->fetchAll(PDO::FETCH_ASSOC);

        return array_column($linhas, null, 'organizacao_id');
    }

    public function inserir(string $planoId, string $status, ?string $testeAte, ?string $organizacaoId = null): string
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            INSERT INTO assinatura (organizacao_id, plano_id, status, teste_ate)
            VALUES (COALESCE(CAST(:organizacao AS uuid), cg_org_atual()), :plano, :status, :teste)
            RETURNING id
            SQL);
        $consulta->execute([
            'organizacao' => $organizacaoId,
            'plano' => $planoId,
            'status' => $status,
            'teste' => $testeAte,
        ]);

        return (string) $consulta->fetchColumn();
    }

    public function definirGateway(string $id, string $gateway, string $clienteId, string $assinaturaId): void
    {
        $this->pdo->prepare(<<<'SQL'
            UPDATE assinatura SET gateway = :g, gateway_cliente_id = :c, gateway_assinatura_id = :a WHERE id = :id
            SQL)->execute(['id' => $id, 'g' => $gateway, 'c' => $clienteId, 'a' => $assinaturaId]);
    }

    public function definirPlano(string $id, string $planoId): void
    {
        $this->pdo->prepare('UPDATE assinatura SET plano_id = :p WHERE id = :id')
            ->execute(['id' => $id, 'p' => $planoId]);
    }

    /** Pagamento em dia: volta a "ativa" (assinatura cancelada continua cancelada). */
    public function marcarEmDia(string $id): void
    {
        $this->pdo->prepare(<<<'SQL'
            UPDATE assinatura SET status = 'ativa', inadimplente_desde = NULL, teste_ate = NULL
             WHERE id = :id AND status <> 'cancelada'
            SQL)->execute(['id' => $id]);
    }

    /** Cobrança vencida: inadimplente desde o dia seguinte ao vencimento (mantém a data mais antiga). */
    public function marcarInadimplente(string $id, string $desde): void
    {
        $this->pdo->prepare(<<<'SQL'
            UPDATE assinatura
               SET status = 'inadimplente',
                   inadimplente_desde = LEAST(COALESCE(inadimplente_desde, CAST(:d AS date)), CAST(:d AS date))
             WHERE id = :id AND status <> 'cancelada'
            SQL)->execute(['id' => $id, 'd' => $desde]);
    }

    public function cancelar(string $id): void
    {
        $this->pdo->prepare(<<<'SQL'
            UPDATE assinatura SET status = 'cancelada', cancelada_em = now() WHERE id = :id AND status <> 'cancelada'
            SQL)->execute(['id' => $id]);
    }

    // --- Faturas -------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function faturas(?string $organizacaoId = null, int $limite = 24): array
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT id, valor_centavos, vencimento::text AS vencimento, status, link_pagamento, pago_em,
                   gateway_cobranca_id
              FROM fatura
             WHERE organizacao_id = COALESCE(CAST(:organizacao AS uuid), cg_org_atual())
             ORDER BY vencimento DESC, created_at DESC
             LIMIT :limite
            SQL);
        $consulta->execute(['organizacao' => $organizacaoId, 'limite' => $limite]);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function faturaPorCobranca(string $gateway, string $cobrancaId): ?array
    {
        $consulta = $this->pdo->prepare('SELECT * FROM fatura WHERE gateway = :g AND gateway_cobranca_id = :c');
        $consulta->execute(['g' => $gateway, 'c' => $cobrancaId]);

        return $consulta->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Cria a fatura se ainda não existir (o mesmo aviso pode chegar mais de uma vez). */
    public function registrarFatura(
        string $assinaturaId,
        string $gateway,
        string $cobrancaId,
        int $valorCentavos,
        string $vencimento,
        ?string $link,
    ): void {
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO fatura (assinatura_id, gateway, gateway_cobranca_id, valor_centavos, vencimento, link_pagamento)
            VALUES (:a, :g, :c, :v, :venc, :link)
            ON CONFLICT (gateway, gateway_cobranca_id) DO NOTHING
            SQL)->execute([
            'a' => $assinaturaId,
            'g' => $gateway,
            'c' => $cobrancaId,
            'v' => $valorCentavos,
            'venc' => $vencimento,
            // Só links do próprio sistema (simulado) ou HTTPS: nunca javascript: etc.
            'link' => $link !== null && preg_match('#^(https://|/)#', $link) === 1 ? mb_substr($link, 0, 500) : null,
        ]);
    }

    public function definirStatusFatura(string $gateway, string $cobrancaId, string $status): void
    {
        $this->pdo->prepare(<<<'SQL'
            UPDATE fatura
               SET status = CAST(:s AS varchar),
                   pago_em = CASE WHEN CAST(:s AS varchar) = 'paga' THEN COALESCE(pago_em, now()) END
             WHERE gateway = :g AND gateway_cobranca_id = :c AND status <> 'paga'
            SQL)->execute(['s' => $status, 'g' => $gateway, 'c' => $cobrancaId]);
    }

    // --- Uso (para os limites do plano) ----------------------------------------

    /** @return array{pessoas: int, catracas: int} */
    public function uso(): array
    {
        $linha = $this->pdo->query(<<<'SQL'
            SELECT (SELECT count(*) FROM pessoa
                     WHERE ativo AND anonimizada_em IS NULL AND organizacao_id = cg_org_atual()) AS pessoas,
                   (SELECT count(*) FROM catraca WHERE ativo AND organizacao_id = cg_org_atual()) AS catracas
            SQL)->fetch(PDO::FETCH_ASSOC);

        return ['pessoas' => (int) $linha['pessoas'], 'catracas' => (int) $linha['catracas']];
    }

    /**
     * Dados de cadastro do cliente para exportação (portabilidade, LGPD art. 18).
     *
     * @return list<array<string, mixed>>
     */
    public function exportacaoCadastros(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT p.nome, p.cpf, p.data_nascimento::text AS data_nascimento, p.email, p.telefone, p.ativo,
                   e.razao_social AS empresa, e.cnpj AS empresa_cnpj, pv.nome AS perfil, v.status AS vinculo_status,
                   v.data_inicio::text AS data_inicio, v.data_fim::text AS data_fim
              FROM pessoa p
              LEFT JOIN vinculo v ON v.pessoa_id = p.id
              LEFT JOIN empresa e ON e.id = v.empresa_id
              LEFT JOIN perfil_vinculo pv ON pv.id = v.perfil_id
             WHERE p.anonimizada_em IS NULL
             ORDER BY p.nome, e.razao_social
            SQL)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $dados
     * @return array<string, mixed>
     */
    private static function parametrosPlano(array $dados): array
    {
        $parametros = array_intersect_key($dados, array_flip(self::CAMPOS_PLANO));
        $parametros['disponivel_venda'] = !empty($dados['disponivel_venda']) ? 'true' : 'false';

        return $parametros;
    }
}
