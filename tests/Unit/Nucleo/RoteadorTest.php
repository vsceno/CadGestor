<?php

declare(strict_types=1);

namespace CadGestor\Testes\Unit\Nucleo;

use CadGestor\Http\Middleware\Middleware;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Nucleo\Roteador;
use PHPUnit\Framework\TestCase;

final class RoteadorTest extends TestCase
{
    private const UUID = '0192f5a4-7c1e-7b3a-9d2e-1a2b3c4d5e6f';

    public function testDespachaRotaEstatica(): void
    {
        $roteador = new Roteador();
        $roteador->get('/saude', static fn (): Resposta => new Resposta('ok'));

        self::assertSame('ok', $roteador->despachar(new Requisicao('GET', '/saude'))->corpo);
    }

    public function testHeadUsaRotaGet(): void
    {
        $roteador = new Roteador();
        $roteador->get('/saude', static fn (): Resposta => new Resposta('ok'));

        self::assertSame(200, $roteador->despachar(new Requisicao('HEAD', '/saude'))->status);
    }

    public function testExtraiParametroUuid(): void
    {
        $roteador = new Roteador();
        $roteador->get(
            '/empresas/{id}',
            static fn (Requisicao $r, array $p): Resposta => new Resposta($p['id']),
        );

        $resposta = $roteador->despachar(new Requisicao('GET', '/empresas/' . self::UUID));

        self::assertSame(self::UUID, $resposta->corpo);
    }

    public function testParametroQueNaoEUuidNaoCasa(): void
    {
        // Garante que dados pessoais (ex.: CPF) não sirvam de identificador na URL.
        $roteador = new Roteador();
        $roteador->get('/pessoas/{id}', static fn (): Resposta => new Resposta('nao deveria'));

        self::assertSame(404, $roteador->despachar(new Requisicao('GET', '/pessoas/12345678909'))->status);
    }

    public function testRotaInexistenteRetorna404(): void
    {
        self::assertSame(404, (new Roteador())->despachar(new Requisicao('GET', '/nada'))->status);
    }

    public function testMetodoNaoPermitidoRetorna405ComAllow(): void
    {
        $roteador = new Roteador();
        $roteador->get('/saude', static fn (): Resposta => new Resposta('ok'));

        $resposta = $roteador->despachar(new Requisicao('POST', '/saude'));

        self::assertSame(405, $resposta->status);
        self::assertSame('GET', $resposta->cabecalhos()['Allow']);
    }

    public function testMiddlewaresDeGrupoERotaExecutamEmOrdem(): void
    {
        $ordem = [];
        $registrar = static function (string $nome) use (&$ordem): Middleware {
            return new class ($nome, $ordem) implements Middleware {
            /** @param list<string> $ordem */
                public function __construct(private string $nome, private array &$ordem)
                {
                }

                public function processar(Requisicao $requisicao, callable $proximo): Resposta
                {
                    $this->ordem[] = $this->nome;

                    return $proximo($requisicao);
                }
            };
        };

        $roteador = new Roteador();
        $roteador->grupo([$registrar('grupo')], static function (Roteador $r) use ($registrar, &$ordem): void {
            $r->get('/x', static function () use (&$ordem): Resposta {
                $ordem[] = 'controller';

                return new Resposta('ok');
            }, [$registrar('rota')]);
        });

        $roteador->despachar(new Requisicao('GET', '/x'));

        self::assertSame(['grupo', 'rota', 'controller'], $ordem);
    }

    public function testMiddlewarePodeBarrarAntesDoController(): void
    {
        $bloqueio = new class implements Middleware {
            public function processar(Requisicao $requisicao, callable $proximo): Resposta
            {
                return new Resposta('barrado', 403);
            }
        };

        $roteador = new Roteador();
        $roteador->get('/admin', static fn (): Resposta => new Resposta('segredo'), [$bloqueio]);

        $resposta = $roteador->despachar(new Requisicao('GET', '/admin'));

        self::assertSame(403, $resposta->status);
        self::assertSame('barrado', $resposta->corpo);
    }

    public function testMiddlewaresDeGrupoNaoVazamParaRotasForaDoGrupo(): void
    {
        $bloqueio = new class implements Middleware {
            public function processar(Requisicao $requisicao, callable $proximo): Resposta
            {
                return new Resposta('barrado', 403);
            }
        };

        $roteador = new Roteador();
        $roteador->grupo([$bloqueio], static fn (Roteador $r) => $r->get('/dentro', static fn () => new Resposta('x')));
        $roteador->get('/fora', static fn (): Resposta => new Resposta('livre'));

        self::assertSame('livre', $roteador->despachar(new Requisicao('GET', '/fora'))->corpo);
    }

    public function testNormalizaBarraFinal(): void
    {
        self::assertSame('/empresas', Requisicao::normalizarCaminho('/empresas/'));
        self::assertSame('/', Requisicao::normalizarCaminho(''));
    }
}
