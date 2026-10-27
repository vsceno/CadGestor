<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

use Throwable;

/**
 * Ponto de montagem da aplicação: carrega o .env, configura fuso e erros,
 * cria a conexão com o banco e registra as rotas.
 *
 * Usado tanto pelo public/index.php quanto pelos scripts de bin/.
 */
final class Aplicacao
{
    public readonly Roteador $roteador;
    public readonly ConexaoBanco $banco;
    public readonly Log $log;
    private readonly TratadorErros $tratadorErros;

    private function __construct(
        public readonly string $raiz,
        public readonly Ambiente $ambiente,
    ) {
        date_default_timezone_set($ambiente->texto('APP_FUSO'));

        $this->log = new Log($raiz . '/storage/logs');
        $this->tratadorErros = new TratadorErros(
            $this->log,
            !$ambiente->emProducao() && $ambiente->booleano('APP_DEBUG'),
            PHP_SAPI === 'cli',
        );
        $this->tratadorErros->registrar();

        $this->banco = ConexaoBanco::doAmbiente($ambiente);
        $this->roteador = new Roteador();
    }

    public static function iniciar(string $raiz): self
    {
        return new self($raiz, Ambiente::carregar($raiz));
    }

    public function registrarRotas(string $arquivo): self
    {
        $registrar = require $arquivo;
        $registrar($this->roteador, $this);

        return $this;
    }

    public function atender(Requisicao $requisicao): Resposta
    {
        try {
            return $this->roteador->despachar($requisicao);
        } catch (Throwable $erro) {
            return $this->tratadorErros->tratar($erro, str_starts_with($requisicao->caminho, '/api/')
                || $requisicao->caminho === '/saude');
        }
    }
}
