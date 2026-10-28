<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\AssinaturaRepositorio;

/**
 * Confere os limites do plano antes de ativar mais uma pessoa ou catraca.
 */
final class LimitesPlano
{
    private const RECURSOS = [
        'pessoas' => ['limite_pessoas', 'pessoas ativas'],
        'catracas' => ['limite_catracas', 'catracas ativas'],
    ];

    public function __construct(private readonly AssinaturaRepositorio $assinaturas)
    {
    }

    /**
     * @param 'pessoas'|'catracas' $recurso
     * @throws ErroValidacao campo "plano", com a mensagem para o usuário
     */
    public function garantirCapacidade(string $recurso): void
    {
        [$coluna, $rotulo] = self::RECURSOS[$recurso];
        $assinatura = $this->assinaturas->daOrganizacao();

        if ($assinatura === null) {
            throw ErroValidacao::campo('plano', 'Organização sem assinatura ativa.');
        }

        $limite = (int) $assinatura[$coluna];

        if ($this->assinaturas->uso()[$recurso] >= $limite) {
            throw ErroValidacao::campo('plano', sprintf(
                'O plano %s permite até %d %s. Para cadastrar mais, troque de plano em "Minha assinatura".',
                $assinatura['plano_nome'],
                $limite,
                $rotulo,
            ));
        }
    }
}
