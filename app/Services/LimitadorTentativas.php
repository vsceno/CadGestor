<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Bloqueio temporário contra adivinhação de senha e abuso de ações públicas.
 *
 * O e-mail é guardado como HMAC (impressão digital com segredo, a APP_KEY),
 * nunca em texto. A tabela é só-acrescentar: desbloquear registra um
 * "sucesso", não apaga nada.
 */
class LimitadorTentativas
{
    public const JANELA_MINUTOS = 15;

    public const MAXIMO_POR_EMAIL = 5;

    public const MAXIMO_POR_IP = 20;

    public function identificadorEmail(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), $this->segredo());
    }

    /**
     * Por e-mail, contam as falhas desde o último sucesso (login certo, senha
     * redefinida ou desbloqueio). Por IP, todas as da janela: um login certo
     * numa conta não "limpa" a rede de quem tenta várias.
     */
    public function bloqueado(string $email, string $ip): bool
    {
        $linha = DB::connection('pgsql')->selectOne(<<<'SQL'
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
            SQL, ['email' => $this->identificadorEmail($email), 'ip' => $ip, 'janela' => self::JANELA_MINUTOS]);

        return (int) $linha->por_email >= self::MAXIMO_POR_EMAIL || (int) $linha->por_ip >= self::MAXIMO_POR_IP;
    }

    /** O login deste e-mail está travado por falhas recentes (independe do IP). */
    public function emailBloqueado(string $email): bool
    {
        $total = DB::connection('pgsql')->scalar(<<<'SQL'
            SELECT count(*) FROM tentativa_login
             WHERE email_hash = :email AND NOT sucesso
               AND data_hora > now() - make_interval(mins => :janela)
               AND data_hora > COALESCE((
                   SELECT max(data_hora) FROM tentativa_login WHERE email_hash = :email AND sucesso
               ), '-infinity')
            SQL, ['email' => $this->identificadorEmail($email), 'janela' => self::JANELA_MINUTOS]);

        return (int) $total >= self::MAXIMO_POR_EMAIL;
    }

    public function registrar(string $email, string $ip, bool $sucesso): void
    {
        $this->inserir($this->identificadorEmail($email), $ip, $sucesso);
    }

    /** Zera as falhas do e-mail (registra um "sucesso"; nada é apagado). */
    public function desbloquear(string $email, string $ip): void
    {
        $this->registrar($email, $ip, true);
    }

    /**
     * Limite de uma ação pública (ex.: pedir link de senha): no máximo
     * $maximoPorIp por IP e $maximoPorChave por chave (ex.: e-mail) por hora.
     */
    public function excedeu(string $acao, string $ip, string $chave, int $maximoPorIp, int $maximoPorChave): bool
    {
        $linha = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT count(*) FILTER (WHERE email_hash = :marcador_ip AND ip = CAST(:ip AS inet)) AS por_ip,
                   count(*) FILTER (WHERE email_hash = :chave) AS por_chave
              FROM tentativa_login
             WHERE email_hash IN (:marcador_ip, :chave) AND sucesso AND data_hora > now() - interval '1 hour'
            SQL, ['marcador_ip' => $this->marcador($acao), 'ip' => $ip, 'chave' => $this->marcador($acao, $chave)]);

        return (int) $linha->por_ip >= $maximoPorIp || (int) $linha->por_chave >= $maximoPorChave;
    }

    public function registrarUso(string $acao, string $ip, string $chave): void
    {
        $this->inserir($this->marcador($acao), $ip, true);
        $this->inserir($this->marcador($acao, $chave), $ip, true);
    }

    private function inserir(string $hash, string $ip, bool $sucesso): void
    {
        // Hora real (não a do início da transação): a ordem entre falhas e sucessos importa.
        DB::connection('pgsql')->insert(
            'INSERT INTO tentativa_login (email_hash, ip, sucesso, data_hora) VALUES (?, CAST(? AS inet), ?, clock_timestamp())',
            [$hash, $ip, $sucesso],
        );
    }

    private function marcador(string $acao, ?string $chave = null): string
    {
        return hash_hmac('sha256', '#'.$acao.($chave === null ? '' : '#'.mb_strtolower(trim($chave))), $this->segredo());
    }

    private function segredo(): string
    {
        return (string) config('app.key');
    }
}
