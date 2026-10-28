<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Dominio\DadosCadastraisCliente;
use CadGestor\Dominio\SituacaoAssinatura;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Repositorios\OrganizacaoRepositorio;
use CadGestor\Repositorios\UsuarioRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\AtendimentoClienteServico;
use CadGestor\Servicos\EmpresaServico;
use CadGestor\Servicos\OrganizacaoServico;
use Closure;
use DateTimeImmutable;

/**
 * Painel comercial da plataforma: clientes (organizações), cadastro, plano,
 * situação da assinatura, faturas e atendimento. A plataforma NÃO entra no
 * ambiente do cliente: daqui só se veem contagens e dados comerciais.
 */
final class OrganizacaoController extends Controlador
{
    private const CAMPOS = ['nome', 'cnpj', ...DadosCadastraisCliente::CAMPOS_CONTATO];
    private const STATUS = ['teste', 'ativa', 'inadimplente', 'cancelada'];

    public function __construct(
        Sessao $sessao,
        private readonly OrganizacaoRepositorio $organizacoes,
        private readonly UsuarioRepositorio $usuarios,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly OrganizacaoServico $servico,
        private readonly AtendimentoClienteServico $atendimento,
    ) {
        parent::__construct($sessao);
    }

    /**
     * Lista com indicadores. A busca chega por POST (/organizacoes/busca)
     * para que nome e CNPJ pesquisados não fiquem em URLs e históricos.
     *
     * @param array<string, string> $parametros
     */
    public function index(Requisicao $requisicao, array $parametros): Resposta
    {
        $status = $requisicao->entrada('status');
        $filtros = [
            'q' => mb_substr($requisicao->entrada('q'), 0, 100),
            'status' => in_array($status, self::STATUS, true) ? $status : '',
            'plano' => $requisicao->entrada('plano'),
        ];
        $hoje = new DateTimeImmutable('today');
        $assinaturas = $this->assinaturas->resumoPorOrganizacao();
        $organizacoes = [];
        $indicadores = ['receita_mensal' => 0, 'ativas' => 0, 'teste' => 0, 'inadimplentes' => 0, 'canceladas' => 0];

        foreach ($this->organizacoes->listarComResumo() as $o) {
            $a = $assinaturas[$o['id']] ?? null;
            $o['assinatura'] = $a;
            $o['situacao'] = $a === null
                ? SituacaoAssinatura::semAssinatura()
                : SituacaoAssinatura::calcular($a['status'], $a['teste_ate'], $a['inadimplente_desde'], $hoje);

            match ($a['status'] ?? null) {
                'ativa' => $indicadores['ativas']++,
                'teste' => $indicadores['teste']++,
                'inadimplente' => $indicadores['inadimplentes']++,
                'cancelada' => $indicadores['canceladas']++,
                default => null,
            };

            if (in_array($a['status'] ?? null, ['ativa', 'inadimplente'], true) && $o['ativo']) {
                $indicadores['receita_mensal'] += (int) $a['preco_centavos'];
            }

            if (self::atendeFiltros($o, $filtros)) {
                $organizacoes[] = $o;
            }
        }

        return $this->pagina('organizacoes/index', [
            'titulo' => 'Clientes',
            'organizacoes' => $organizacoes,
            'indicadores' => $indicadores,
            'filtros' => $filtros,
            'filtrando' => implode('', $filtros) !== '',
            'planos' => $this->assinaturas->planos(),
        ]);
    }

    /** @param array<string, string> $parametros */
    public function novo(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->formulario(null, ['plano_id' => ''], []);
    }

    /** @param array<string, string> $parametros */
    public function criar(Requisicao $requisicao, array $parametros): Resposta
    {
        $dados = $this->entrada($requisicao) + ['plano_id' => $requisicao->entrada('plano_id')];

        try {
            $id = $this->servico->criar($dados);
        } catch (ErroValidacao $erro) {
            return $this->formulario(null, $dados, $erro->erros, 422);
        }

        return $this->redirecionar(
            '/organizacoes/' . $id,
            'Cliente criado com a assinatura ativa. Agora cadastre o administrador dele.',
        );
    }

    /** @param array<string, string> $parametros */
    public function ver(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->detalhe($parametros['id'], ['nome' => '', 'email' => ''], []);
    }

    /** @param array<string, string> $parametros */
    public function editar(Requisicao $requisicao, array $parametros): Resposta
    {
        $organizacao = $this->organizacoes->buscar($parametros['id']);

        return $organizacao === null ? $this->naoEncontrado() : $this->formulario($parametros['id'], $organizacao, []);
    }

