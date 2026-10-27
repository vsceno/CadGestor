<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\Paginacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\EventoRepositorio;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\Papel;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\Auditoria;

/**
 * Alertas de segurança (acessos negados, dispositivos desconhecidos...).
 * Consulta vê; só o administrador marca como tratado.
 */
final class AlertaController extends Controlador
{
    public const TIPOS = [
        'acesso_negado' => 'Acesso negado',
        'tentativas_repetidas' => 'Tentativas repetidas',
        'dispositivo_desconhecido' => 'Dispositivo desconhecido',
        'dispositivo_offline' => 'Dispositivo offline',
        'falha_sincronizacao' => 'Falha de sincronização',
        'emergencia' => 'Emergência',
    ];

    public function __construct(
        Sessao $sessao,
        private readonly EventoRepositorio $eventos,
        private readonly Autenticacao $autenticacao,
        private readonly Auditoria $auditoria,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        $pendentes = $requisicao->consulta('situacao', 'pendentes') !== 'todos';
        $tipo = array_key_exists($requisicao->consulta('tipo'), self::TIPOS) ? $requisicao->consulta('tipo') : '';

        [, $total] = $this->eventos->alertas($pendentes, $tipo, 1, 0);
        $paginacao = Paginacao::daRequisicao($requisicao, $total, 30);
        [$alertas] = $this->eventos->alertas($pendentes, $tipo, $paginacao->porPagina, $paginacao->deslocamento());

        return $this->pagina('alertas/index', [
            'titulo' => 'Alertas',
            'alertas' => $alertas,
            'tipos' => self::TIPOS,
            'filtroTipo' => $tipo,
            'somentePendentes' => $pendentes,
            'paginacao' => $paginacao,
            'podeTratar' => $this->autenticacao->usuario()?->temPapel(Papel::Administrador) ?? false,
        ]);
    }

    /** @param array<string, string> $parametros */
    public function tratar(Requisicao $requisicao, array $parametros): Resposta
    {
        $observacao = mb_substr($requisicao->entrada('observacao'), 0, 500);
        $usuario = (string) $this->autenticacao->usuario()?->id;

        if (!$this->eventos->tratarAlerta($parametros['id'], $usuario, $observacao)) {
            return $this->redirecionar('/alertas', 'Alerta não encontrado ou já tratado.', 'erro');
        }

        $this->auditoria->registrar('alterar', 'alerta', $parametros['id'], null, ['tratado' => true]);

        return $this->redirecionar('/alertas', 'Alerta marcado como tratado.');
    }
}
