<?php

declare(strict_types=1);

namespace CadGestor\Biometria;

/**
 * Contrato de integração com o hardware/SDK biométrico.
 *
 * Funciona como uma tomada padrão: o CadGestor só conhece este formato, e
 * cada fabricante ganha um "adaptador" (src/Biometria/Adaptadores) que
 * converte para o SDK dele. Nenhum SDK real é implementado sem a documentação
 * oficial do fabricante.
 *
 * "referência" é o identificador da pessoa NO DISPOSITIVO. Usamos o UUID da
 * pessoa: não é dado pessoal por si só e evita enviar CPF/nome ao hardware.
 */
interface IdentificadorBiometrico
{
    /** Nome do adaptador, para log e diagnóstico (ex.: "simulado"). */
    public function nome(): string;

    /**
     * Converte uma amostra capturada (imagem facial ou leitura do sensor) em
     * template. A amostra bruta deve ser descartada pelo chamador logo depois:
     * só o template (cifrado) é guardado.
     */
    public function extrairTemplate(TipoBiometria $tipo, string $amostra): string;

    /** Cadastra o template da pessoa no dispositivo. */
    public function cadastrar(string $dispositivo, string $referencia, TipoBiometria $tipo, string $template): void;

    /** Remove todos os templates da pessoa do dispositivo. */
    public function remover(string $dispositivo, string $referencia): void;

    /**
     * Identificação 1:N no servidor: compara a amostra com os candidatos.
     *
     * @param array<string, string> $candidatos referência => template
     */
    public function identificar(TipoBiometria $tipo, string $amostra, array $candidatos): ResultadoIdentificacao;
}
