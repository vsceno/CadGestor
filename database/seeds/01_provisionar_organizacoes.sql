-- =============================================================================
-- Seed 01 - Cadastros iniciais de cada organização (perfis padrão e termo
-- MODELO 1.0). Idempotente: organizações já provisionadas não mudam.
--
-- Os perfis e o termo agora pertencem a cada organização (migração 0010); uma
-- organização nova é provisionada pela plataforma no momento do cadastro.
-- =============================================================================

SELECT cg_provisionar_organizacao(id) FROM organizacao;
