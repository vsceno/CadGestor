-- =============================================================================
-- Seed 02 - Termo de consentimento MODELO (versão 1.0).
-- Idempotente. O texto é um MODELO e DEVE ser revisado pelo jurídico do cliente;
-- uma versão revisada deve ser publicada pela tela "Termos LGPD" (nova versão).
-- =============================================================================

INSERT INTO termo_consentimento (versao, finalidade, texto, hash_texto)
SELECT '1.0', 'controle_acesso_biometrico', t.texto, encode(sha256(convert_to(t.texto, 'UTF8')), 'hex')
  FROM (SELECT
'TERMO DE CONSENTIMENTO PARA TRATAMENTO DE DADOS BIOMÉTRICOS (MODELO - REVISAR COM O JURÍDICO)

1. Finalidade específica: os dados biométricos (template de impressão digital e/ou template facial) serão tratados exclusivamente para identificar o(a) titular e liberar ou bloquear a passagem nas catracas de controle de acesso físico das empresas com as quais ele(a) possui vínculo.

2. Dados tratados: apenas o template biométrico (representação matemática), armazenado de forma cifrada. A imagem da digital não é armazenada. A foto facial, quando capturada, é usada somente para gerar o template e descartada em seguida.

3. Compartilhamento: os templates são enviados apenas aos dispositivos (catracas) das empresas com vínculo ativo, e a nenhum outro terceiro.

4. Retenção: os dados biométricos são excluídos em até 30 dias após o término do vínculo, ou imediatamente após a revogação deste consentimento.

5. Direitos do titular: confirmar a existência do tratamento, acessar, corrigir, solicitar a exclusão e revogar este consentimento a qualquer momento, sem custo, junto à administração. A revogação não afeta a legalidade do tratamento já realizado; após ela, o acesso deverá ocorrer por meio alternativo oferecido pela empresa.

6. Menores de 18 anos: o consentimento deve ser dado por pelo menos um dos pais ou pelo responsável legal.

Declaro que li e compreendi este termo e consinto com o tratamento dos meus dados biométricos para a finalidade descrita.' AS texto) t
ON CONFLICT (finalidade, versao) DO NOTHING;
