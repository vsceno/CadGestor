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
    public const MAXIMO_CADASTROS_POR_HORA = 5;

    public function __construct(
        private readonly PDO $pdo,
        #[\SensitiveParameter] private readonly string $segredo,
    ) {
    }

    public function identificadorEmail(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), $this->segredo);
    }

    /**
     * Por e-mail, contam as falhas desde o último sucesso (login certo,
     * senha redefinida ou desbloqueio pela plataforma). Por IP, todas as da
     * janela: um sucesso em uma conta não "limpa" a rede de quem tenta várias.
     */
    public function bloqueado(string $emailHash, string $ip): bool
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT count(*) FILTER (
                       WHERE email_hash = :email AND data_hora > COALESCE((
                           SELECT max(data_hora) FROM tentativa_login WHERE email_hash = :email AND sucesso
                       ), '-infinity')
                   ) AS por_email,
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

    /** O login deste e-mail está travado por falhas recentes (independe do IP). */
    public function emailBloqueado(string $email): bool
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT count(*) FROM tentativa_login
             WHERE email_hash = :email AND NOT sucesso
               AND data_hora > now() - make_interval(mins => :janela)
               AND data_hora > COALESCE((
                   SELECT max(data_hora) FROM tentativa_login WHERE email_hash = :email AND sucesso
               ), '-infinity')
            SQL);
        $consulta->execute(['email' => $this->identificadorEmail($email), 'janela' => self::JANELA_MINUTOS]);

        return (int) $consulta->fetchColumn() >= self::MAXIMO_POR_EMAIL;
    }

    /** Zera as falhas do e-mail (a trilha só-acrescentar ganha um "sucesso"; nada é apagado). */
    public function desbloquear(string $email, string $ip): void
    {
        $this->registrar($this->identificadorEmail($email), $ip, true);
    }

    /**
     * Limite de uso de uma ação pública (ex.: pedir link de senha): no
     * máximo $maximoPorIp por IP e $maximoPorChave por chave (ex.: e-mail) na
     * janela. A chave é guardada só como HMAC.
     */
    public function excedeu(string $acao, string $ip, string $chave, int $maximoPorIp, int $maximoPorChave): bool
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT count(*) FILTER (WHERE email_hash = :ip_marcador AND ip = CAST(:ip AS inet)) AS por_ip,
                   count(*) FILTER (WHERE email_hash = :chave) AS por_chave
              FROM tentativa_login
             WHERE email_hash IN (:ip_marcador, :chave) AND sucesso AND data_hora > now() - interval '1 hour'
            SQL);
        $consulta->execute([
            'ip_marcador' => $this->marcador($acao),
            'ip' => $ip,
            'chave' => $this->marcador($acao, $chave),
        ]);
        $linha = $consulta->fetch(PDO::FETCH_ASSOC) ?: ['por_ip' => 0, 'por_chave' => 0];

        return (int) $linha['por_ip'] >= $maximoPorIp || (int) $linha['por_chave'] >= $maximoPorChave;
    }

    public function registrarUso(string $acao, string $ip, string $chave): void
    {
        $this->registrar($this->marcador($acao), $ip, true);
        $this->registrar($this->marcador($acao, $chave), $ip, true);
    }

    private function marcador(string $acao, ?string $chave = null): string
    {
        $texto = '#' . $acao . ($chave === null ? '' : '#' . mb_strtolower(trim($chave)));

        return hash_hmac('sha256', $texto, $this->segredo);
    }

    /**
     * Cadastro self-service: no máximo N contas novas por IP por hora (contra
     * abuso do teste grátis). Usa a mesma tabela com um marcador próprio e
     * sucesso = true, para não contar como falha de login.
     */
    public function excedeuCadastros(string $ip): bool
    {
        $consulta = $this->pdo->prepare(<<<'SQL'
            SELECT count(*) FROM tentativa_login
             WHERE email_hash = :marcador AND ip = CAST(:ip AS inet) AND data_hora > now() - interval '1 hour'
            SQL);
        $consulta->execute(['marcador' => $this->marcadorCadastro(), 'ip' => $ip]);

        return (int) $consulta->fetchColumn() >= self::MAXIMO_CADASTROS_POR_HORA;
    }

    public function registrarCadastro(string $ip): void
    {
        $this->registrar($this->marcadorCadastro(), $ip, true);
    }

    private function marcadorCadastro(): string
    {
        return hash_hmac('sha256', '#cadastro-self-service', $this->segredo);
    }

    public function registrar(string $emailHash, string $ip, bool $sucesso): void
    {
        // Hora real (não a do início da transação): a ordem entre falhas e sucessos importa.
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO tentativa_login (email_hash, ip, sucesso, data_hora)
            VALUES (:email, CAST(:ip AS inet), :sucesso, clock_timestamp())
            SQL)->execute(['email' => $emailHash, 'ip' => $ip, 'sucesso' => $sucesso ? 'true' : 'false']);
    }
}
