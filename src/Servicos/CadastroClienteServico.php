<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\ContextoBanco;
use CadGestor\Banco\Transacao;
use CadGestor\Cobranca\GatewayPagamento;
use CadGestor\Dominio\Cnpj;
use CadGestor\Mensagens\Mensageiro;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\HashSenha;
use CadGestor\Seguranca\Papel;
use CadGestor\Seguranca\PoliticaSenha;
use DateTimeImmutable;
use PDO;

/**
 * Cadastro self-service: a empresa contrata sozinha.
 *
 * Em uma única transação: cria a organização (com o aceite dos Termos de Uso),
 * provisiona perfis e termo modelo, cria o administrador (aguardando
 * confirmação do e-mail), abre a assinatura em teste grátis e registra a
 * assinatura no gateway, cuja primeira cobrança vence no fim do teste.
 */
final class CadastroClienteServico
{
    /** Versão dos Termos de Uso/contrato de dados aceita no cadastro (MODELO, revisar com o jurídico). */
    public const VERSAO_TERMOS = '1.0-modelo';
    public const DIAS_TESTE = 14;
    private const HORAS_VALIDADE_CONFIRMACAO = 72;

    public function __construct(
        private readonly PDO $pdo,
        private readonly OrganizacaoRepositorio $organizacoes,
        private readonly UsuarioRepositorio $usuarios,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly GatewayPagamento $gateway,
        private readonly ContextoBanco $contextoBanco,
        private readonly HashSenha $hashSenha,
        private readonly Mensageiro $mensageiro,
        private readonly Auditoria $auditoria,
        private readonly string $urlBase,
    ) {
    }

    /**
     * @param array<string, string> $entrada
     * @return string organização criada
     */
    public function cadastrar(array $entrada, string $ip, ?DateTimeImmutable $hoje = null): string
    {
        $hoje ??= new DateTimeImmutable('today');
        // O cadastro é a "loja" da plataforma: valida e cria no modo plataforma.
        $this->contextoBanco->definir(null, null, true);
        $dados = $this->validar($entrada);
        $token = bin2hex(random_bytes(32));
        $testeAte = $hoje->modify('+' . self::DIAS_TESTE . ' days');

        try {
            $organizacaoId = Transacao::executar($this->pdo, function () use ($dados, $ip, $token, $testeAte): string {
                $nome = $this->organizacoes->nomeEmUso($dados['razao_social'])
                    ? $dados['razao_social'] . ' - ' . formatar_cnpj($dados['cnpj'])
                    : $dados['razao_social'];
                $organizacaoId = $this->organizacoes->inserirCliente($nome, $dados['cnpj'], self::VERSAO_TERMOS, $ip);
                $this->organizacoes->provisionar($organizacaoId);

                $this->contextoBanco->dentroDe($organizacaoId, function () use ($dados, $token, $testeAte): void {
                    $usuarioId = $this->usuarios->inserirAguardandoConfirmacao(
                        $dados['responsavel_nome'],
                        $dados['email'],
                        $this->hashSenha->gerar($dados['senha']),
                        hash('sha256', $token),
                        (new DateTimeImmutable('+' . self::HORAS_VALIDADE_CONFIRMACAO . ' hours'))->format(DATE_ATOM),
                    );
                    $this->usuarios->definirPapeis($usuarioId, [Papel::Administrador]);

                    $assinaturaId = $this->assinaturas->inserir(
                        (string) $dados['plano']['id'],
                        'teste',
                        $testeAte->format('Y-m-d'),
                    );
                    $this->registrarNoGateway($assinaturaId, $dados, $testeAte);

                    $this->auditoria->registrar('cadastro_cliente', 'organizacao', null, null, [
                        'plano' => $dados['plano']['codigo'],
                        'teste_ate' => $testeAte->format('Y-m-d'),
                        'termos_versao' => self::VERSAO_TERMOS,
                    ]);
                });

                return $organizacaoId;
            });
        } finally {
            $this->contextoBanco->limpar();
        }

        $this->mensageiro->enviar($dados['email'], 'Confirme seu e-mail no CadGestor', sprintf(
            "Olá, %s.\n\nPara ativar o acesso de %s ao CadGestor, confirme seu e-mail:\n\n"
            . "%s/confirmar-email?token=%s\n\n"
            . "O link vale por %d horas. Seu teste grátis vai até %s.\n\n"
            . 'Se você não fez este cadastro, ignore esta mensagem.',
            $dados['responsavel_nome'],
            $dados['razao_social'],
            rtrim($this->urlBase, '/'),
            $token,
            self::HORAS_VALIDADE_CONFIRMACAO,
            $testeAte->format('d/m/Y'),
        ));

        return $organizacaoId;
    }