    /** @param array<string, string> $parametros */
    public function atualizar(Requisicao $requisicao, array $parametros): Resposta
    {
        $dados = $this->entrada($requisicao);

        try {
            $this->servico->atualizar($parametros['id'], $dados);
        } catch (ErroValidacao $erro) {
            return isset($erro->erros['id'])
                ? $this->naoEncontrado()
                : $this->formulario($parametros['id'], $dados, $erro->erros, 422);
        }

        return $this->redirecionar('/organizacoes/' . $parametros['id'], 'Cadastro do cliente atualizado.');
    }

    /** @param array<string, string> $parametros */
    public function alterarSituacao(Requisicao $requisicao, array $parametros): Resposta
    {
        $ativo = $requisicao->entrada('ativo') === '1';

        try {
            $this->servico->alterarSituacao($parametros['id'], $ativo);
        } catch (ErroValidacao) {
            return $this->naoEncontrado();
        }

        return $this->redirecionar('/organizacoes/' . $parametros['id'], $ativo
            ? 'Cliente reativado.'
            : 'Cliente bloqueado: os usuários dele não entram e as catracas são recusadas.');
    }

    /** @param array<string, string> $parametros */
    public function criarAdministrador(Requisicao $requisicao, array $parametros): Resposta
    {
        $dados = ['nome' => $requisicao->entrada('nome'), 'email' => $requisicao->entrada('email')];

        try {
            $this->servico->criarAdministrador($parametros['id'], $dados['nome'], $dados['email']);
        } catch (ErroValidacao $erro) {
            return isset($erro->erros['id'])
                ? $this->naoEncontrado()
                : $this->detalhe($parametros['id'], $dados, $erro->erros, 422);
        }

        return $this->redirecionar(
            '/organizacoes/' . $parametros['id'],
            'Administrador criado. Ele recebeu no e-mail um link para criar a própria senha (vale por 72 horas).',
        );
    }

    // --- Atendimento: assinatura ---------------------------------------------------

    /** @param array<string, string> $parametros */
    public function trocarPlano(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->atender($parametros['id'], 'Plano do cliente alterado.', fn () => $this->atendimento->trocarPlano(
            $parametros['id'],
            $requisicao->entrada('plano_id'),
            $requisicao->entrada('motivo'),
        ));
    }

    /** @param array<string, string> $parametros */
    public function estenderTeste(Requisicao $requisicao, array $parametros): Resposta
    {
        $novoFim = null;
        $id = $parametros['id'];
        $resposta = $this->atender($id, null, function () use ($requisicao, $id, &$novoFim): void {
            $novoFim = $this->atendimento->estenderTeste(
                $id,
                (int) $requisicao->entrada('dias'),
                $requisicao->entrada('motivo'),
            );
        });

        if ($novoFim !== null) {
            $this->sessao->flash('sucesso', 'Teste grátis estendido até ' . formatar_data($novoFim) . '.');
        }

        return $resposta;
    }

    /** @param array<string, string> $parametros */
    public function darBaixa(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->atender(
            $parametros['id'],
            'Pagamento registrado. A fatura está paga.',
            fn () => $this->atendimento->darBaixaManual(
                $parametros['id'],
                $parametros['fatura'],
                $requisicao->entrada('motivo'),
            ),
        );
    }

    /** @param array<string, string> $parametros */
    public function anotar(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->atender($parametros['id'], 'Anotação registrada.', fn () => $this->atendimento->anotar(
            $parametros['id'],
            $requisicao->entrada('texto'),
        ));
    }

    // --- Atendimento: acesso dos administradores -----------------------------------

    /** @param array<string, string> $parametros */
    public function enviarRedefinicao(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->atender(
            $parametros['id'],
            'Link de redefinição enviado para o e-mail do administrador (vale por 1 hora).',
            fn () => $this->atendimento->enviarLinkRedefinicao($parametros['id'], $parametros['usuario']),
        );
    }

    /** @param array<string, string> $parametros */
    public function reenviarConfirmacao(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->atender(
            $parametros['id'],
            'Novo link de confirmação enviado para o e-mail do administrador.',
            fn () => $this->atendimento->reenviarConfirmacao($parametros['id'], $parametros['usuario']),
        );
    }

