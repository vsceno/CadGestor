<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Sessao;
use PDO;

/**
 * Página inicial após o login, com atalhos conforme os papéis do usuário.
 */
final class PainelController extends Controlador
{
    public function __construct(
        Sessao $sessao,
        private readonly Autenticacao $autenticacao,
        private readonly PDO $pdo,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        $indicadores = $this->pdo->query(<<<'SQL'
            SELECT
                (SELECT count(*) FROM empresa WHERE ativo) AS empresas,
                (SELECT count(*) FROM pessoa WHERE ativo AND anonimizada_em IS NULL) AS pessoas,
                (SELECT count(*) FROM vinculo WHERE status = 'ativo') AS vinculos,
                (SELECT count(*) FROM catraca WHERE ativo) AS catracas,
                (SELECT count(*) FROM evento_acesso WHERE data_hora >= date_trunc('day', now())) AS eventos_hoje,
                (SELECT count(*) FROM alerta WHERE tratado_em IS NULL) AS alertas_pendentes
            SQL)->fetch(PDO::FETCH_ASSOC) ?: [];

        return $this->pagina('painel/index', [
            'titulo' => 'Painel',
            'usuarioPainel' => $this->autenticacao->usuario(),
            'indicadores' => array_map('intval', $indicadores),
        ]);
    }
}
