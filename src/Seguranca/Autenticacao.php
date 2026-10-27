<?php

declare(strict_types=1);

namespace CadGestor\Seguranca;

use CadGestor\Nucleo\Contexto;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Servicos\Auditoria;

/**
 * Login, logout e identificação do usuário da requisição.
 */
final class Autenticacao
{
    public const OK = 'ok';
    public const FALHA = 'falha';
    public const BLOQUEADO = 'bloqueado';

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
        private readonly Sessao $sessao,
        private readonly Csrf $csrf,
        private readonly HashSenha $hashSenha,
        private readonly LimitadorTentativas $limitador,
        private readonly Auditoria $auditoria,
        private readonly Contexto $contexto,
    ) {
    }

    /** @return self::OK|self::FALHA|self::BLOQUEADO */
    public function entrar(string $email, #[\SensitiveParameter] string $senha): string
    {
        $email = mb_strtolower(trim($email));
        $emailHash = $this->limitador->identificadorEmail($email);

        if ($this->limitador->bloqueado($emailHash, $this->contexto->ip)) {
            return self::BLOQUEADO;
        }

        $usuario = $email === '' ? null : $this->usuarios->buscarPorEmail($email);
        $senhaConfere = $this->hashSenha->verificar($senha, $usuario['senha_hash'] ?? self::HASH_FICTICIO);
        $sucesso = $usuario !== null && $senhaConfere && $usuario['ativo'] === true;

        $this->limitador->registrar($emailHash, $this->contexto->ip, $sucesso);

        if (!$sucesso) {
            // Sem registro_id de usuário inexistente; o e-mail não vai para a trilha.
            $this->auditoria->registrar('login_falhou', 'usuario_sistema', $usuario['id'] ?? null);

            return self::FALHA;
        }

        $id = (string) $usuario['id'];

        if ($this->hashSenha->precisaRefazer((string) $usuario['senha_hash'])) {
            $this->usuarios->definirSenha($id, $this->hashSenha->gerar($senha), (bool) $usuario['deve_trocar_senha']);
        }

        $this->usuarios->registrarLogin($id);
        $this->sessao->regenerar();
        $this->csrf->renovar();
        $this->sessao->definir('usuario_id', $id);
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
    }

    /**
     * Usuário da sessão, recarregado do banco a cada requisição: se ele for
     * desativado ou perder um papel, o efeito é imediato.
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

        $dados = $this->usuarios->buscarPorId($id);

        if ($dados === null || $dados['ativo'] !== true) {
            $this->sessao->destruir();

            return null;
        }

        $this->usuarioAtual = new UsuarioAutenticado(
            $id,
            (string) $dados['nome'],
            (string) $dados['email'],
            array_values(array_filter(array_map(
                static fn (string $papel): ?Papel => Papel::tryFrom($papel),
                $dados['papeis'],
            ))),
            (bool) $dados['deve_trocar_senha'],
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
}
