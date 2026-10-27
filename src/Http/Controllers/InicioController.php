<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\Ambiente;
use CadGestor\Nucleo\ConexaoBanco;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Nucleo\Visao;
use PDOException;

/**
 * Página inicial. Na Fase 0 mostra um diagnóstico do ambiente; a partir da
 * Fase 2 dará lugar ao login.
 */
final class InicioController
{
    /** Extensões exigidas pelo projeto (seção 2 do escopo). */
    private const EXTENSOES = ['pdo_pgsql', 'pgsql', 'mbstring', 'openssl', 'fileinfo', 'intl', 'sodium', 'json'];

    public function __construct(
        private readonly Ambiente $ambiente,
        private readonly ConexaoBanco $banco,
    ) {
    }

    /** @param array<string, string> $parametros */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        // Diagnóstico revela detalhes do servidor: só fora de produção.
        if ($this->ambiente->emProducao()) {
            return Resposta::html(Visao::renderizar('inicio/producao', ['titulo' => 'CadGestor']));
        }

        $verificacoes = [];

        $verificacoes[] = [
            'item' => 'PHP',
            'ok' => version_compare(PHP_VERSION, '8.5.0', '>='),
            'detalhe' => PHP_VERSION,
        ];

        foreach (self::EXTENSOES as $extensao) {
            $carregada = extension_loaded($extensao);
            $verificacoes[] = [
                'item' => "Extensão {$extensao}",
                'ok' => $carregada,
                'detalhe' => $carregada ? 'carregada' : 'não carregada (habilite no php.ini)',
            ];
        }

        $verificacoes[] = [
            'item' => 'Argon2id (password_hash)',
            'ok' => defined('PASSWORD_ARGON2ID'),
            'detalhe' => defined('PASSWORD_ARGON2ID') ? 'disponível' : 'indisponível',
        ];

        $verificacoes = [...$verificacoes, ...$this->verificarBanco()];

        $verificacoes[] = [
            'item' => 'Fuso horário',
            'ok' => true,
            'detalhe' => date_default_timezone_get() . ' (agora: ' . date('d/m/Y H:i') . ')',
        ];

        return Resposta::html(Visao::renderizar('inicio/diagnostico', [
            'titulo' => 'Diagnóstico do ambiente',
            'ambiente' => $this->ambiente->texto('APP_AMBIENTE'),
            'verificacoes' => $verificacoes,
        ]));
    }

    /** @return list<array{item: string, ok: bool, detalhe: string}> */
    private function verificarBanco(): array
    {
        try {
            $linha = $this->banco->pdo()
                ->query("SELECT current_setting('server_version') AS versao, current_schema() AS schema,"
                    . ' current_user AS usuario')
                ->fetch();
        } catch (PDOException $erro) {
            return [[
                'item' => 'Conexão PostgreSQL',
                'ok' => false,
                'detalhe' => 'falhou (SQLSTATE ' . $erro->getCode() . ') - confira o .env',
            ]];
        }

        return [
            [
                'item' => 'Conexão PostgreSQL',
                'ok' => str_starts_with((string) $linha['versao'], '18.'),
                'detalhe' => "versão {$linha['versao']}, usuário {$linha['usuario']}",
            ],
            [
                'item' => 'Schema da aplicação',
                'ok' => $linha['schema'] !== null,
                'detalhe' => $linha['schema'] ?? 'schema não encontrado (rode database/setup)',
            ],
        ];
    }
}
