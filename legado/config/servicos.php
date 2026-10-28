<?php

/**
 * Registro dos serviços no container (quem depende de quem).
 */

declare(strict_types=1);

use CadGestor\Cobranca\GatewayPagamento;
use CadGestor\Cobranca\GatewaySimulado;
use CadGestor\Http\Controllers\AlertaController;
use CadGestor\Http\Controllers\ApiPagamentoController;
use CadGestor\Http\Controllers\AssinaturaController;
use CadGestor\Http\Controllers\CadastroController;
use CadGestor\Http\Controllers\DadosEmpresaController;
use CadGestor\Http\Controllers\PagamentoSimuladoController;
use CadGestor\Http\Controllers\PlanoController;
use CadGestor\Mensagens\Mensageiro;
use CadGestor\Mensagens\MensageiroArquivo;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Servicos\AssinaturaServico;
use CadGestor\Servicos\AtendimentoClienteServico;
use CadGestor\Servicos\DadosEmpresaServico;
use CadGestor\Servicos\RecuperacaoContaServico;
use CadGestor\Servicos\CadastroClienteServico;
use CadGestor\Servicos\LimitesPlano;
use CadGestor\Servicos\PagamentoServico;
use CadGestor\Servicos\PlanoServico;
use CadGestor\Http\Controllers\ApiCatracaController;
use CadGestor\Http\Controllers\AuditoriaController;
use CadGestor\Http\Controllers\AutenticacaoController;
use CadGestor\Http\Controllers\CatracaController;
use CadGestor\Http\Controllers\EmpresaController;
use CadGestor\Http\Controllers\InicioController;
use CadGestor\Http\Controllers\LgpdController;
use CadGestor\Http\Controllers\OrganizacaoController;
use CadGestor\Http\Controllers\PainelController;
use CadGestor\Http\Controllers\PerfilController;
use CadGestor\Http\Controllers\PessoaController;
use CadGestor\Http\Controllers\RegraAcessoController;
use CadGestor\Http\Controllers\RelatorioController;
use CadGestor\Http\Controllers\SaudeController;
use CadGestor\Http\Controllers\SimuladorController;
use CadGestor\Http\Controllers\UsuarioController;
use CadGestor\Http\Controllers\VinculoController;
use CadGestor\Banco\ContextoBanco;
use CadGestor\Nucleo\Aplicacao;
use CadGestor\Nucleo\Container;
use CadGestor\Repositorios\CatracaRepositorio;
use CadGestor\Repositorios\EmpresaRepositorio;
use CadGestor\Repositorios\EventoRepositorio;
use CadGestor\Repositorios\LgpdRepositorio;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\PerfilRepositorio;
use CadGestor\Repositorios\PessoaRepositorio;
use CadGestor\Repositorios\RegraAcessoRepositorio;
use CadGestor\Repositorios\RelatorioRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Repositorios\VinculoRepositorio;
use CadGestor\Acesso\AcessoServico;
use CadGestor\Acesso\DecisaoAcesso;
use CadGestor\Acesso\FonteDadosAcessoPdo;
use CadGestor\Acesso\SincronizacaoDispositivo;
use CadGestor\Biometria\IdentificadorBiometrico;
use CadGestor\Biometria\IdentificadorSimulado;
use CadGestor\Seguranca\Autenticacao;
use CadGestor\Seguranca\CifradorBiometria;
use CadGestor\Seguranca\Csrf;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Seguranca\LimitadorTentativas;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\Auditoria;
use CadGestor\Servicos\BiometriaServico;
use CadGestor\Servicos\CatracaServico;
use CadGestor\Servicos\ConsentimentoServico;
use CadGestor\Servicos\EmpresaServico;
use CadGestor\Servicos\OrganizacaoServico;
use CadGestor\Servicos\PerfilServico;
use CadGestor\Servicos\PessoaServico;
use CadGestor\Servicos\RegraAcessoServico;
use CadGestor\Servicos\SincronizacaoServico;
use CadGestor\Servicos\UsuarioServico;
use CadGestor\Servicos\VinculoServico;

