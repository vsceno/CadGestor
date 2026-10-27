<?php

declare(strict_types=1);

namespace CadGestor\Servicos;

use CadGestor\Banco\Transacao;
use CadGestor\Biometria\IdentificadorBiometrico;
use CadGestor\Biometria\TipoBiometria;
use CadGestor\Dominio\Idade;
use CadGestor\Nucleo\ErroValidacao;
use CadGestor\Repositorios\LgpdRepositorio;
use CadGestor\Repositorios\PessoaRepositorio;
use CadGestor\Seguranca\CifradorBiometria;
use DateTimeImmutable;
use PDO;

/**
 * Cadastro e exclusão de templates biométricos.
 *
 * Fluxo de coleta: amostra (foto/leitura) -> template (identificador) ->
 * cifra -> banco. A amostra bruta NUNCA é gravada: é apagada da memória logo
 * após gerar o template.
 */
final class BiometriaServico
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LgpdRepositorio $lgpd,
        private readonly PessoaRepositorio $pessoas,
        private readonly IdentificadorBiometrico $identificador,
        private readonly CifradorBiometria $cifrador,
        private readonly Auditoria $auditoria,
        private readonly SincronizacaoServico $sincronizacao,
    ) {
    }

    /**
     * Verifica se a pessoa pode ter biometria coletada agora.
     *
     * @return string|null motivo do bloqueio, ou null se liberado
     */
    public function motivoBloqueio(string $pessoaId): ?string
    {
        $pessoa = $this->pessoas->buscar($pessoaId);

        if ($pessoa === null || $pessoa['anonimizada_em'] !== null || $pessoa['ativo'] !== true) {
            return 'Pessoa inativa.';
        }

        $faixa = Idade::classificar($pessoa['data_nascimento'], new DateTimeImmutable('now'));

        if ($faixa === 'desconhecida') {
            return 'Idade não verificável: informe a data de nascimento.';
        }

        $consentimento = $this->lgpd->consentimentoAtivo($pessoaId);

        if ($consentimento === null) {
            return 'Consentimento pendente: registre o aceite do termo antes da coleta.';
        }

        if ($faixa === 'menor' && $consentimento['titular_menor'] !== true) {
            return 'Titular menor de idade sem consentimento do responsável: revogue e registre novamente.';
        }

        return null;
    }

    /**
     * @param string $amostra imagem facial ou leitura do sensor (descartada após o uso)
     */
    public function cadastrar(
        string $pessoaId,
        string $tipo,
        ?int $dedo,
        #[\SensitiveParameter] string &$amostra,
    ): string {
        $bloqueio = $this->motivoBloqueio($pessoaId);

        if ($bloqueio !== null) {
            $this->descartar($amostra);
            throw ErroValidacao::campo('biometria', $bloqueio);
        }

        $tipoBiometria = TipoBiometria::tryFrom($tipo)
            ?? throw ErroValidacao::campo('tipo', 'Tipo de biometria inválido.');

        if ($tipoBiometria === TipoBiometria::Digital && ($dedo === null || $dedo < 1 || $dedo > 10)) {
            $this->descartar($amostra);
            throw ErroValidacao::campo('dedo', 'Selecione o dedo.');
        }

        $dedo = $tipoBiometria === TipoBiometria::Facial ? null : $dedo;

        if ($amostra === '') {
            throw ErroValidacao::campo('amostra', 'Nenhuma amostra capturada.');
        }

        $template = $this->identificador->extrairTemplate($tipoBiometria, $amostra);
        $this->descartar($amostra);

        $cifra = $this->cifrador->cifrar($template, CifradorBiometria::dadosAssociados($pessoaId, $tipo, $dedo));
        $hash = $this->cifrador->hashIntegridade($template);
        sodium_memzero($template);

        $consentimento = $this->lgpd->consentimentoAtivo($pessoaId);

        $gravar = function () use ($pessoaId, $tipo, $dedo, $cifra, $hash, $consentimento): string {
            $substituidas = $this->substituirExistente($pessoaId, $tipo, $dedo);
            $id = $this->lgpd->inserirBiometria(
                $pessoaId,
                (string) $consentimento['id'],
                $tipo,
                $dedo,
                $cifra['cifrado'],
                $cifra['nonce'],
                $cifra['versao'],
                $hash,
            );
            $envios = $this->sincronizacao->enviarBiometria($id);

            $this->auditoria->registrar('biometria_cadastrada', 'biometria', $id, null, [
                'pessoa_id' => $pessoaId,
                'tipo' => $tipo,
                'dedo' => $dedo,
                'substituiu' => $substituidas,
                'envios_agendados' => $envios,
            ]);

            return $id;
        };

        return Transacao::executar($this->pdo, $gravar);
    }

    /** Exclusão a pedido do titular (sem revogar o consentimento). */
    public function excluir(string $pessoaId, string $biometriaId): void
    {
        Transacao::executar($this->pdo, function () use ($pessoaId, $biometriaId): void {
            $excluidas = $this->lgpd->excluirBiometrias($pessoaId, 'solicitacao_titular', $biometriaId);

            if ($excluidas === []) {
                throw ErroValidacao::campo('biometria', 'Biometria não encontrada.');
            }

            // O dispositivo remove a pessoa inteira; as demais biometrias são reenviadas.
            $this->sincronizacao->removerDeTodas($pessoaId);

            foreach ($this->lgpd->biometriasAtivas($pessoaId) as $restante) {
                $this->sincronizacao->enviarBiometria((string) $restante['id']);
            }

            $this->auditoria->registrar('biometria_excluida', 'biometria', $biometriaId, null, [
                'motivo' => 'solicitacao_titular',
            ]);
        });
    }

    /** @return int quantas biometrias anteriores foram substituídas */
    private function substituirExistente(string $pessoaId, string $tipo, ?int $dedo): int
    {
        $total = 0;

        foreach ($this->lgpd->biometriasAtivas($pessoaId) as $existente) {
            $dedoExistente = $existente['dedo'] === null ? null : (int) $existente['dedo'];

            if ($existente['tipo'] === $tipo && $dedoExistente === $dedo) {
                $total += count($this->lgpd->excluirBiometrias($pessoaId, 'substituicao', (string) $existente['id']));
            }
        }

        return $total;
    }

    private function descartar(string &$amostra): void
    {
        if ($amostra !== '') {
            sodium_memzero($amostra);
        }

        $amostra = '';
    }
}
