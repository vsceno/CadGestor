<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

use PDO;

/**
 * Bloqueio temporário contra adivinhação de senha (força bruta).
 *
 * Conta as falhas recentes por e-mail e por IP. O e-mail é guardado como
 * HMAC (impressão digital com segredo), não em texto.
 */
final class LimitadorTentativas
{
    public const JANELA_MINUTOS = 15;
    public const MAXIMO_POR_EMAIL = 5;
    public const MAXIMO_POR_IP = 20;

    public function __construct(
        private readonly PDO $pdo,
        #[\SensitiveParameter] private readonly string $segredo,
    ) {
    }

    public function identificadorEmail(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), $this->segredo);
    }

    public function bloqueado(string $emailHash, string $ip): bool
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT count(*) FILTER (WHERE email_hash = :email) AS por_email,
                   count(*) FILTER (WHERE ip = CAST(:ip AS inet)) AS por_ip
              FROM tentativa_login
             WHERE NOT sucesso
               AND data_hora > now() - make_interval(mins => :janela)
               AND (email_hash = :email OR ip = CAST(:ip AS inet))
            SQL);
        $consulta->execute(['email' => $emailHash, 'ip' => $ip, 'janela' => self::JANELA_MINUTOS]);
        $linha = $consulta->fetch(PDO::FETCH_ASSOC) ?: ['por_email' => 0, 'por_ip' => 0];

        return (int) $linha['por_email'] >= self::MAXIMO_POR_EMAIL
            || (int) $linha['por_ip'] >= self::MAXIMO_POR_IP;
    }

    public function registrar(string $emailHash, string $ip, bool $sucesso): void
    {
        $this->pdo->prepare(
            'INSERT INTO tentativa_login (email_hash, ip, sucesso) VALUES (:email, CAST(:ip AS inet), :sucesso)'
        )->execute(['email' => $emailHash, 'ip' => $ip, 'sucesso' => $sucesso ? 'true' : 'false']);
    }
}