return static function (Container $c, Aplicacao $app): void {
    $pdo = static fn (): PDO => $app->banco->pdo();
    $ambiente = $app->ambiente;

    // --- Contexto da organização no banco (RLS) ------------------------------
    $c->registrar(ContextoBanco::class, static fn (): ContextoBanco => new ContextoBanco($pdo()));

    // --- Segurança ---------------------------------------------------------
    $c->registrar(Sessao::class, static fn (): Sessao => new Sessao(
        $ambiente->booleano('SESSAO_SEGURA'),
        $ambiente->inteiro('SESSAO_INATIVIDADE_MINUTOS', 30),
    ));
    $c->registrar(Csrf::class, static fn (Container $c): Csrf => new Csrf($c->obter(Sessao::class)));
    $c->registrar(HashSenha::class, static fn (): HashSenha => new HashSenha());
    $c->registrar(LimitadorTentativas::class, static fn (): LimitadorTentativas => new LimitadorTentativas(
        $pdo(),
        $ambiente->texto('APP_SEGREDO'),
    ));
    $c->registrar(Auditoria::class, static fn (): Auditoria => new Auditoria($pdo(), $app->contexto));
    $c->registrar(Autenticacao::class, static fn (Container $c): Autenticacao => new Autenticacao(
        $c->obter(UsuarioRepositorio::class),
        $c->obter(OrganizacaoRepositorio::class),
        $c->obter(ContextoBanco::class),
        $c->obter(Sessao::class),
        $c->obter(Csrf::class),
        $c->obter(HashSenha::class),
        $c->obter(LimitadorTentativas::class),
        $c->obter(Auditoria::class),
        $app->contexto,
    ));

    // --- Repositórios (todos recebem apenas o PDO) -------------------------
    foreach (
        [
            UsuarioRepositorio::class, EmpresaRepositorio::class, PerfilRepositorio::class,
            CatracaRepositorio::class, RegraAcessoRepositorio::class, PessoaRepositorio::class,
            VinculoRepositorio::class, LgpdRepositorio::class, EventoRepositorio::class, OrganizacaoRepositorio::class,
            AssinaturaRepositorio::class,
        ] as $repositorio
    ) {
        $c->registrar($repositorio, static fn (): object => new $repositorio($pdo()));
    }

    // --- Serviços -----------------------------------------------------------
    $c->registrar(EmpresaServico::class, static fn (Container $c): EmpresaServico => new EmpresaServico(
        $pdo(),
        $c->obter(EmpresaRepositorio::class),
        $c->obter(PerfilRepositorio::class),
        $c->obter(Auditoria::class),
    ));
    $c->registrar(PerfilServico::class, static fn (Container $c): PerfilServico => new PerfilServico(
        $pdo(),
        $c->obter(PerfilRepositorio::class),
        $c->obter(Auditoria::class),
    ));
    $c->registrar(CatracaServico::class, static fn (Container $c): CatracaServico => new CatracaServico(
        $pdo(),
        $c->obter(CatracaRepositorio::class),
        $c->obter(EmpresaRepositorio::class),
        $c->obter(Auditoria::class),
        $c->obter(LimitesPlano::class),
    ));
    $c->registrar(RegraAcessoServico::class, static fn (Container $c): RegraAcessoServico => new RegraAcessoServico(
        $pdo(),
        $c->obter(RegraAcessoRepositorio::class),
        $c->obter(EmpresaRepositorio::class),
        $c->obter(CatracaRepositorio::class),
        $c->obter(Auditoria::class),
    ));
    $c->registrar(UsuarioServico::class, static fn (Container $c): UsuarioServico => new UsuarioServico(
        $pdo(),
        $c->obter(UsuarioRepositorio::class),
        $c->obter(HashSenha::class),
        $c->obter(Auditoria::class),
    ));

    $c->registrar(OrganizacaoServico::class, static fn (Container $c): OrganizacaoServico => new OrganizacaoServico(
        $pdo(),
        $c->obter(OrganizacaoRepositorio::class),
        $c->obter(AssinaturaRepositorio::class),
        $c->obter(UsuarioServico::class),
        $c->obter(UsuarioRepositorio::class),
        $c->obter(RecuperacaoContaServico::class),
        $c->obter(ContextoBanco::class),
        $c->obter(Auditoria::class),
    ));
    $c->registrar(RecuperacaoContaServico::class, static fn (Container $c): RecuperacaoContaServico =>
        new RecuperacaoContaServico(
            $pdo(),
            $c->obter(UsuarioRepositorio::class),
            $c->obter(OrganizacaoRepositorio::class),
            $c->obter(ContextoBanco::class),
            $c->obter(HashSenha::class),
            $c->obter(LimitadorTentativas::class),
            $c->obter(Mensageiro::class),
            $c->obter(Auditoria::class),
            $ambiente->texto('APP_URL'),
        ));
    $c->registrar(AtendimentoClienteServico::class, static fn (Container $c): AtendimentoClienteServico =>
        new AtendimentoClienteServico(
            $pdo(),
            $c->obter(OrganizacaoRepositorio::class),
            $c->obter(AssinaturaRepositorio::class),
            $c->obter(UsuarioRepositorio::class),
            $c->obter(GatewayPagamento::class),
            $c->obter(RecuperacaoContaServico::class),
            $c->obter(LimitadorTentativas::class),
            $c->obter(ContextoBanco::class),
            $c->obter(Auditoria::class),
        ));
    $c->registrar(DadosEmpresaServico::class, static fn (Container $c): DadosEmpresaServico => new DadosEmpresaServico(
        $pdo(),
        $c->obter(OrganizacaoRepositorio::class),
        $c->obter(ContextoBanco::class),
        $c->obter(Auditoria::class),
    ));
    $c->registrar(DadosEmpresaController::class, static fn (Container $c): DadosEmpresaController =>
        new DadosEmpresaController($c->obter(Sessao::class), $c->obter(DadosEmpresaServico::class)));

    $c->registrar(SincronizacaoServico::class, static fn (): SincronizacaoServico => new SincronizacaoServico($pdo()));
    $c->registrar(PessoaServico::class, static fn (Container $c): PessoaServico => new PessoaServico(
        $pdo(),
        $c->obter(PessoaRepositorio::class),
        $c->obter(Auditoria::class),
        $c->obter(LimitesPlano::class),
    ));
    $c->registrar(VinculoServico::class, static fn (Container $c): VinculoServico => new VinculoServico(
        $pdo(),
        $c->obter(VinculoRepositorio::class),
        $c->obter(PessoaRepositorio::class),
        $c->obter(Auditoria::class),
        $c->obter(SincronizacaoServico::class),
    ));

    // --- Comercial: planos, assinatura, cobrança e cadastro self-service -----
    $c->registrar(GatewayPagamento::class, static function () use ($ambiente): GatewayPagamento {
        // Ponto de extensão: o adaptador do gateway real entra aqui (src/Cobranca/Adaptadores).
        return match ($ambiente->texto('PAGAMENTO_GATEWAY', 'simulado')) {
            'simulado' => $ambiente->emProducao()
                ? throw new RuntimeException('O gateway simulado não pode ser usado em produção.')
                : new GatewaySimulado($ambiente->texto('PAGAMENTO_WEBHOOK_TOKEN', '')),
            default => throw new RuntimeException('Gateway de pagamento desconhecido.'),
        };
    });
    $c->registrar(Mensageiro::class, static function () use ($ambiente, $app): Mensageiro {
        return match ($ambiente->texto('MENSAGEIRO', 'arquivo')) {
            'arquivo' => $ambiente->emProducao()
                ? throw new RuntimeException('O mensageiro de arquivo não pode ser usado em produção.')
                : new MensageiroArquivo($app->raiz . '/storage/emails'),
            default => throw new RuntimeException('Mensageiro desconhecido.'),
        };
    });
    $c->registrar(LimitesPlano::class, static fn (Container $c): LimitesPlano =>
        new LimitesPlano($c->obter(AssinaturaRepositorio::class)));
    $c->registrar(AssinaturaServico::class, static fn (Container $c): AssinaturaServico => new AssinaturaServico(
        $pdo(),
        $c->obter(AssinaturaRepositorio::class),
        $c->obter(GatewayPagamento::class),
        $c->obter(Auditoria::class),
    ));
    $c->registrar(PagamentoServico::class, static fn (Container $c): PagamentoServico => new PagamentoServico(
        $pdo(),
        $c->obter(AssinaturaRepositorio::class),
        $c->obter(ContextoBanco::class),
        $c->obter(Auditoria::class),
    ));
    $c->registrar(PlanoServico::class, static fn (Container $c): PlanoServico => new PlanoServico(
        $pdo(),
        $c->obter(AssinaturaRepositorio::class),
        $c->obter(Auditoria::class),
    ));
    $c->registrar(CadastroClienteServico::class, static fn (Container $c): CadastroClienteServico =>
        new CadastroClienteServico(
            $pdo(),
            $c->obter(OrganizacaoRepositorio::class),
            $c->obter(UsuarioRepositorio::class),
            $c->obter(AssinaturaRepositorio::class),
            $c->obter(GatewayPagamento::class),
            $c->obter(ContextoBanco::class),
            $c->obter(HashSenha::class),
            $c->obter(Mensageiro::class),
            $c->obter(Auditoria::class),
            $ambiente->texto('APP_URL'),
        ));
    $c->registrar(PlanoController::class, static fn (Container $c): PlanoController => new PlanoController(
        $c->obter(Sessao::class),
        $c->obter(AssinaturaRepositorio::class),
        $c->obter(PlanoServico::class),
    ));
    $c->registrar(AssinaturaController::class, static fn (Container $c): AssinaturaController =>
        new AssinaturaController(
            $c->obter(Sessao::class),
            $c->obter(AssinaturaRepositorio::class),
            $c->obter(AssinaturaServico::class),
            $c->obter(DadosEmpresaServico::class),
        ));
    $c->registrar(CadastroController::class, static fn (Container $c): CadastroController => new CadastroController(
        $c->obter(Sessao::class),
        $c->obter(AssinaturaRepositorio::class),
        $c->obter(CadastroClienteServico::class),
        $c->obter(LimitadorTentativas::class),
    ));
    $c->registrar(ApiPagamentoController::class, static fn (Container $c): ApiPagamentoController =>
        new ApiPagamentoController($c->obter(GatewayPagamento::class), $c->obter(PagamentoServico::class)));
    $c->registrar(PagamentoSimuladoController::class, static fn (Container $c): PagamentoSimuladoController =>
        new PagamentoSimuladoController(
            $c->obter(Sessao::class),
            $c->obter(AssinaturaRepositorio::class),
            $c->obter(PagamentoServico::class),
        ));

    // --- Biometria e LGPD ----------------------------------------------------
    $c->registrar(IdentificadorBiometrico::class, static function () use ($ambiente): IdentificadorBiometrico {
        // Ponto de extensão: adaptadores de fabricantes entram aqui (src/Biometria/Adaptadores).
        return match ($ambiente->texto('IDENTIFICADOR_BIOMETRICO', 'simulado')) {
            'simulado' => new IdentificadorSimulado(),
            default => throw new RuntimeException('Adaptador biométrico desconhecido.'),
        };
    });
    $c->registrar(CifradorBiometria::class, static function () use ($ambiente, $app): CifradorBiometria {
        return CifradorBiometria::doArquivo(
            CifradorBiometria::resolverCaminho($ambiente->texto('CHAVE_CIFRA_ARQUIVO'), $app->raiz),
        );
    });
    $c->registrar(ConsentimentoServico::class, static fn (Container $c): ConsentimentoServico =>
        new ConsentimentoServico(
            $pdo(),
            $c->obter(LgpdRepositorio::class),
            $c->obter(PessoaRepositorio::class),
            $c->obter(Auditoria::class),
            $c->obter(SincronizacaoServico::class),
            $app->contexto,
        ));
    $c->registrar(BiometriaServico::class, static fn (Container $c): BiometriaServico => new BiometriaServico(
        $pdo(),
        $c->obter(LgpdRepositorio::class),
        $c->obter(PessoaRepositorio::class),
        $c->obter(IdentificadorBiometrico::class),
        $c->obter(CifradorBiometria::class),
        $c->obter(Auditoria::class),
        $c->obter(SincronizacaoServico::class),
    ));

    // --- Acesso (catracas) ----------------------------------------------------
    $c->registrar(DecisaoAcesso::class, static fn (): DecisaoAcesso => new DecisaoAcesso(
        new FonteDadosAcessoPdo($pdo()),
        (float) $ambiente->texto('LIMIAR_CONFIANCA', '0.80'),
    ));
    $c->registrar(AcessoServico::class, static fn (Container $c): AcessoServico => new AcessoServico(
        $pdo(),
        $c->obter(DecisaoAcesso::class),
        $c->obter(LgpdRepositorio::class),
        $c->obter(IdentificadorBiometrico::class),
        $c->obter(CifradorBiometria::class),
        new DateTimeZone($ambiente->texto('APP_FUSO')),
    ));
    $c->registrar(SincronizacaoDispositivo::class, static fn (Container $c): SincronizacaoDispositivo =>
        new SincronizacaoDispositivo($pdo(), $c->obter(CifradorBiometria::class)));

    // --- Controllers -------------------------------------------------------
    $c->registrar(SaudeController::class, static fn (): SaudeController => new SaudeController($app->banco, $app->log));
    $c->registrar(InicioController::class, static fn (): InicioController => new InicioController(
        $ambiente,
        $app->banco,
        $app->raiz . '/database/migracoes',
    ));
    $c->registrar(AutenticacaoController::class, static fn (Container $c): AutenticacaoController =>
        new AutenticacaoController(
            $c->obter(Sessao::class),
            $c->obter(Autenticacao::class),
            $c->obter(UsuarioServico::class),
            $c->obter(RecuperacaoContaServico::class),
        ));
    $c->registrar(PainelController::class, static fn (Container $c): PainelController => new PainelController(
        $c->obter(Sessao::class),
        $c->obter(Autenticacao::class),
        $pdo(),
    ));
    $c->registrar(UsuarioController::class, static fn (Container $c): UsuarioController => new UsuarioController(
        $c->obter(Sessao::class),
        $c->obter(UsuarioRepositorio::class),
        $c->obter(UsuarioServico::class),
        $c->obter(Autenticacao::class),
    ));
    $c->registrar(EmpresaController::class, static fn (Container $c): EmpresaController => new EmpresaController(
        $c->obter(Sessao::class),
        $c->obter(EmpresaRepositorio::class),
        $c->obter(PerfilRepositorio::class),
        $c->obter(CatracaRepositorio::class),
        $c->obter(RegraAcessoRepositorio::class),
        $c->obter(EmpresaServico::class),
    ));
    $c->registrar(OrganizacaoController::class, static fn (Container $c): OrganizacaoController =>
        new OrganizacaoController(
            $c->obter(Sessao::class),
            $c->obter(OrganizacaoRepositorio::class),
            $c->obter(UsuarioRepositorio::class),
            $c->obter(AssinaturaRepositorio::class),
            $c->obter(OrganizacaoServico::class),
            $c->obter(AtendimentoClienteServico::class),
        ));
    $c->registrar(PerfilController::class, static fn (Container $c): PerfilController => new PerfilController(
        $c->obter(Sessao::class),
        $c->obter(PerfilRepositorio::class),
        $c->obter(PerfilServico::class),
    ));
    $c->registrar(CatracaController::class, static fn (Container $c): CatracaController => new CatracaController(
        $c->obter(Sessao::class),
        $c->obter(CatracaRepositorio::class),
        $c->obter(EmpresaRepositorio::class),
        $c->obter(CatracaServico::class),
    ));
    $c->registrar(RegraAcessoController::class, static fn (Container $c): RegraAcessoController =>
        new RegraAcessoController(
            $c->obter(Sessao::class),
            $c->obter(EmpresaRepositorio::class),
            $c->obter(CatracaRepositorio::class),
            $c->obter(RegraAcessoRepositorio::class),
            $c->obter(RegraAcessoServico::class),
        ));
    $c->registrar(PessoaController::class, static fn (Container $c): PessoaController => new PessoaController(
        $c->obter(Sessao::class),
        $c->obter(PessoaRepositorio::class),
        $c->obter(VinculoRepositorio::class),
        $c->obter(PessoaServico::class),
        static fn (string $pessoaId): array => $c->obter(LgpdController::class)->painel($pessoaId),
    ));
    $c->registrar(VinculoController::class, static fn (Container $c): VinculoController => new VinculoController(
        $c->obter(Sessao::class),
        $c->obter(VinculoRepositorio::class),
        $c->obter(VinculoServico::class),
        $c->obter(RegraAcessoRepositorio::class),
        $c->obter(RegraAcessoServico::class),
        $c->obter(CatracaRepositorio::class),
    ));
    $c->registrar(LgpdController::class, static fn (Container $c): LgpdController => new LgpdController(
        $c->obter(Sessao::class),
        $c->obter(LgpdRepositorio::class),
        $c->obter(ConsentimentoServico::class),
        $c->obter(BiometriaServico::class),
        $c->obter(IdentificadorBiometrico::class),
    ));
    $c->registrar(ApiCatracaController::class, static fn (Container $c): ApiCatracaController =>
        new ApiCatracaController(
            $c->obter(CatracaRepositorio::class),
            $c->obter(ContextoBanco::class),
            $c->obter(AcessoServico::class),
            $c->obter(SincronizacaoDispositivo::class),
            $c->obter(AssinaturaServico::class),
            (float) $ambiente->texto('LIMIAR_CONFIANCA', '0.80'),
            $ambiente->texto('APP_FUSO'),
        ));
    $c->registrar(AlertaController::class, static fn (Container $c): AlertaController => new AlertaController(
        $c->obter(Sessao::class),
        $c->obter(EventoRepositorio::class),
        $c->obter(Autenticacao::class),
        $c->obter(Auditoria::class),
    ));
    $c->registrar(SimuladorController::class, static fn (Container $c): SimuladorController => new SimuladorController(
        $c->obter(Sessao::class),
        $pdo(),
        $c->obter(CatracaRepositorio::class),
        $c->obter(EventoRepositorio::class),
        $c->obter(AcessoServico::class),
    ));
    $c->registrar(RelatorioRepositorio::class, static fn (): RelatorioRepositorio => new RelatorioRepositorio(
        $pdo(),
        $ambiente->texto('APP_FUSO'),
    ));
    $c->registrar(RelatorioController::class, static fn (Container $c): RelatorioController => new RelatorioController(
        $c->obter(Sessao::class),
        $c->obter(RelatorioRepositorio::class),
        $c->obter(Auditoria::class),
    ));
    $c->registrar(AuditoriaController::class, static fn (Container $c): AuditoriaController => new AuditoriaController(
        $c->obter(Sessao::class),
        $c->obter(RelatorioRepositorio::class),
        $c->obter(Auditoria::class),
    ));
};
