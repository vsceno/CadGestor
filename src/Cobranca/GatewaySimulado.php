<?php

declare(strict_types=1);

namespace CadGestor\Cobranca;

use CadGestor\Nucleo\Requisicao;
use DateTimeImmutable;

/**
 * Gateway de mentira, para desenvolvimento e testes. NÃO pode ir para produção
 * (o container recusa). Não fala com nenhum serviço externo.
 *
 * As cobranças apontam para /pagamento-simulado/{id}, onde é possível
 * "pagar" ou "deixar vencer" e ver o sistema reagir como reagiria ao gateway real.
 *
 * Webhook simulado: POST /api/v1/pagamentos/webhook com o cabeçalho
 * X-Webhook-Token igual a PAGAMENTO_WEBHOOK_TOKEN e o corpo
 * {"id","tipo","assinatura_id","cobranca":{"id","valor_centavos","vencimento","link"}}.
 */
final class GatewaySimulado implements GatewayPagamento
{
    public const NOME = 'simulado';

    public function __construct(#[\SensitiveParameter] private readonly string $tokenWebhook)
    {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function criarAssinatura(
        array $cliente,
        array $plano,
        DateTimeImmutable $primeiroVencimento,
    ): AssinaturaGateway {
        $cobranca = self::novaCobranca($plano['preco_centavos'], $primeiroVencimento->format('Y-m-d'));

        return new AssinaturaGateway(
            'sim_cli_' . bin2hex(random_bytes(8)),
            'sim_ass_' . bin2hex(random_bytes(8)),
            $cobranca,
        );
    }

    public function alterarPlano(string $assinaturaId, array $plano): void
    {
        // No gateway real, o novo valor vale a partir da próxima cobrança.
    }

    public function cancelarAssinatura(string $assinaturaId): void
    {
    }

    public function interpretarWebhook(Requisicao $requisicao): ?EventoPagamento
    {
        $token = (string) $requisicao->cabecalho('X-Webhook-Token');

        if ($this->tokenWebhook === '' || !hash_equals($this->tokenWebhook, $token)) {
            throw new WebhookInvalido('Token do webhook inválido.');
        }

        $dados = $requisicao->json();
        $cobranca = is_array($dados['cobranca'] ?? null) ? $dados['cobranca'] : [];

        if (
            !is_string($dados['id'] ?? null) || !in_array($dados['tipo'] ?? null, EventoPagamento::TIPOS, true)
            || !is_string($cobranca['id'] ?? null) || !is_int($cobranca['valor_centavos'] ?? null)
            || !is_string($cobranca['vencimento'] ?? null)
            || DateTimeImmutable::createFromFormat('!Y-m-d', $cobranca['vencimento']) === false
        ) {
            return null;
        }

        return new EventoPagamento(
            mb_substr($dados['id'], 0, 150),
            $dados['tipo'],
            is_string($dados['assinatura_id'] ?? null) ? $dados['assinatura_id'] : null,
            new CobrancaGateway(
                mb_substr($cobranca['id'], 0, 100),
                $cobranca['valor_centavos'],
                $cobranca['vencimento'],
                is_string($cobranca['link'] ?? null) ? $cobranca['link'] : null,
            ),
        );
    }

    /** Cobrança com id em formato UUID, para caber nas rotas (que só aceitam UUID). */
    public static function novaCobranca(int $valorCentavos, string $vencimento): CobrancaGateway
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

        return new CobrancaGateway($id, $valorCentavos, $vencimento, '/pagamento-simulado/' . $id);
    }
}
