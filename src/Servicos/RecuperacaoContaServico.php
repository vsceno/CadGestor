<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Banco\Transacao;
use CadGestor\Mensagens\Mensageiro;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Seguranca\LimitadorTentativas;
use CadGestor\Seguranca\PoliticaSenha;
use DateTimeImmutable;
use PDO;

/**
 * Recuperação de acesso sem que ninguém veja ou escolha a senha de outra
 * pessoa: "esqueci minha senha", link de redefinição disparado pela
 * plataforma e reenvio do e-mail de confirmação.
 *
 * O link é como a chave de um cofre de banco enviada pelo correio para o
 * endereço do titular: só quem tem acesso à caixa de e-mail consegue usá-la,
 * ela vale por pouco tempo, abre uma vez só e o banco guarda apenas a
 * "impressão digital" (hash) dela.
 */
final class RecuperacaoContaServico
{
    public const MINUTOS_VALIDADE_REDEFINICAO = 60;
    public const MINUTOS_VALIDADE_CONTA_NOVA = 72 * 60;
    public const ACAO_REDEFINICAO = 'redefinicao_senha';
    public const ACAO_REENVIO = 'reenvio_confirmacao';

    public function __construct(
        private readonly PDO $pdo,
        private readonly UsuarioRepositorio $usuarios,
        private readonly OrganizacaoRepositorio $organizacoes,
        private readonly ContextoBanco $contextoBanco,
        private readonly HashSenha $hashSenha,
        private readonly LimitadorTentativas $limitador,
        private readonly Mensageiro $mensageiro,
        private readonly Auditoria $auditoria,
        private readonly string $urlBase,
    ) {
    }

    /**
     * "Esqueci minha senha". Não diz se o e-mail existe: quem chama mostra
     * sempre a mesma mensagem. Devolve false só quando o limite de pedidos
     * foi atingido (aí nada é enviado).
     */
    public function solicitarRedefinicao(string $email, string $ip): bool
    {
        $email = mb_strtolower(trim($email));

        if ($this->limitador->excedeu(self::ACAO_REDEFINICAO, $ip, $email, 10, 3)) {
            return false;
        }

        $this->limitador->registrarUso(self::ACAO_REDEFINICAO, $ip, $email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return true;
        }

        $conta = $this->usuarios->localizarParaLogin($email);

        if ($conta === null) {
            return true;
        }

        // A conta passa a ser o contexto: ela se enxerga (RLS) e o pedido vai para a trilha da organização dela.
        $this->contextoBanco->definir($conta['organizacao_id'], $conta['usuario_id']);

        try {
            $usuario = $this->usuarios->buscarPorId($conta['usuario_id']);

            if ($usuario !== null && $usuario['ativo'] === true && $this->organizacaoAtiva($conta['organizacao_id'])) {
                $this->enviarLinkRedefinicao($usuario, 'esqueci_senha');
            }
        } finally {
            $this->contextoBanco->limpar();
        }

        return true;
    }

    /**
     * Gera e envia o link para o e-mail do próprio usuário. Roda no contexto
     * já definido por quem chama (a conta, ou a organização dela quando a
     * plataforma dispara).
     *
     * @param array<string, mixed> $usuario
     * @param bool $contaNova administrador recém-criado: o link serve para definir a primeira senha
     */
    public function enviarLinkRedefinicao(array $usuario, string $via, bool $contaNova = false): void
    {
        $token = bin2hex(random_bytes(32));
        $minutos = $contaNova ? self::MINUTOS_VALIDADE_CONTA_NOVA : self::MINUTOS_VALIDADE_REDEFINICAO;
        $expira = (new DateTimeImmutable("+{$minutos} minutes"))->format(DATE_ATOM);
        $id = (string) $usuario['id'];

        Transacao::executar($this->pdo, function () use ($id, $token, $expira, $via): void {
            $this->usuarios->definirTokenRedefinicao($id, hash('sha256', $token), $expira);
            $this->auditoria->registrar('redefinicao_solicitada', 'usuario_sistema', $id, null, ['via' => $via]);
        });

        $link = sprintf('%s/redefinir-senha?token=%s', rtrim($this->urlBase, '/'), $token);
        $nome = self::primeiroNome((string) $usuario['nome']);
        $aviso = "Ninguém da equipe do CadGestor pede a sua senha.";

        if ($contaNova) {
            $this->mensageiro->enviar((string) $usuario['email'], 'Seu acesso ao CadGestor', sprintf(
                "Olá, %s.\n\nVocê foi cadastrado(a) como administrador(a) no CadGestor. Para criar a sua senha, "
                . "acesse:\n\n%s\n\nO link vale por %d horas e pode ser usado uma única vez. Depois, entre com este "
                . "e-mail e a senha que você criou.\n\n%s",
                $nome,
                $link,
                intdiv($minutos, 60),
                $aviso,
            ));

            return;
        }

        $this->mensageiro->enviar((string) $usuario['email'], 'Redefinição de senha do CadGestor', sprintf(
            "Olá, %s.\n\nRecebemos um pedido para redefinir a sua senha do CadGestor. Para criar uma nova senha, "
            . "acesse:\n\n%s\n\nO link vale por %d minutos e pode ser usado uma única vez.\n\n"
            . "Se você não pediu, ignore esta mensagem: sua senha atual continua valendo. %s",
            $nome,
            $link,
            $minutos,
            $aviso,
        ));
    }

