-- =============================================================================
-- CadGestor - banco usado pelos testes de integração (PHPUnit).
-- Executar UMA vez, como postgres, depois do 01_criar_banco.sql:
--
--   & "C:\laragon\bin\postgresql\postgresql-18.6-3-windows-x64-binaries\bin\psql.exe" `
--       -h 127.0.0.1 -U postgres -f database/setup/02_criar_banco_teste.sql
--
-- Os testes apagam e recriam o schema cadgestor deste banco a cada execução.
-- NUNCA aponte DB_TESTE_BANCO para o banco de desenvolvimento ou produção.
-- =============================================================================

\set ON_ERROR_STOP on

CREATE DATABASE cadgestor_teste
    WITH OWNER = cadgestor_owner
         TEMPLATE = template0
         ENCODING = 'UTF8'
         LOCALE_PROVIDER = icu
         ICU_LOCALE = 'pt-BR';

\connect cadgestor_teste

REVOKE ALL ON DATABASE cadgestor_teste FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE cadgestor_teste TO cadgestor_app;
REVOKE ALL ON SCHEMA public FROM PUBLIC;

ALTER ROLE cadgestor_owner IN DATABASE cadgestor_teste SET search_path = cadgestor;
ALTER ROLE cadgestor_app IN DATABASE cadgestor_teste SET search_path = cadgestor;
ALTER ROLE cadgestor_owner IN DATABASE cadgestor_teste SET timezone = 'UTC';
ALTER ROLE cadgestor_app IN DATABASE cadgestor_teste SET timezone = 'UTC';
