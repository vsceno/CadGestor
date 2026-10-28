<?php
/**
 * Painel LGPD da pessoa: consentimento e biometrias.
 *
 * @var array<string, mixed> $pessoa
 * @var array<string, mixed>|null $termo
 * @var array<string, mixed>|null $consentimento
 * @var list<array<string, mixed>> $historicoConsentimentos
 * @var list<array<string, mixed>> $biometrias
 * @var string|null $bloqueioBiometria
 * @var int $sincronizacoesPendentes
 * @var array<string, string> $parentescos
 * @var bool $identificadorSimulado
 */

use CadGestor\Dominio\Idade;

$id = (string) $pessoa['id'];
$faixa = Idade::classificar($pessoa['data_nascimento'], new DateTimeImmutable('now'));
$ativa = $pessoa['ativo'] && $pessoa['anonimizada_em'] === null;
$dedos = [
    1 => 'Polegar direito', 2 => 'Indicador direito', 3 => 'Médio direito', 4 => 'Anelar direito',
    5 => 'Mínimo direito', 6 => 'Polegar esquerdo', 7 => 'Indicador esquerdo', 8 => 'Médio esquerdo',
    9 => 'Anelar esquerdo', 10 => 'Mínimo esquerdo',
];
?>
<section class="cartao" id="lgpd">
    <h2>Consentimento LGPD</h2>
    <p class="dica">Finalidade específica: controle de acesso físico por biometria.</p>

    <?php if ($consentimento !== null) : ?>
        <div class="alerta alerta-sucesso">Consentimento ativo.</div>
        <dl class="dados">
            <dt>Termo</dt><dd>versão <?= e($consentimento['versao_termo']) ?>
                <small class="subtitulo">(hash <?= e(substr((string) $consentimento['hash_termo'], 0, 12)) ?>…)</small></dd>
            <dt>Aceito em</dt><dd><?= e(formatar_data_hora($consentimento['aceito_em'], true)) ?> · IP <?= e($consentimento['ip']) ?>
                · canal <?= e($consentimento['canal']) ?></dd>
            <dt>Registrado por</dt><dd><?= e($consentimento['registrado_por_nome'] ?? '—') ?></dd>
            <?php if ($consentimento['titular_menor']) : ?>
                <dt>Responsável legal</dt>
                <dd><?= e($consentimento['responsavel_nome']) ?> (<?= e($parentescos[$consentimento['responsavel_parentesco']] ?? '') ?>)
                    · CPF <?= e(mascarar_cpf($consentimento['responsavel_cpf'])) ?></dd>
            <?php endif; ?>
        </dl>
        <?php if ($ativa) : ?>
            <form method="post" action="/pessoas/<?= e($id) ?>/consentimento/revogar"
                  data-confirmar="Revogar o consentimento? Todas as biometrias serão APAGADAS e removidas das catracas. Esta ação não pode ser desfeita.">
                <?= csrf_campo() ?>
                <button type="submit" class="botao botao-perigo">Revogar consentimento</button>
            </form>
        <?php endif; ?>
    <?php elseif (!$ativa) : ?>
        <p class="vazio">Pessoa inativa.</p>
    <?php elseif ($termo === null) : ?>
        <div class="alerta alerta-erro">Nenhum termo publicado. O administrador deve publicar o termo em "Termos LGPD".</div>
    <?php elseif ($faixa === 'desconhecida') : ?>
        <div class="alerta alerta-aviso">
            Idade não verificável. Informe a data de nascimento no cadastro antes de coletar o consentimento:
            a coleta biométrica fica bloqueada até lá.
        </div>
    <?php else : ?>
        <div class="alerta alerta-aviso">Consentimento pendente: a coleta biométrica está bloqueada.</div>
        <h3>Termo vigente (versão <?= e($termo['versao']) ?>)</h3>
        <div class="texto-termo" tabindex="0"><?= e($termo['texto']) ?></div>

        <form method="post" action="/pessoas/<?= e($id) ?>/consentimento" class="formulario">
            <?= csrf_campo() ?>
            <label for="canal">Canal</label>
            <select id="canal" name="canal">
                <option value="presencial">Presencial (assinatura/aceite no balcão)</option>
                <option value="digital">Digital</option>
            </select>

            <?php if ($faixa === 'menor') : ?>
                <fieldset>
                    <legend>Titular menor de 18 anos: consentimento do responsável legal</legend>
                    <div class="grade">
                        <div>
                            <label for="responsavel_nome">Nome completo do responsável</label>
                            <input type="text" id="responsavel_nome" name="responsavel_nome" required maxlength="150">
                        </div>
                        <div>
                            <label for="responsavel_cpf">CPF do responsável</label>
                            <input type="text" id="responsavel_cpf" name="responsavel_cpf" data-mascara="cpf" required maxlength="14">
                        </div>
                        <div>
                            <label for="responsavel_parentesco">Vínculo com o titular</label>
                            <select id="responsavel_parentesco" name="responsavel_parentesco" required>
                                <option value="">Selecione</option>
                                <?php foreach ($parentescos as $valor => $rotulo) : ?>
                                    <option value="<?= e($valor) ?>"><?= e($rotulo) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <label class="opcao">
                        <input type="checkbox" name="aceite_responsavel" value="1" required>
                        O responsável legal leu o termo e consente com o tratamento da biometria do menor.
                    </label>
                </fieldset>
            <?php else : ?>
                <label class="opcao">
                    <input type="checkbox" name="aceite_titular" value="1" required>
                    O titular leu o termo e consente com o tratamento da sua biometria para esta finalidade.
                </label>
            <?php endif; ?>
            <p class="dica">Serão registrados: versão e hash do termo, data/hora, IP desta estação e o operador.</p>
            <div class="acoes"><button type="submit" class="botao botao-primario">Registrar consentimento</button></div>
        </form>
    <?php endif; ?>

    <?php if (count($historicoConsentimentos) > ($consentimento === null ? 0 : 1)) : ?>
        <details>
            <summary>Histórico de consentimentos</summary>
            <table class="tabela tabela-compacta">
                <thead><tr><th>Versão</th><th>Aceito em</th><th>Revogado em</th><th>Menor</th></tr></thead>
                <tbody>
                <?php foreach ($historicoConsentimentos as $h) : ?>
                    <tr>
                        <td><?= e($h['versao_termo']) ?></td>
                        <td><?= e(formatar_data_hora($h['aceito_em'])) ?></td>
                        <td><?= e(formatar_data_hora($h['revogado_em']) ?: '—') ?></td>
                        <td><?= $h['titular_menor'] ? 'Sim' : 'Não' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
    <?php endif; ?>
