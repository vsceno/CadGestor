<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Banco\Migrador;
use CadGestor\Nucleo\Ambiente;
use CadGestor\Nucleo\ConexaoBanco;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Nucleo\Visao;
use PDO;
use PDOException;
use Throwable;

/**
 * Página inicial. Nas Fases 0 e 1 mostra um diagnóstico do ambiente e do
 * banco; a partir da Fase 2 dará lugar ao login.
 */
final class InicioController
{
    /** Extensões exigidas pelo projeto (seção 2 do escopo). */
    private const EXTENSOES = ['pdo_pgsql', 'pgsql', 'mbstring', 'openssl', 'fileinfo', 'intl', 'sodium', 'json'];

    public function __construct(
        private readonly Ambiente $ambiente,
        private readonly ConexaoBanco $banco,
        private readonly string $diretorioMigracoes,
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

        [$verificacoesDados, $tabelas] = $this->verificarDados();

        return Resposta::html(Visao::renderizar('inicio/diagnostico', [
            'titulo' => 'Diagnóstico do ambiente',
            'ambiente' => $this->ambiente->texto('APP_AMBIENTE'),
            'verificacoes' => $verificacoes,
            'verificacoesDados' => $verificacoesDados,
            'tabelas' => $tabelas,
        ]));
    }

    /**
     * Situação das migrações e dos dados iniciais (Fase 1).
     *
     * @return array{
     *     list<array{item: string, ok: bool, detalhe: string}>,
     *     list<array{tabela: string, colunas: int, registros: int}>
     * }
     */
    private function verificarDados(): array
    {
        try {
            $pdo = $this->banco->pdo();
            $migrador = new Migrador($pdo, $this->diretorioMigracoes);
            $aplicadas = count($migrador->aplicadas());
            $pendentes = $migrador->pendentes();

            $perfis = $pdo->query('SELECT nome, base_legal_validada FROM perfil_vinculo WHERE ativo ORDER BY nome')
                ->fetchAll(PDO::FETCH_ASSOC);
            $semValidacao = count(array_filter($perfis, static fn (array $p): bool => !$p['base_legal_validada']));

            $administradores = (int) $pdo->query(<<<'SQL'
                SELECT count(*) FROM usuario_papel p JOIN usuario_sistema u ON u.id = p.usuario_id
                 WHERE p.papel = 'administrador' AND u.ativo
                SQL)->fetchColumn();

            $tabelas = $pdo->query(<<<'SQL'
                SELECT t.table_name AS tabela,
                       (SELECT count(*) FROM information_schema.columns c
                         WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name) AS colunas,
                       COALESCE(s.n_live_tup, 0) AS registros
                  FROM information_schema.tables t
                  LEFT JOIN pg_stat_user_tables s ON s.schemaname = t.table_schema AND s.relname = t.table_name
                 WHERE t.table_schema = current_schema() AND t.table_type = 'BASE TABLE'
                 ORDER BY t.table_name
                SQL)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $erro) {
            return [[[
                'item' => 'Migrações',
                'ok' => false,
                'detalhe' => 'não foi possível verificar (' . $erro::class . ') - rode php bin/migrar.php',
            ]], []];
        }

        $verificacoes = [
            [
                'item' => 'Migrações',
                'ok' => $pendentes === [] && $aplicadas > 0,
                'detalhe' => "{$aplicadas} aplicada(s), " . count($pendentes) . ' pendente(s)'
                    . ($pendentes === [] ? '' : ' - rode php bin/migrar.php'),
            ],
            [
                'item' => 'Perfis de vínculo',
                'ok' => $perfis !== [],
                'detalhe' => $perfis === []
                    ? 'nenhum perfil - rode php bin/seed.php'
                    : implode(', ', array_column($perfis, 'nome'))
                        . ($semValidacao > 0 ? " (base legal de {$semValidacao} aguardando validação jurídica)" : ''),
            ],
            [
                'item' => 'Administrador inicial',
                'ok' => $administradores > 0,
                'detalhe' => $administradores > 0
                    ? "{$administradores} administrador(es) ativo(s)"
                    : 'nenhum - rode php bin/criar-admin.php',
            ],
        ];

        return [$verificacoes, array_map(static fn (array $t): array => [
            'tabela' => (string) $t['tabela'],
            'colunas' => (int) $t['colunas'],
            'registros' => (int) $t['registros'],
        ], $tabelas)];
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
