<?php

declare(strict_types=1);

namespace CadGestor\Dominio;

use DateTimeImmutable;

/**
 * O que o cliente pode fazer, conforme a assinatura e as datas.
 *
 * É calculada na hora (não gravada), como o semáforo que muda sozinho com o
 * relógio: não depende de nenhum job rodar para a situação ficar certa.
 *
 * Régua de inadimplência (PRAZOS PROVISÓRIOS, a validar com o negócio):
 *   dias  0 a  6 de atraso -> normal, com aviso;
 *   dias  7 a 14           -> somente leitura (nada pode ser alterado);
 *   dias 15 a 29           -> suspensa: só a tela "Minha assinatura" abre,
 *                             mas as CATRACAS CONTINUAM FUNCIONANDO (bloquear a
 *                             porta de alguém é grave e afeta terceiros);
 *   dia 30 em diante       -> bloqueada: as catracas também param.
 * Teste grátis vencido sem pagamento conta como atraso a partir do dia seguinte.
 * Assinatura cancelada = bloqueada (ainda é possível exportar os dados).
 */
final readonly class SituacaoAssinatura
{
    public const NORMAL = 'normal';
    public const SOMENTE_LEITURA = 'somente_leitura';
    public const SUSPENSA = 'suspensa';
    public const BLOQUEADA = 'bloqueada';

    public const DIAS_SOMENTE_LEITURA = 7;
    public const DIAS_SUSPENSAO = 15;
    public const DIAS_BLOQUEIO_CATRACAS = 30;
    private const DIAS_AVISO_FIM_TESTE = 3;

    private function __construct(
        public string $nivel,
        public ?string $aviso,
        public ?int $diasAtraso = null,
    ) {
    }

    public static function calcular(
        string $status,
        ?string $testeAte,
        ?string $inadimplenteDesde,
        DateTimeImmutable $hoje,
    ): self {
        $hoje = $hoje->setTime(0, 0);

        if ($status === 'cancelada') {
            return new self(self::BLOQUEADA, 'Assinatura cancelada. Você ainda pode exportar os seus dados.');
        }

        if ($status === 'teste' && $testeAte !== null) {
            $fim = new DateTimeImmutable($testeAte);

            if ($hoje <= $fim) {
                $restam = (int) $hoje->diff($fim)->days;

                return new self(self::NORMAL, $restam <= self::DIAS_AVISO_FIM_TESTE
                    ? sprintf(
                        'Seu teste grátis termina em %d dia(s). Pague a primeira fatura para não interromper.',
                        $restam,
                    )
                    : null);
            }

            $inadimplenteDesde = $fim->modify('+1 day')->format('Y-m-d');
        } elseif ($status !== 'inadimplente' || $inadimplenteDesde === null) {
            return new self(self::NORMAL, null);
        }

        $dias = max(0, (int) (new DateTimeImmutable($inadimplenteDesde))->diff($hoje)->format('%r%a'));

        return match (true) {
            $dias >= self::DIAS_BLOQUEIO_CATRACAS => new self(self::BLOQUEADA, sprintf(
                'Pagamento em atraso há %d dias: acesso e catracas bloqueados até a regularização.',
                $dias,
            ), $dias),
            $dias >= self::DIAS_SUSPENSAO => new self(self::SUSPENSA, sprintf(
                'Pagamento em atraso há %d dias: sistema suspenso. As catracas param no dia %d de atraso.',
                $dias,
                self::DIAS_BLOQUEIO_CATRACAS,
            ), $dias),
            $dias >= self::DIAS_SOMENTE_LEITURA => new self(self::SOMENTE_LEITURA, sprintf(
                'Pagamento em atraso há %d dias: sistema em modo somente leitura. Suspensão no dia %d.',
                $dias,
                self::DIAS_SUSPENSAO,
            ), $dias),
            default => new self(self::NORMAL, sprintf(
                'Pagamento em atraso há %d dia(s). Regularize para evitar o modo somente leitura no dia %d.',
                $dias,
                self::DIAS_SOMENTE_LEITURA,
            ), $dias),
        };
    }

    /** Sem assinatura (não deveria ocorrer): falha fechada, só "Minha assinatura". */
    public static function semAssinatura(): self
    {
        return new self(self::SUSPENSA, 'Organização sem assinatura. Fale com o comercial do CadGestor.');
    }

    public function permiteAlterar(): bool
    {
        return $this->nivel === self::NORMAL;
    }

    public function permiteUsarSistema(): bool
    {
        return in_array($this->nivel, [self::NORMAL, self::SOMENTE_LEITURA], true);
    }

    public function catracasLiberadas(): bool
    {
        return $this->nivel !== self::BLOQUEADA;
    }
}
