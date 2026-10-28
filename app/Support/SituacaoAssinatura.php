<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Régua de atraso de pagamento, calculada na hora a partir do status e das
 * datas da assinatura (não depende de um job rodar):
 *
 *   0 a 6 dias   normal, com aviso
 *   7 a 14 dias  somente leitura (o servidor recusa alterações)
 *   15 a 29 dias suspenso (só "Minha assinatura"); as catracas seguem funcionando
 *   30 dias ou mais, ou cancelada: bloqueado (as catracas param)
 *
 * O teste grátis vencido sem pagamento conta como atraso a partir do dia seguinte.
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

    /** Rótulo curto e tipo do <x-status> de cada nível. */
    public const ROTULOS = [
        self::NORMAL => ['Normal', 'ativo'],
        self::SOMENTE_LEITURA => ['Somente leitura', 'atencao'],
        self::SUSPENSA => ['Suspenso', 'atencao'],
        self::BLOQUEADA => ['Bloqueado', 'negado'],
    ];

    private function __construct(
        public string $nivel,
        public ?string $aviso,
        public ?int $diasAtraso = null,
    ) {}

    public static function calcular(string $status, ?string $testeAte, ?string $inadimplenteDesde, DateTimeInterface $hoje): self
    {
        $hoje = DateTimeImmutable::createFromInterface($hoje)->setTime(0, 0);

        if ($status === 'cancelada') {
            return new self(self::BLOQUEADA, 'Assinatura cancelada. Você ainda pode exportar os seus dados.');
        }

        if ($status === 'teste' && $testeAte !== null) {
            $fim = new DateTimeImmutable($testeAte);

            if ($hoje <= $fim) {
                $restam = (int) $hoje->diff($fim)->days;

                return new self(self::NORMAL, $restam <= self::DIAS_AVISO_FIM_TESTE
                    ? sprintf('Seu teste grátis termina em %d dia(s). Pague a primeira fatura para não interromper.', $restam)
                    : null);
            }

            $inadimplenteDesde = $fim->modify('+1 day')->format('Y-m-d');
        } elseif ($status !== 'inadimplente' || $inadimplenteDesde === null) {
            return new self(self::NORMAL, null);
        }

        $dias = max(0, (int) (new DateTimeImmutable($inadimplenteDesde))->diff($hoje)->format('%r%a'));

        return match (true) {
            $dias >= self::DIAS_BLOQUEIO_CATRACAS => new self(self::BLOQUEADA, sprintf(
                'Pagamento em atraso há %d dias: acesso e catracas bloqueados até a regularização.', $dias,
            ), $dias),
            $dias >= self::DIAS_SUSPENSAO => new self(self::SUSPENSA, sprintf(
                'Pagamento em atraso há %d dias: sistema suspenso. As catracas param no dia %d de atraso.', $dias, self::DIAS_BLOQUEIO_CATRACAS,
            ), $dias),
            $dias >= self::DIAS_SOMENTE_LEITURA => new self(self::SOMENTE_LEITURA, sprintf(
                'Pagamento em atraso há %d dias: sistema em modo somente leitura. Suspensão no dia %d.', $dias, self::DIAS_SUSPENSAO,
            ), $dias),
            default => new self(self::NORMAL, sprintf(
                'Pagamento em atraso há %d dia(s). Regularize para evitar o modo somente leitura no dia %d.', $dias, self::DIAS_SOMENTE_LEITURA,
            ), $dias),
        };
    }

    public static function semAssinatura(): self
    {
        return new self(self::SUSPENSA, 'Cliente sem assinatura. Fale com o comercial do CadGestor.');
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

    public function rotulo(): string
    {
        return self::ROTULOS[$this->nivel][0];
    }

    public function tipoStatus(): string
    {
        return self::ROTULOS[$this->nivel][1];
    }
}