</section>

<section class="cartao" id="biometria">
    <h2>Biometria</h2>
    <p class="dica">
        Somente o <strong>template cifrado</strong> é armazenado (XChaCha20-Poly1305, chave fora do banco).
        A imagem da digital nunca é gravada; a foto do rosto é usada para gerar o template e descartada.
        <?php if ($sincronizacoesPendentes > 0) : ?>
            <br><span class="selo selo-aviso"><?= e($sincronizacoesPendentes) ?> sincronização(ões) pendente(s) com catracas</span>
        <?php endif; ?>
    </p>

    <table class="tabela">
        <thead><tr><th>Tipo</th><th>Dedo</th><th>Cadastrada em</th><th>Chave</th><th></th></tr></thead>
        <tbody>
        <?php if ($biometrias === []) : ?>
            <tr><td colspan="5" class="vazio">Nenhuma biometria cadastrada.</td></tr>
        <?php endif; ?>
        <?php foreach ($biometrias as $b) : ?>
            <tr>
                <td><?= e($b['tipo'] === 'facial' ? 'Facial' : 'Digital') ?></td>
                <td><?= e($b['dedo'] ? $dedos[(int) $b['dedo']] : '—') ?></td>
                <td><?= e(formatar_data_hora($b['created_at'])) ?></td>
                <td>v<?= e($b['versao_chave']) ?></td>
                <td>
                    <form method="post" action="/pessoas/<?= e($id) ?>/biometrias/<?= e($b['id']) ?>/excluir" class="form-inline"
                          data-confirmar="Excluir esta biometria? O template será apagado e removido das catracas.">
                        <?= csrf_campo() ?>
                        <button type="submit" class="botao botao-pequeno botao-perigo">Excluir</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($bloqueioBiometria !== null) : ?>
        <div class="alerta alerta-aviso" role="status">Coleta bloqueada: <?= e($bloqueioBiometria) ?></div>
    <?php else : ?>
        <?php if ($identificadorSimulado) : ?>
            <div class="alerta alerta-info">
                Modo SIMULADO: não há leitor físico integrado. A digital é uma leitura simulada e a foto gera um
                template de teste. Em produção, use o adaptador do fabricante da catraca.
            </div>
        <?php endif; ?>
        <div class="grade">
            <form method="post" action="/pessoas/<?= e($id) ?>/biometrias" class="formulario">
                <?= csrf_campo() ?>
                <input type="hidden" name="tipo" value="digital">
                <h3>Digital</h3>
                <label for="dedo">Dedo</label>
                <select id="dedo" name="dedo" required>
                    <?php foreach ($dedos as $numero => $nome) : ?>
                        <option value="<?= $numero ?>"<?= selecionado($numero === 2) ?>><?= e($nome) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="acoes"><button type="submit" class="botao botao-primario">Capturar digital<?= $identificadorSimulado ? ' (simulado)' : '' ?></button></div>
            </form>

            <form method="post" action="/pessoas/<?= e($id) ?>/biometrias" class="formulario captura" enctype="multipart/form-data"
                  data-captura-facial>
                <?= csrf_campo() ?>
                <input type="hidden" name="tipo" value="facial">
                <h3>Facial</h3>
                <video data-video autoplay playsinline muted hidden></video>
                <canvas data-canvas hidden></canvas>
                <div class="acoes">
                    <button type="button" class="botao" data-abrir-camera>Abrir câmera</button>
                    <button type="button" class="botao" data-tirar-foto hidden>Tirar foto</button>
                </div>
                <label for="foto">ou selecione uma imagem (JPEG/PNG/WebP, até 5 MB)</label>
                <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp" required>
                <div class="acoes"><button type="submit" class="botao botao-primario">Cadastrar facial</button></div>
            </form>
        </div>
    <?php endif; ?>
</section>
