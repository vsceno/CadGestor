<?php

declare(strict_types=1);

namespace CadGestor\Http\Controllers;

use CadGestor\Biometria\IdentificadorBiometrico;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Nucleo\Requisicao;
use CadGestor\Nucleo\Resposta;
use CadGestor\Repositorios\LgpdRepositorio;
use CadGestor\Seguranca\Sessao;
use CadGestor\Servicos\BiometriaServico;
use CadGestor\Servicos\ConsentimentoServico;

/**
 * Consentimento LGPD, coleta de biometria e termos de consentimento.
 */
final class LgpdController extends Controlador
{
    private const TAMANHO_MAXIMO_FOTO = 5 * 1024 * 1024;
    private const TIPOS_FOTO = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        Sessao $sessao,
        private readonly LgpdRepositorio $lgpd,
        private readonly ConsentimentoServico $consentimentos,
        private readonly BiometriaServico $biometrias,
        private readonly IdentificadorBiometrico $identificador,
    ) {
        parent::__construct($sessao);
    }

    /**
     * Dados do painel LGPD exibido no detalhe da pessoa.
     *
     * @return array<string, mixed>
     */
    public function painel(string $pessoaId): array
    {
        return [
            'termo' => $this->lgpd->termoVigente(),
            'consentimento' => $this->lgpd->consentimentoAtivo($pessoaId),
            'historicoConsentimentos' => $this->lgpd->consentimentos($pessoaId),
            'biometrias' => $this->lgpd->biometriasAtivas($pessoaId),
            'bloqueioBiometria' => $this->biometrias->motivoBloqueio($pessoaId),
            'sincronizacoesPendentes' => $this->lgpd->sincronizacoesPendentes($pessoaId),
            'parentescos' => ConsentimentoServico::PARENTESCOS,
            'identificadorSimulado' => $this->identificador->nome() === 'simulado',
        ];
    }

    /** @param array<string, string> $parametros */
    public function registrarConsentimento(Requisicao $requisicao, array $parametros): Resposta
    {
        $destino = "/pessoas/{$parametros['id']}#lgpd";

        try {
            $this->consentimentos->registrar($parametros['id'], [
                'canal' => $requisicao->entrada('canal', 'presencial'),
                'aceite_titular' => $requisicao->entrada('aceite_titular'),
                'aceite_responsavel' => $requisicao->entrada('aceite_responsavel'),
                'responsavel_nome' => $requisicao->entrada('responsavel_nome'),
                'responsavel_cpf' => $requisicao->entrada('responsavel_cpf'),
                'responsavel_parentesco' => $requisicao->entrada('responsavel_parentesco'),
            ]);
        } catch (ErroValidacao $erro) {
            return $this->redirecionar($destino, implode(' ', $erro->erros), 'erro');
        }

        return $this->redirecionar($destino, 'Consentimento registrado. A coleta biométrica está liberada.');
    }

    /** @param array<string, string> $parametros */
    public function revogarConsentimento(Requisicao $requisicao, array $parametros): Resposta
    {
        $destino = "/pessoas/{$parametros['id']}#lgpd";

        try {
            $excluidas = $this->consentimentos->revogar($parametros['id']);
        } catch (ErroValidacao $erro) {
            return $this->redirecionar($destino, implode(' ', $erro->erros), 'erro');
        }

        return $this->redirecionar(
            $destino,
            "Consentimento revogado. {$excluidas} biometria(s) apagada(s) e remoção agendada em todas as catracas.",
        );
    }

    /** @param array<string, string> $parametros */
    public function cadastrarBiometria(Requisicao $requisicao, array $parametros): Resposta
    {
        $destino = "/pessoas/{$parametros['id']}#lgpd";
        $tipo = $requisicao->entrada('tipo');
        $dedo = $requisicao->entrada('dedo') === '' ? null : (int) $requisicao->entrada('dedo');

        try {
            $amostra = $tipo === 'facial'
                ? $this->lerFoto($requisicao)
                : $this->lerLeituraDigital($requisicao);

            $this->biometrias->cadastrar($parametros['id'], $tipo, $dedo, $amostra);
        } catch (ErroValidacao $erro) {
            return $this->redirecionar($destino, implode(' ', $erro->erros), 'erro');
        }

        return $this->redirecionar(
            $destino,
            'Biometria cadastrada (template cifrado). A amostra original foi descartada.',
        );
    }

    /** @param array<string, string> $parametros */
    public function excluirBiometria(Requisicao $requisicao, array $parametros): Resposta
    {
        $destino = "/pessoas/{$parametros['id']}#lgpd";

        try {
            $this->biometrias->excluir($parametros['id'], $parametros['biometria']);
        } catch (ErroValidacao $erro) {
            return $this->redirecionar($destino, implode(' ', $erro->erros), 'erro');
        }

        return $this->redirecionar($destino, 'Biometria excluída e remoção agendada nas catracas.');
    }

    /** @param array<string, string> $parametros */
    public function termos(Requisicao $requisicao, array $parametros): Resposta
    {
        return $this->pagina('termos/index', [
            'titulo' => 'Termos de consentimento',
            'termos' => $this->lgpd->termos(),
            'vigente' => $this->lgpd->termoVigente(),
            'erros' => [],
            'dados' => [],
        ]);
    }

    /** @param array<string, string> $parametros */
    public function publicarTermo(Requisicao $requisicao, array $parametros): Resposta
    {
        $dados = ['versao' => $requisicao->entrada('versao'), 'texto' => $requisicao->entrada('texto')];

        try {
            $this->consentimentos->publicarTermo($dados['versao'], $dados['texto']);
        } catch (ErroValidacao $erro) {
            return $this->pagina('termos/index', [
                'titulo' => 'Termos de consentimento',
                'termos' => $this->lgpd->termos(),
                'vigente' => $this->lgpd->termoVigente(),
                'erros' => $erro->erros,
                'dados' => $dados,
            ], 422);
        }

        return $this->redirecionar('/termos', 'Nova versão do termo publicada. Novos aceites usarão esta versão.');
    }

    /**
     * Lê a foto enviada (webcam ou arquivo). O arquivo temporário é apagado em
     * seguida: a imagem só existe em memória até virar template.
     */
    private function lerFoto(Requisicao $requisicao): string
    {
        $arquivo = $requisicao->arquivos['foto'] ?? null;

        if (!is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw ErroValidacao::campo('foto', 'Capture ou selecione a foto do rosto.');
        }

        $caminho = (string) $arquivo['tmp_name'];

        try {
            if (!is_uploaded_file($caminho) || (int) $arquivo['size'] > self::TAMANHO_MAXIMO_FOTO) {
                throw ErroValidacao::campo('foto', 'Foto inválida ou maior que 5 MB.');
            }

            $tipo = (new \finfo(FILEINFO_MIME_TYPE))->file($caminho);

            if (!in_array($tipo, self::TIPOS_FOTO, true)) {
                throw ErroValidacao::campo('foto', 'Formato não aceito: use JPEG, PNG ou WebP.');
            }

            return (string) file_get_contents($caminho);
        } finally {
            if (is_file($caminho)) {
                @unlink($caminho);
            }
        }
    }

    /**
     * Leitura do sensor de digital. Sem leitor físico integrado, o
     * identificador simulado gera uma leitura aleatória (claramente sinalizada
     * na tela). Com um adaptador real, a leitura viria do SDK do fabricante.
     */
    private function lerLeituraDigital(Requisicao $requisicao): string
    {
        if ($this->identificador->nome() !== 'simulado') {
            throw ErroValidacao::campo('amostra', 'Leitura de digital depende do adaptador do fabricante.');
        }

        return 'leitura-simulada:' . bin2hex(random_bytes(64));
    }
}
