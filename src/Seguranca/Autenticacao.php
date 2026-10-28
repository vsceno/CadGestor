<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Nucleo\Contexto;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Servicos\Auditoria;

/**
 * Login, logout e identificação do usuário da requisição.
 *
 * Também define, a cada requisição, a organização em que o banco vai operar
 * (ContextoBanco): a do usuário. O usuário da plataforma opera sempre fora das
 * organizações (a plataforma só vende e controla planos; não entra no
 * ambiente dos clientes).
 */
final class Autenticacao
{
    public const OK = 'ok';
    public const FALHA = 'falha';
    public const BLOQUEADO = 'bloqueado';
    public const EMAIL_NAO_CONFIRMADO = 'email_nao_confirmado';

    /**
     * Hash Argon2id de uma senha aleatória descartada. Quando o e-mail não
     * existe, conferimos a senha contra ele para que a resposta demore o mesmo
     * tempo e não revele quais e-mails estão cadastrados.
     */
    private const HASH_FICTICIO =
        '$argon2id$v=19$m=65536,t=4,p=1$YmV1WmJLTjcxLjlnaEFiTA$xe0l7+i6rmT4bxChorI/XkqqvHxn4lzMz4udMJAxt+c';

    private ?UsuarioAutenticado $usuarioAtual = null;
    private bool $carregado = false;

    public function __construct(
        private readonly UsuarioRepositorio $usuarios,
        private readonly OrganizacaoRepositorio $organizacoes,
        private readonly ContextoBanco $contextoBanco,
        private readonly Sessao $sessao,
        private readonly Csrf $csrf,
        private readonly HashSenha $hashSenha,
        private readonly LimitadorTentativas $limitador,
        private readonly Auditoria $auditoria,
        private readonly Contexto $contexto,
    ) {
    }

    /** @return self::OK|self::FALHA|self::BLOQUEADO|self::EMAIL_NAO_CONFIRMADO */
    public function entrar(string $email, #[\SensitiveParameter] string $senha): string
    {
        $email = mb_strtolower(trim($email));
        $emailHash = $this->limitador->identificadorEmail($email);

        if ($this->limitador->bloqueado($emailHash, $this->contexto->ip)) {
            return self::BLOQUEADO;
        }

        $conta = $email === '' ? null : $this->usuarios->localizarParaLogin($email);
        $usuario = null;

        if ($conta !== null) {
            // A partir daqui o banco opera na organização da conta (falhas vão para a auditoria dela).
            $this->contextoBanco->definir($conta['organizacao_id'], $conta['usuario_id']);
            $usuario = $this->usuarios->buscarPorId($conta['usuario_id']);
        }

        $senhaConfere = $this->hashSenha->verificar($senha, $usuario['senha_hash'] ?? self::HASH_FICTICIO);
        $sucesso = $usuario !== null && $senhaConfere && $usuario['ativo'] === true
            && $this->organizacaoPermiteAcesso($conta['organizacao_id']);

        $this->limitador->registrar($emailHash, $this->contexto->ip, $sucesso);

        if (!$sucesso) {
            // Sem registro_id de usuário inexistente; o e-mail não vai para a trilha.
            $this->auditoria->registrar('login_falhou', 'usuario_sistema', $usuario['id'] ?? null);
            $this->contextoBanco->limpar();

            return self::FALHA;
        }

        $id = (string) $usuario['id'];

        // Só depois da senha correta, para não revelar quais e-mails existem.
        if ($usuario['email_verificado_em'] === null) {
            $this->contextoBanco->limpar();

            return self::EMAIL_NAO_CONFIRMADO;
        }

        if ($this->hashSenha->precisaRefazer((string) $usuario['senha_hash'])) {
            $this->usuarios->definirSenha($id, $this->hashSenha->gerar($senha), (bool) $usuario['deve_trocar_senha']);
        }

        $this->usuarios->registrarLogin($id);
        $this->sessao->regenerar();
        $this->csrf->renovar();
        $this->sessao->definir('usuario_id', $id);
        $this->sessao->definir('organizacao_id', $conta['organizacao_id']);
        $this->contexto->usuarioId = $id;
        $this->carregado = false;

        $this->auditoria->registrar('login', 'usuario_sistema', $id);

        return self::OK;
    }

    public function sair(): void
    {
        if ($this->usuario() !== null) {
            $this->auditoria->registrar('logout', 'usuario_sistema', $this->usuario()->id);
        }

        $this->sessao->destruir();
        $this->usuarioAtual = null;
        $this->contexto->usuarioId = null;
        $this->contextoBanco->limpar();
    }

    /**
     * Usuário da sessão, recarregado do banco a cada requisição: se ele for
     * desativado, perder um papel ou a organização for suspensa, o efeito é
     * imediato.
     */
    public function usuario(): ?UsuarioAutenticado
    {
        if ($this->carregado) {
            return $this->usuarioAtual;
        }

        $this->carregado = true;
        $id = $this->sessao->obter('usuario_id');

        if (!is_string($id)) {
            return null;
        }

        $organizacaoId = $this->sessao->obter('organizacao_id');
        $organizacaoId = is_string($organizacaoId) ? $organizacaoId : null;
        $this->contextoBanco->definir($organizacaoId, $id);

        $dados = $this->usuarios->buscarPorId($id);

        if ($dados === null || $dados['ativo'] !== true) {
            return $this->encerrar();
        }

        $daPlataforma = $dados['organizacao_id'] === null;
        $organizacao = $organizacaoId === null ? null : $this->organizacoes->buscar($organizacaoId);
        $organizacaoAtiva = ($organizacao['ativo'] ?? false) === true;

        // Usuário de organização: precisa estar na dele e ela, ativa. Plataforma: nunca dentro de uma.
        $sessaoValida = $daPlataforma
            ? $organizacaoId === null
            : $dados['organizacao_id'] === $organizacaoId && $organizacaoAtiva;

        if (!$sessaoValida) {
            return $this->encerrar();
        }

        if ($daPlataforma) {
            $this->contextoBanco->definir(null, $id, true);
        }

        $papeis = match (true) {
            $daPlataforma => [Papel::Plataforma],
            default => array_values(array_filter(array_map(
                static fn (string $papel): ?Papel => Papel::tryFrom($papel),
                $dados['papeis'],
            ), static fn (?Papel $papel): bool => $papel !== null && $papel !== Papel::Plataforma)),
        };

        $this->usuarioAtual = new UsuarioAutenticado(
            $id,
            (string) $dados['nome'],
            (string) $dados['email'],
            $papeis,
            (bool) $dados['deve_trocar_senha'],
            $organizacaoId,
            $organizacao === null ? null : (string) $organizacao['nome'],
            $daPlataforma,
        );
        $this->contexto->usuarioId = $id;

        return $this->usuarioAtual;
    }

    /** Recarrega o usuário (ex.: após trocar a senha). */
    public function recarregar(): void
    {
        $this->carregado = false;
        $this->usuarioAtual = null;
    }

    /** Usuários de organização só entram se ela estiver ativa. */
    private function organizacaoPermiteAcesso(?string $organizacaoId): bool
    {
        return $organizacaoId === null || ($this->organizacoes->buscar($organizacaoId)['ativo'] ?? false) === true;
    }

    private function encerrar(): null
    {
        $this->sessao->destruir();
        $this->contextoBanco->limpar();

        return null;
    }
}
