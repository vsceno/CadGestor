<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Acesso\ResultadoDecisao;
use CadGestor\Nucleo\Csv;
use CadGestor\Nucleo\Paginacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\RelatorioRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\Auditoria;
use DateTimeImmutable;

/**
 * Relatórios de acesso por perfil, empresa e período, com exportação CSV.
 *
 * Os filtros vão na URL (GET) porque são só datas e UUIDs: nenhum dado pessoal.
 * Nos relatórios o CPF aparece sempre mascarado (minimização).
 */
final class RelatorioController extends Controlador
{
    private const PERIODO_MAXIMO_DIAS = 366;

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
        [$filtros, $aviso] = $this->filtros($requisicao);

        [, $total] = $this->relatorios->eventos($filtros, 1);
        $paginacao = Paginacao::daRequisicao($requisicao, $total, 50);
        [$eventos] = $this->relatorios->eventos($filtros, $paginacao->porPagina, $paginacao->deslocamento());

        return $this->pagina('relatorios/index', [
            'titulo' => 'Relatórios de acesso',
            'filtros' => $filtros,
            'aviso' => $aviso,
            'porPerfil' => $this->relatorios->resumoPorPerfil($filtros),
            'porEmpresa' => $this->relatorios->resumoPorEmpresa($filtros),
            'porMotivo' => $this->relatorios->resumoPorMotivo($filtros),
            'porDia' => $this->relatorios->resumoPorDia($filtros),
            'eventos' => $eventos,
            'paginacao' => $paginacao,
            'empresas' => $this->relatorios->opcoes('empresa'),
            'perfis' => $this->relatorios->opcoes('perfil'),
            'catracas' => $this->relatorios->opcoes('catraca'),
        ]);
    }

    /** @param array<string, string> $parametros */
    public function exportar(Requisicao $requisicao, array $parametros): Resposta
    {
        [$filtros] = $this->filtros($requisicao);
        $tipo = $requisicao->consulta('tipo', 'eventos');

        [$cabecalhos, $linhas] = match ($tipo) {
            'perfil' => $this->linhasResumo($this->relatorios->resumoPorPerfil($filtros), 'Perfil'),
            'empresa' => $this->linhasResumo($this->relatorios->resumoPorEmpresa($filtros), 'Empresa'),
            default => $this->linhasEventos($filtros),
        };
        $tipo = in_array($tipo, ['perfil', 'empresa'], true) ? $tipo : 'eventos';

        // A trilha registra QUE houve exportação e com quais filtros, não o conteúdo.
        $this->auditoria->registrar('exportar', 'evento_acesso', null, null, [
            'relatorio' => $tipo,
            'filtros' => $filtros,
            'linhas' => count($linhas),
        ]);

        $nome = sprintf('cadgestor-%s-%s-a-%s.csv', $tipo, $filtros['inicio'], $filtros['fim']);

        return Resposta::download(Csv::gerar($cabecalhos, $linhas), $nome, 'text/csv; charset=UTF-8');
    }

    /**
     * @return array{0: array{inicio: string, fim: string, empresa: ?string, perfil: ?string,
     *     catraca: ?string, resultado: ?string}, 1: ?string}
     */
    private function filtros(Requisicao $requisicao): array
    {
        $hoje = new DateTimeImmutable('today');
        $data = static function (string $valor, DateTimeImmutable $padrao): DateTimeImmutable {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

            return $d !== false && $d->format('Y-m-d') === $valor ? $d : $padrao;
        };
        $uuid = static fn (string $v): ?string => preg_match('/^[0-9a-f-]{36}$/i', $v) === 1 ? $v : null;

        $inicio = $data($requisicao->consulta('inicio'), $hoje->modify('-6 days'));
        $fim = $data($requisicao->consulta('fim'), $hoje);
        $aviso = null;

        if ($fim < $inicio) {
            [$inicio, $fim] = [$fim, $inicio];
        }

        if ($inicio->diff($fim)->days > self::PERIODO_MAXIMO_DIAS) {
            $inicio = $fim->modify('-' . self::PERIODO_MAXIMO_DIAS . ' days');
            $aviso = 'Período limitado a ' . self::PERIODO_MAXIMO_DIAS . ' dias.';
        }

        $resultado = $requisicao->consulta('resultado');

        return [[
            'inicio' => $inicio->format('Y-m-d'),
            'fim' => $fim->format('Y-m-d'),
            'empresa' => $uuid($requisicao->consulta('empresa')),
            'perfil' => $uuid($requisicao->consulta('perfil')),
            'catraca' => $uuid($requisicao->consulta('catraca')),
            'resultado' => in_array($resultado, ['liberado', 'negado'], true) ? $resultado : null,
        ], $aviso];
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function linhasEventos(array $filtros): array
    {
        [$eventos] = $this->relatorios->eventos($filtros, null);
        $linhas = array_map(static fn (array $ev): array => [
            formatar_data_hora($ev['data_hora'], true),
            $ev['empresa_nome'],
            $ev['catraca_nome'],
            $ev['pessoa_nome'],
            mascarar_cpf($ev['pessoa_cpf']),
            $ev['perfil_nome'],
            $ev['tipo_identificacao'],
            $ev['resultado'],
            ResultadoDecisao::rotuloMotivo($ev['motivo_negacao']),
            $ev['score'] === null ? '' : str_replace('.', ',', (string) $ev['score']),
            $ev['offline'] ? 'sim' : 'não',
        ], $eventos);

        return [[
            'Data/hora', 'Empresa', 'Catraca', 'Pessoa', 'CPF (mascarado)', 'Perfil', 'Identificação', 'Resultado',
            'Motivo da negação', 'Confiança', 'Offline',
        ], $linhas];
    }

    /**
     * @param list<array<string, mixed>> $resumo
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function linhasResumo(array $resumo, string $rotulo): array
    {
        return [[$rotulo, 'Total', 'Liberados', 'Negados', 'Pessoas distintas'], array_map(
            static fn (array $r): array => [$r['grupo'], $r['total'], $r['liberados'], $r['negados'], $r['pessoas']],
            $resumo,
        )];
    }
}
