<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Cobranca\GatewayPagamento;
use CadGestor\Dominio\SituacaoAssinatura;
use CadGestor\Nucleo\Csv;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\AssinaturaRepositorio;
use DateTimeImmutable;
use PDO;

/**
 * "Minha assinatura": o que o próprio cliente faz com o plano dele.
 */
final class AssinaturaServico
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly GatewayPagamento $gateway,
        private readonly Auditoria $auditoria,
    ) {
    }

    public function situacao(?DateTimeImmutable $hoje = null): SituacaoAssinatura
    {
        $assinatura = $this->assinaturas->daOrganizacao();

        if ($assinatura === null) {
            return SituacaoAssinatura::semAssinatura();
        }

        return SituacaoAssinatura::calcular(
            (string) $assinatura['status'],
            $assinatura['teste_ate'] === null ? null : (string) $assinatura['teste_ate'],
            $assinatura['inadimplente_desde'] === null ? null : (string) $assinatura['inadimplente_desde'],
            $hoje ?? new DateTimeImmutable('today'),
        );
    }

    /** Upgrade ou downgrade. O downgrade só passa se o uso atual couber no plano novo. */
    public function trocarPlano(string $planoId): void
    {
        $assinatura = $this->ativa();
        $plano = $this->assinaturas->plano($planoId);

        if ($plano === null || $plano['disponivel_venda'] !== true) {
            throw ErroValidacao::campo('plano', 'Plano indisponível.');
        }

        if ($plano['id'] === $assinatura['plano_id']) {
            throw ErroValidacao::campo('plano', 'Este já é o seu plano.');
        }

        $uso = $this->assinaturas->uso();

        if ($uso['pessoas'] > $plano['limite_pessoas'] || $uso['catracas'] > $plano['limite_catracas']) {
            throw ErroValidacao::campo('plano', sprintf(
                'Seu uso atual (%d pessoas e %d catracas ativas) não cabe no plano %s. Inative o excedente antes.',
                $uso['pessoas'],
                $uso['catracas'],
                $plano['nome'],
            ));
        }

        Transacao::executar($this->pdo, function () use ($assinatura, $plano): void {
            $this->assinaturas->definirPlano((string) $assinatura['id'], (string) $plano['id']);

            if ($assinatura['gateway_assinatura_id'] !== null) {
                $this->gateway->alterarPlano((string) $assinatura['gateway_assinatura_id'], self::dadosPlano($plano));
            }

            $this->auditoria->registrar('assinatura_alterada', 'assinatura', (string) $assinatura['id'], [
                'plano' => $assinatura['plano_codigo'],
            ], ['plano' => $plano['codigo']]);
        });
    }

    public function cancelar(): void
    {
        $assinatura = $this->ativa();

        Transacao::executar($this->pdo, function () use ($assinatura): void {
            $this->assinaturas->cancelar((string) $assinatura['id']);

            if ($assinatura['gateway_assinatura_id'] !== null) {
                $this->gateway->cancelarAssinatura((string) $assinatura['gateway_assinatura_id']);
            }

            $this->auditoria->registrar('assinatura_alterada', 'assinatura', (string) $assinatura['id'], [
                'status' => $assinatura['status'],
            ], ['status' => 'cancelada']);
        });
    }

    /** CSV com os cadastros (pessoas e vínculos). A trilha registra só o fato. */
    public function exportarCadastros(): string
    {
        $linhas = $this->assinaturas->exportacaoCadastros();
        $this->auditoria->registrar('exportar', 'pessoa', null, null, [
            'relatorio' => 'cadastros_completos',
            'linhas' => count($linhas),
        ]);

        return Csv::gerar(
            ['Nome', 'CPF', 'Nascimento', 'E-mail', 'Telefone', 'Pessoa ativa', 'Empresa', 'CNPJ da empresa',
                'Perfil', 'Situação do vínculo', 'Início', 'Fim'],
            array_map('array_values', $linhas),
        );
    }

    /**
     * @param array<string, mixed> $plano
     * @return array{codigo: string, nome: string, preco_centavos: int}
     */
    public static function dadosPlano(array $plano): array
    {
        return [
            'codigo' => (string) $plano['codigo'],
            'nome' => (string) $plano['nome'],
            'preco_centavos' => (int) $plano['preco_centavos'],
        ];
    }

    /** @return array<string, mixed> */
    private function ativa(): array
    {
        $assinatura = $this->assinaturas->daOrganizacao();

        if ($assinatura === null || $assinatura['status'] === 'cancelada') {
            throw ErroValidacao::campo('plano', 'Assinatura cancelada. Fale com o comercial para contratar de novo.');
        }

        return $assinatura;
    }
}