    /**
     * Link do e-mail. Depois de confirmado, o usuário já pode entrar.
     */
    public function confirmarEmail(string $token): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return false;
        }

        $conta = $this->usuarios->confirmarEmail(hash('sha256', $token));

        if ($conta === null) {
            return false;
        }

        $this->contextoBanco->dentroDe($conta['organizacao_id'], function () use ($conta): void {
            $this->auditoria->registrar('email_confirmado', 'usuario_sistema', $conta['usuario_id']);
        });

        return true;
    }

    /**
     * @param array<string, mixed> $dados
     */
    private function registrarNoGateway(string $assinaturaId, array $dados, DateTimeImmutable $testeAte): void
    {
        $plano = AssinaturaServico::dadosPlano($dados['plano']);
        $resultado = $this->gateway->criarAssinatura(
            ['nome' => $dados['razao_social'], 'cnpj' => $dados['cnpj'], 'email' => $dados['email']],
            $plano,
            $testeAte->modify('+1 day'),
        );
        $this->assinaturas->definirGateway(
            $assinaturaId,
            $this->gateway->nome(),
            $resultado->clienteId,
            $resultado->assinaturaId,
        );

        if ($resultado->primeiraCobranca !== null) {
            $c = $resultado->primeiraCobranca;
            $this->assinaturas->registrarFatura(
                $assinaturaId,
                $this->gateway->nome(),
                $c->id,
                $c->valorCentavos,
                $c->vencimento,
                $c->linkPagamento,
            );
        }
    }

    /**
     * @param array<string, string> $entrada
     * @return array{razao_social: string, cnpj: string, responsavel_nome: string, email: string,
     *     senha: string, plano: array<string, mixed>}
     */
    private function validar(array $entrada): array
    {
        $razao = trim(preg_replace('/\s+/u', ' ', $entrada['razao_social'] ?? '') ?? '');
        $cnpj = Cnpj::normalizar($entrada['cnpj'] ?? '');
        $nome = trim(preg_replace('/\s+/u', ' ', $entrada['responsavel_nome'] ?? '') ?? '');
        $email = mb_strtolower(trim($entrada['email'] ?? ''));
        $senha = $entrada['senha'] ?? '';
        $plano = $this->assinaturas->planoPorCodigo($entrada['plano'] ?? '');
        $erros = [];

        if (mb_strlen($razao) < 3 || mb_strlen($razao) > 150) {
            $erros['razao_social'] = 'Informe a razão social (3 a 150 caracteres).';
        }

        if (!Cnpj::valido($cnpj)) {
            $erros['cnpj'] = 'CNPJ inválido.';
        } elseif ($this->organizacoes->cnpjEmUso($cnpj)) {
            $erros['cnpj'] = 'Este CNPJ já tem cadastro. Entre com o e-mail do administrador ou fale com o comercial.';
        }

        if (mb_strlen($nome) < 3 || mb_strlen($nome) > 150) {
            $erros['responsavel_nome'] = 'Informe o nome completo do responsável.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            $erros['email'] = 'Informe um e-mail válido.';
        } elseif ($this->usuarios->emailEmUso($email)) {
            $erros['email'] = 'Este e-mail já está em uso.';
        }

        $erroSenha = PoliticaSenha::validar($senha, $email);

        if ($erroSenha !== null) {
            $erros['senha'] = $erroSenha;
        } elseif (!hash_equals($senha, $entrada['confirmacao'] ?? '')) {
            $erros['confirmacao'] = 'A confirmação não confere com a senha.';
        }

        if ($plano === null || $plano['disponivel_venda'] !== true) {
            $erros['plano'] = 'Escolha um plano.';
        }

        if (($entrada['aceite'] ?? '') !== '1') {
            $erros['aceite'] = 'É preciso aceitar os Termos de Uso e o Contrato de Tratamento de Dados.';
        }

        if ($erros !== []) {
            throw new ErroValidacao($erros);
        }

        return [
            'razao_social' => $razao,
            'cnpj' => $cnpj,
            'responsavel_nome' => $nome,
            'email' => $email,
            'senha' => $senha,
            'plano' => $plano,
        ];
    }
}
