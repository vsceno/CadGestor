<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

use ErrorException;
use Throwable;

/**
 * Captura avisos, exceções e erros fatais.
 *
 * - No log: só a classe do erro, arquivo, linha e um código de referência.
 *   A mensagem original NÃO é gravada, porque exceções de banco costumam
 *   carregar valores (ex.: "chave duplicada (cpf)=(...)").
 * - Na tela: página genérica com o código de referência. Detalhes técnicos
 *   aparecem apenas com APP_DEBUG=true fora de produção.
 */
final class TratadorErros
{
    private const FATAIS = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    public function __construct(
        private readonly Log $log,
        private readonly bool $exibirDetalhes,
        private readonly bool $respostaJson = false,
    ) {
    }

    public function registrar(): void
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        set_error_handler(function (int $nivel, string $mensagem, string $arquivo, int $linha): bool {
            if ((error_reporting() & $nivel) === 0) {
                return false;
            }

            throw new ErrorException($mensagem, 0, $nivel, $arquivo, $linha);
        });

        set_exception_handler(function (Throwable $erro): void {
            $this->tratar($erro)->enviar();
        });

        register_shutdown_function(function (): void {
            $ultimo = error_get_last();

            if ($ultimo !== null && ($ultimo['type'] & self::FATAIS) !== 0) {
                $erro = new ErrorException($ultimo['message'], 0, $ultimo['type'], $ultimo['file'], $ultimo['line']);
                $this->tratar($erro)->enviar();
            }
        });
    }

    public function tratar(Throwable $erro, bool $json = false): Resposta
    {
        $referencia = bin2hex(random_bytes(6));

        $this->log->erro($erro::class, [
            'ref' => $referencia,
            'arquivo' => $this->caminhoRelativo($erro->getFile()),
            'linha' => $erro->getLine(),
            'codigo' => is_scalar($erro->getCode()) ? (string) $erro->getCode() : null,
        ]);

        if ($json || $this->respostaJson) {
            $dados = ['status' => 'erro', 'referencia' => $referencia];

            if ($this->exibirDetalhes) {
                $dados['detalhe'] = $erro::class . ': ' . $erro->getMessage();
            }

            return Resposta::json($dados, 500);
        }

        try {
            $corpo = Visao::renderizar('erros/500', [
                'titulo' => 'Erro interno',
                'referencia' => $referencia,
                'detalhe' => $this->exibirDetalhes ? (string) $erro : null,
            ]);
        } catch (Throwable) {
            $corpo = '<!doctype html><meta charset="utf-8"><title>Erro</title>'
                . '<p>Ocorreu um erro interno. Referência: ' . $referencia . '</p>';
        }

        return Resposta::html($corpo, 500);
    }

    private function caminhoRelativo(string $arquivo): string
    {
        $raiz = dirname(__DIR__, 2);

        return str_starts_with($arquivo, $raiz) ? ltrim(substr($arquivo, strlen($raiz)), '\\/') : basename($arquivo);
    }
}
