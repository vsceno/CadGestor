-- =============================================================================
-- Seed 01 - Perfis de vínculo padrão.
-- Idempotente: pode rodar várias vezes sem duplicar.
--
-- Base legal: 'consentimento' em todos, até validação do jurídico do cliente
-- (base_legal_validada = false sinaliza a pendência na interface).
-- =============================================================================

INSERT INTO perfil_vinculo (nome, descricao, base_legal, base_legal_validada)
VALUES
    ('Funcionário', 'Empregado da empresa (CLT, estagiário ou temporário).', 'consentimento', false),
    ('Aluno', 'Estudante matriculado. Menores de 18 anos exigem consentimento do responsável.', 'consentimento', false),
    ('Faxineiro', 'Prestador de serviço de limpeza e conservação.', 'consentimento', false),
    ('Pedreiro', 'Prestador de serviço de obras e manutenção predial.', 'consentimento', false)
ON CONFLICT ((lower(nome))) DO NOTHING;
