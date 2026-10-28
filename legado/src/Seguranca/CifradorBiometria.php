<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

use RuntimeException;

/**
 * Cifra de templates biométricos com XChaCha20-Poly1305 (libsodium).
 *
 * - A chave mestra fica num arquivo FORA do banco e de public/ (CHAVE_CIFRA_ARQUIVO).
 *   Quem rouba só o banco leva um cofre sem a chave.
 * - O arquivo pode ter várias versões ("versao:base64"); a maior é usada para
 *   cifrar e as antigas continuam decifrando (rotação de chave).
 * - Da chave mestra derivamos (KDF) uma subchave de cifra e outra de hash, para
 *   não reutilizar a mesma chave em finalidades diferentes.
 * - Dados associados (AAD) amarram o template à pessoa/tipo/dedo: trocar o
 *   conteúdo entre linhas do banco faz a decifragem falhar.
 */
final class CifradorBiometria
{
    private const CONTEXTO_CIFRA = 'CGBIOCIF';
    private const CONTEXTO_HASH = 'CGBIOHSH';

    /** @var array<int, string> versão => chave mestra (32 bytes) */
    private array $chaves;
    private int $versaoAtual;

    /** @param array<int, string> $chaves */
    public function __construct(#[\SensitiveParameter] array $chaves)
    {
        if (!extension_loaded('sodium')) {
            throw new RuntimeException('A extensão sodium é obrigatória para cifrar biometria.');
        }

        if ($chaves === []) {
            throw new RuntimeException('Nenhuma chave de cifra configurada.');
        }

        foreach ($chaves as $versao => $chave) {
            if ($versao < 1 || strlen($chave) !== SODIUM_CRYPTO_KDF_KEYBYTES) {
                throw new RuntimeException('Chave de cifra inválida.');
            }
        }

        $this->chaves = $chaves;
        $this->versaoAtual = max(array_keys($chaves));
    }

    /** Lê o arquivo de chaves ("versao:base64" por linha; # = comentário). */
    public static function doArquivo(string $caminho): self
    {
        if (!is_file($caminho) || !is_readable($caminho)) {
            throw new RuntimeException('Arquivo de chave de cifra não encontrado. Rode: php bin/gerar-chave.php');
        }

        $chaves = [];

        foreach (file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
            $linha = trim($linha);

            if ($linha === '' || str_starts_with($linha, '#')) {
                continue;
            }

            [$versao, $base64] = array_pad(explode(':', $linha, 2), 2, '');
            $chave = sodium_base642bin($base64, SODIUM_BASE64_VARIANT_ORIGINAL);
            $chaves[(int) $versao] = $chave;
        }

        return new self($chaves);
    }

    /** Caminho absoluto do arquivo de chaves; relativo é resolvido a partir da raiz do projeto. */
    public static function resolverCaminho(string $caminho, string $raiz): string
    {
        $absoluto = str_starts_with($caminho, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $caminho) === 1;

        return $absoluto ? $caminho : rtrim($raiz, '\\/') . '/' . $caminho;
    }

    /** Linha nova para o arquivo de chaves. */
    public static function gerarLinhaChave(int $versao): string
    {
        return $versao . ':' . sodium_bin2base64(sodium_crypto_kdf_keygen(), SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function versaoAtual(): int
    {
        return $this->versaoAtual;
    }

    /** @return array{cifrado: string, nonce: string, versao: int} */
    public function cifrar(#[\SensitiveParameter] string $template, string $dadosAssociados): array
    {
        $chave = $this->subchave($this->versaoAtual, self::CONTEXTO_CIFRA);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $cifrado = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($template, $dadosAssociados, $nonce, $chave);
        sodium_memzero($chave);

        return ['cifrado' => $cifrado, 'nonce' => $nonce, 'versao' => $this->versaoAtual];
    }

    public function decifrar(string $cifrado, string $nonce, int $versao, string $dadosAssociados): string
    {
        $chave = $this->subchave($versao, self::CONTEXTO_CIFRA);
        $template = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cifrado, $dadosAssociados, $nonce, $chave);
        sodium_memzero($chave);

        if ($template === false) {
            throw new RuntimeException('Falha de integridade ao decifrar template biométrico.');
        }

        return $template;
    }

    /**
     * Hash com chave (BLAKE2b) do template, em hexadecimal (64 caracteres).
     * Permite conferir integridade/sincronização sem guardar nada reversível.
     */
    public function hashIntegridade(#[\SensitiveParameter] string $template): string
    {
        $chave = $this->subchave($this->versaoAtual, self::CONTEXTO_HASH);
        $hash = bin2hex(sodium_crypto_generichash($template, $chave, 32));
        sodium_memzero($chave);

        return $hash;
    }

    /** Texto que amarra o template ao seu registro (pessoa, tipo e dedo). */
    public static function dadosAssociados(string $pessoaId, string $tipo, ?int $dedo): string
    {
        return "cadgestor:biometria:{$pessoaId}:{$tipo}:" . ($dedo ?? 0);
    }

    private function subchave(int $versao, string $contexto): string
    {
        $mestra = $this->chaves[$versao] ?? throw new RuntimeException("Versão de chave {$versao} indisponível.");

        return sodium_crypto_kdf_derive_from_key(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            1,
            $contexto,
            $mestra,
        );
    }
}
