<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\AssinaturaRepositorio;
use CadGestor\Seguranca\LimitadorTentativas;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\CadastroClienteServico;

/**
 * Páginas públicas da "loja": planos e cadastro self-service, confirmação de
 * e-mail e documentos jurídicos (MODELOS).
 */
final class CadastroController extends Controlador
{
    private const CAMPOS = ['razao_social', 'cnpj', 'telefone', 'responsavel_nome', 'email', 'plano'];

    public function __construct(
        Sessao $sessao,
        private readonly AssinaturaRepositorio $assinaturas,
        private readonly CadastroClienteServico $servico,
        private readonly LimitadorTentativas $limitador,
    ) {
        parent::__construct($sessao);
    }

    /** @param array<string, string> $parametros */
    public function formulario(Requisicao $requisicao, array $parametros): Resposta
    {
        $plano = $requisicao->consulta('plano', 'profissional');

        return $this->pagina('cadastro/formulario', $this->dadosPagina(['plano' => $plano], []));
    }

    /** @param array<string, string> $parametros */
    public function cadastrar(Requisicao $requisicao, array $parametros): Resposta
    {
        $entrada = array_combine(
            self::CAMPOS,
            array_map(static fn (string $c): string => $requisicao->entrada($c), self::CAMPOS),
        );

        if ($this->limitador->excedeuCadastros($requisicao->ip())) {
            return $this->pagina('cadastro/formulario', $this->dadosPagina($entrada, [
                'geral' => 'Muitos cadastros a partir desta rede. Tente novamente mais tarde.',
            ]), 429);
        }

        $this->limitador->registrarCadastro($requisicao->ip());

        try {
            $this->servico->cadastrar($entrada + [
                'senha' => (string) ($requisicao->corpo['senha'] ?? ''),
                'confirmacao' => (string) ($requisicao->corpo['confirmacao'] ?? ''),
                'aceite' => $requisicao->entrada('aceite'),
            ], $requisicao->ip());
        } catch (ErroValidacao $erro) {
            return $this->pagina('cadastro/formulario', $this->dadosPagina($entrada, $erro->erros), 422);
        }

        return $this->pagina('cadastro/enviado', [
            'titulo' => 'Confirme seu e-mail',
            'dias' => CadastroClienteServico::DIAS_TESTE,
        ]);
    }

    /** @param array<string, string> $parametros */
    public function confirmarEmail(Requisicao $requisicao, array $parametros): Resposta
    {
        if (!$this->servico->confirmarEmail($requisicao->consulta('token'))) {
            return $this->pagina('cadastro/confirmacao', ['titulo' => 'Link inválido', 'confirmado' => false], 400);
        }

        return $this->pagina('cadastro/confirmacao', ['titulo' => 'E-mail confirmado', 'confirmado' => true]);
    }

    /** @param array<string, string> $parametros */
    public function documento(Requisicao $requisicao, array $parametros): Resposta
    {
        $documentos = [
            '/termos-de-uso' => ['juridico/termos-de-uso', 'Termos de Uso'],
            '/privacidade' => ['juridico/privacidade', 'Política de Privacidade'],
            '/contrato-dados' => ['juridico/contrato-dados', 'Contrato de Tratamento de Dados'],
        ];
        [$view, $titulo] = $documentos[$requisicao->caminho];

        return $this->pagina($view, ['titulo' => $titulo, 'versao' => CadastroClienteServico::VERSAO_TERMOS]);
    }

    /**
     * @param array<string, string> $dados
     * @param array<string, string> $erros
     * @return array<string, mixed>
     */
    private function dadosPagina(array $dados, array $erros): array
    {
        return [
            'titulo' => 'Contratar o CadGestor',
            'dados' => $dados,
            'erros' => $erros,
            'planos' => $this->assinaturas->planos(true),
            'diasTeste' => CadastroClienteServico::DIAS_TESTE,
        ];
    }
}
