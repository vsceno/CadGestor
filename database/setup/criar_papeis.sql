-- =============================================================================
-- CadGestor v2 - papéis do banco (executar UMA vez no servidor, como postgres,
-- antes do criar_banco.sql). Gere senhas fortes e guarde-as só no .env.
--
--   & psql.exe -h 127.0.0.1 -U postgres -v senha_owner='...' -v senha_app='...' `
--       -f database/setup/criar_papeis.sql
-- =============================================================================

\set ON_ERROR_STOP on

CREATE ROLE cadgestor_owner LOGIN PASSWORD :'senha_owner';
CREATE ROLE cadgestor_app LOGIN PASSWORD :'senha_app';
