<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\Csv;
use CadGestor\Nucleo\Paginacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\RelatorioRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\Auditoria;
use DateTimeImmutable;

/**
 * Consulta e exportação da trilha de auditoria (somente administrador).
 */
final class AuditoriaController extends Controlador
{
    public const ACOES = [
        'criar', 'alterar', 'excluir', 'anonimizar', 'login', 'logout', 'login_falhou', 'senha_alterada',
        'consentimento_aceito', 'consentimento_revogado', 'biometria_cadastrada', 'biometria_excluida',
        'exportar', 'job_manutencao', 'seed',
    ];

    public function __construct(
        Sessao $sessao,
        private readonly RelatorioRepositorio $relatorios,
        private readonly Auditoria $auditoria,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        $filtros = $this->filtros($requisicao);
        [, $total] = $this->relatorios->auditoria($filtros, 1);
        $paginacao = Paginacao::daRequisicao($requisicao, $total, 50);
        [$registros] = $this->relatorios->auditoria($filtros, $paginacao->porPagina, $paginacao->deslocamento());

        return $this->pagina('auditoria/index', [
            'titulo' => 'Trilha de auditoria',
            'filtros' => $filtros,
            'registros' => $registros,
            'paginacao' => $paginacao,
            'acoes' => self::ACOES,
            'tabelas' => $this->relatorios->tabelasAuditadas(),
            'usuarios' => $this->relatorios->opcoes('usuario'),
        ]);
    }

    /** @param array<string, string> $parametros */
    public function exportar(Requisicao $requisicao, array $parametros): Resposta
    {
        $filtros = $this->filtros($requisicao);
        [$registros] = $this->relatorios->auditoria($filtros, null);

        $this->auditoria->registrar('exportar', 'auditoria', null, null, [
            'filtros' => $filtros,
            'linhas' => count($registros),
        ]);

        $linhas = array_map(static fn (array $r): array => [
            formatar_data_hora($r['data_hora'], true),
            $r['usuario_nome'] ?? '(sistema)',
            $r['acao'],
            $r['tabela'],
            $r['registro_id'],
            $r['dados_antes'],
            $r['dados_depois'],
            $r['ip'],
        ], $registros);

        return Resposta::download(
            Csv::gerar(['Data/hora', 'Usuário', 'Ação', 'Tabela', 'Registro', 'Antes', 'Depois', 'IP'], $linhas),
            sprintf('cadgestor-auditoria-%s-a-%s.csv', $filtros['inicio'], $filtros['fim']),
            'text/csv; charset=UTF-8',
        );
    }

    /** @return array{inicio: string, fim: string, acao: string, tabela: string, usuario: ?string} */
    private function filtros(Requisicao $requisicao): array
    {
        $hoje = new DateTimeImmutable('today');
        $data = static function (string $valor, DateTimeImmutable $padrao): string {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

            return ($d !== false && $d->format('Y-m-d') === $valor ? $d : $padrao)->format('Y-m-d');
        };
        $inicio = $data($requisicao->consulta('inicio'), $hoje->modify('-6 days'));
        $fim = $data($requisicao->consulta('fim'), $hoje);
        $acao = $requisicao->consulta('acao');
        $tabela = $requisicao->consulta('tabela');
        $usuario = $requisicao->consulta('usuario');

        return [
            'inicio' => min($inicio, $fim),
            'fim' => max($inicio, $fim),
            'acao' => in_array($acao, self::ACOES, true) ? $acao : '',
            'tabela' => preg_match('/^[a-z_]{1,60}$/', $tabela) === 1 ? $tabela : '',
            'usuario' => preg_match('/^[0-9a-f-]{36}$/i', $usuario) === 1 ? $usuario : null,
        ];
    }
}
