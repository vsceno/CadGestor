<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

/**
 * Sessão do usuário com cookie protegido e expiração.
 *
 * O cookie é como a pulseira de um evento: HttpOnly impede que JavaScript a
 * "tire do pulso", Secure só a mostra em conexão cifrada e SameSite=Strict
 * impede que outro site a use em seu nome.
 */
final class Sessao
{
    public const NOME_COOKIE = 'cadgestor_sessao';
    private const DURACAO_MAXIMA_SEGUNDOS = 12 * 3600;

    /** @var array<string, mixed> */
    private array $dados = [];
    private bool $iniciada = false;
    private bool $expirou = false;

    public function __construct(
        private readonly bool $cookieSeguro,
        private readonly int $inatividadeMinutos = 30,
        private readonly bool $usarSessaoNativa = true,
    ) {
    }

    /** Sessão só em memória, para testes. */
    public static function emMemoria(): self
    {
        $sessao = new self(false, 30, false);
        $sessao->iniciada = true;

        return $sessao;
    }

    public function iniciar(): void
    {
        if ($this->iniciada) {
            return;
        }

        if ($this->usarSessaoNativa) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.use_trans_sid', '0');
            session_name(self::NOME_COOKIE);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $this->cookieSeguro,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
            $this->dados = &$_SESSION;
        }

        $this->iniciada = true;
        $this->verificarExpiracao();
    }

    /** Indica que a sessão anterior expirou nesta requisição (para avisar o usuário). */
    public function expirou(): bool
    {
        return $this->expirou;
    }

    public function obter(string $chave, mixed $padrao = null): mixed
    {
        return $this->dados[$chave] ?? $padrao;
    }

    public function definir(string $chave, mixed $valor): void
    {
        $this->dados[$chave] = $valor;
    }

    public function remover(string $chave): void
    {
        unset($this->dados[$chave]);
    }

    /** Novo ID de sessão (contra fixação de sessão), mantendo os dados. */
    public function regenerar(): void
    {
        if ($this->usarSessaoNativa && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $this->dados['_iniciada_em'] = time();
    }

    public function destruir(): void
    {
        foreach (array_keys($this->dados) as $chave) {
            unset($this->dados[$chave]);
        }

        if ($this->usarSessaoNativa && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function flash(string $tipo, string $mensagem): void
    {
        $this->dados['_flash'][] = ['tipo' => $tipo, 'mensagem' => $mensagem];
    }

    /** @return list<array{tipo: string, mensagem: string}> */
    public function consumirFlash(): array
    {
        $mensagens = $this->dados['_flash'] ?? [];
        unset($this->dados['_flash']);

        return is_array($mensagens) ? array_values($mensagens) : [];
    }

    private function verificarExpiracao(): void
    {
        $agora = time();
        $ultimo = $this->dados['_ultimo_acesso'] ?? null;
        $iniciada = $this->dados['_iniciada_em'] ?? null;

        $inativa = is_int($ultimo) && ($agora - $ultimo) > $this->inatividadeMinutos * 60;
        $antiga = is_int($iniciada) && ($agora - $iniciada) > self::DURACAO_MAXIMA_SEGUNDOS;

        if (($inativa || $antiga) && isset($this->dados['usuario_id'])) {
            $this->destruir();
            $this->expirou = true;
        }

        $this->dados['_ultimo_acesso'] = $agora;
        $this->dados['_iniciada_em'] ??= $agora;
    }
}