    /** @param array<string, string> $parametros */
    public function desbloquearLogin(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->atender(
            $parametros['id'],
            'Login liberado. A senha não foi alterada.',
            fn () => $this->atendimento->desbloquearLogin($parametros['id'], $parametros['usuario'], $requisicao->ip()),
        );
    }

    /** @param array<string, string> $parametros */
    public function situacaoAdministrador(Requisicao $requisicao, array $parametros): Resposta
    {
        $ativo = $requisicao->entrada('ativo') === '1';

        return $this->atender(
            $parametros['id'],
            $ativo ? 'Administrador reativado.' : 'Administrador desativado: ele não consegue mais entrar.',
            fn () => $this->atendimento->alterarSituacaoAdministrador(
                $parametros['id'],
                $parametros['usuario'],
                $ativo,
            ),
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Ações de atendimento: em caso de erro de validação, volta para o
     * cliente com a mensagem (registro inexistente vira 404).
     */
    private function atender(string $id, ?string $sucesso, Closure $acao): Resposta
    {
        try {
            $acao();
        } catch (ErroValidacao $erro) {
            return isset($erro->erros['id'])
                ? $this->naoEncontrado()
                : $this->redirecionar('/organizacoes/' . $id, implode(' ', $erro->erros), 'erro');
        }

        return $this->redirecionar('/organizacoes/' . $id, $sucesso);
    }

    /** @return array<string, string> */
    private function entrada(Requisicao $requisicao): array
    {
        $dados = [];

        foreach (self::CAMPOS as $campo) {
            $dados[$campo] = $requisicao->entrada($campo);
        }

        return $dados;
    }

    /**
     * @param array<string, mixed>  $organizacao
     * @param array<string, string> $filtros
     */
    private static function atendeFiltros(array $organizacao, array $filtros): bool
    {
        $a = $organizacao['assinatura'];

        if ($filtros['status'] !== '' && ($a['status'] ?? '') !== $filtros['status']) {
            return false;
        }

        if ($filtros['plano'] !== '' && ($a['plano_codigo'] ?? '') !== $filtros['plano']) {
            return false;
        }

        $termo = mb_strtolower(trim($filtros['q']));

        if ($termo === '') {
            return true;
        }

        $digitos = preg_replace('/[^0-9A-Za-z]/', '', $termo) ?? '';
        $texto = mb_strtolower($organizacao['nome'] . ' ' . ($organizacao['nome_fantasia'] ?? ''));

        return str_contains($texto, $termo)
            || ($digitos !== '' && str_contains(mb_strtolower((string) $organizacao['cnpj']), $digitos));
    }

    /**
     * @param array<string, string> $novoAdmin
     * @param array<string, string> $erros
     */
    private function detalhe(string $id, array $novoAdmin, array $erros, int $status = 200): Resposta
    {
        $organizacao = $this->organizacoes->buscar($id);

        if ($organizacao === null) {
            return $this->naoEncontrado();
        }

        $assinatura = $this->assinaturas->daOrganizacao($id);
        $usuarios = $this->usuarios->listar($id);

        return $this->pagina('organizacoes/detalhe', [
            'titulo' => (string) $organizacao['nome'],
            'organizacao' => $organizacao,
            'cadastroCompleto' => DadosCadastraisCliente::completo($organizacao),
            'assinatura' => $assinatura,
            'situacao' => $assinatura === null ? SituacaoAssinatura::semAssinatura() : SituacaoAssinatura::calcular(
                (string) $assinatura['status'],
                $assinatura['teste_ate'],
                $assinatura['inadimplente_desde'],
                new DateTimeImmutable('today'),
            ),
            'faturas' => $this->assinaturas->faturas($id),
            'planos' => $this->assinaturas->planos(),
            'usuarios' => $usuarios,
            'loginsTravados' => $this->atendimento->loginsTravados($usuarios),
            'historico' => $this->organizacoes->historico($id),
            'diasMaximosExtensao' => AtendimentoClienteServico::DIAS_MAXIMOS_EXTENSAO,
            'novoAdmin' => $novoAdmin,
            'erros' => $erros,
        ], $status);
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function formulario(?string $id, array $dados, array $erros, int $status = 200): Resposta
    {
        return $this->pagina('organizacoes/formulario', [
            'titulo' => $id === null ? 'Novo cliente (venda manual)' : 'Editar cadastro do cliente',
            'id' => $id,
            'dados' => $dados,
            'erros' => $erros,
            'planos' => $this->assinaturas->planos(),
            'ufs' => EmpresaServico::UFS,
        ], $status);
    }
}