    /** O link ainda vale? (para mostrar o formulário de nova senha) */
    public function linkValido(string $token): bool
    {
        return self::formatoToken($token) && $this->usuarios->contaPorRedefinicao(hash('sha256', $token)) !== null;
    }

    /**
     * Define a nova senha pelo link. Encerra as sessões abertas e libera o
     * login travado por tentativas.
     */
    public function redefinir(
        string $token,
        #[\SensitiveParameter] string $senha,
        #[\SensitiveParameter] string $confirmacao,
        string $ip,
    ): void {
        $invalido = ErroValidacao::campo('token', 'Este link não vale mais. Peça um novo em "Esqueci minha senha".');
        $conta = self::formatoToken($token) ? $this->usuarios->contaPorRedefinicao(hash('sha256', $token)) : null;

        if ($conta === null) {
            throw $invalido;
        }

        $erro = PoliticaSenha::validar($senha, $conta['email']);

        if ($erro !== null) {
            throw ErroValidacao::campo('senha', $erro);
        }

        if (!hash_equals($senha, $confirmacao)) {
            throw ErroValidacao::campo('confirmacao', 'A confirmação não confere com a nova senha.');
        }

        $this->contextoBanco->definir($conta['organizacao_id'], $conta['usuario_id']);

        try {
            Transacao::executar($this->pdo, function () use ($token, $senha, $conta, $ip, $invalido): void {
                $hash = $this->hashSenha->gerar($senha);

                if ($this->usuarios->redefinirSenhaPorToken(hash('sha256', $token), $hash) === null) {
                    throw $invalido;
                }

                $this->auditoria->registrar('senha_alterada', 'usuario_sistema', $conta['usuario_id'], null, [
                    'via' => 'link',
                ]);
                $this->limitador->desbloquear($conta['email'], $ip);
            });
        } finally {
            $this->contextoBanco->limpar();
        }
    }

    /**
     * Reenvio pedido na tela de login, logo depois de a pessoa acertar a
     * senha de uma conta ainda não confirmada. Devolve false se o limite foi atingido.
     */
    public function reenviarConfirmacaoPeloLogin(string $usuarioId, string $ip): bool
    {
        if ($this->limitador->excedeu(self::ACAO_REENVIO, $ip, $usuarioId, 10, 3)) {
            return false;
        }

        $this->limitador->registrarUso(self::ACAO_REENVIO, $ip, $usuarioId);
        $this->contextoBanco->definir(null, $usuarioId);

        try {
            $usuario = $this->usuarios->buscarPorId($usuarioId);

            if ($usuario !== null && $usuario['ativo'] === true) {
                $this->contextoBanco->definir($usuario['organizacao_id'], $usuarioId);
                $this->reenviarConfirmacao($usuario, 'login');
            }
        } finally {
            $this->contextoBanco->limpar();
        }

        return true;
    }

    /**
     * Novo link de confirmação de e-mail (o anterior deixa de valer). Roda no
     * contexto da organização do usuário. Devolve false se ele já confirmou.
     *
     * @param array<string, mixed> $usuario
     */
    public function reenviarConfirmacao(array $usuario, string $via): bool
    {
        if ($usuario['email_verificado_em'] !== null) {
            return false;
        }

        $token = bin2hex(random_bytes(32));
        $horas = CadastroClienteServico::HORAS_VALIDADE_CONFIRMACAO;

        Transacao::executar($this->pdo, function () use ($usuario, $token, $horas, $via): void {
            $this->usuarios->definirTokenConfirmacao(
                (string) $usuario['id'],
                hash('sha256', $token),
                (new DateTimeImmutable("+{$horas} hours"))->format(DATE_ATOM),
            );
            $this->auditoria->registrar('confirmacao_reenviada', 'usuario_sistema', (string) $usuario['id'], null, [
                'via' => $via,
            ]);
        });

        $this->mensageiro->enviar((string) $usuario['email'], 'Confirme seu e-mail no CadGestor', sprintf(
            "Olá, %s.\n\nPara ativar o seu acesso ao CadGestor, confirme seu e-mail:\n\n%s/confirmar-email?token=%s\n\n"
            . "O link vale por %d horas. Links enviados antes deste deixaram de valer.\n\n"
            . 'Se você não fez este cadastro, ignore esta mensagem.',
            self::primeiroNome((string) $usuario['nome']),
            rtrim($this->urlBase, '/'),
            $token,
            $horas,
        ));

        return true;
    }

    private function organizacaoAtiva(?string $organizacaoId): bool
    {
        return $organizacaoId === null || ($this->organizacoes->buscar($organizacaoId)['ativo'] ?? false) === true;
    }

    private static function formatoToken(string $token): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $token) === 1;
    }

    private static function primeiroNome(string $nome): string
    {
        return explode(' ', trim($nome))[0] ?: $nome;
    }
}
