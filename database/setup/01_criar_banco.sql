-- =============================================================================
-- CadGestor - criação de papéis, banco e schema (executar UMA vez, como postgres)
--
-- Uso (PowerShell, a partir da raiz do projeto):
--   & "C:\laragon\bin\postgresql\postgresql-18.6-3-windows-x64-binaries\bin\psql.exe" `
--       -h 127.0.0.1 -U postgres `
--       -v senha_owner='SENHA_DO_OWNER' -v senha_app='SENHA_DA_APP' `
--       -f database/setup/01_criar_banco.sql
--
-- Papéis:
--   cadgestor_owner -> dono do schema; usado só para migrações (DDL).
--   cadgestor_app   -> usado pela aplicação; apenas SELECT/INSERT/UPDATE/DELETE.
--                      Restrições extras por tabela (ex.: auditoria somente
--                      INSERT/SELECT) são aplicadas nas migrações da Fase 1.
-- =============================================================================

\set ON_ERROR_STOP on

CREATE ROLE cadgestor_owner LOGIN PASSWORD :'senha_owner';
CREATE ROLE cadgestor_app LOGIN PASSWORD :'senha_app';

CREATE DATABASE cadgestor
    WITH OWNER = cadgestor_owner
         TEMPLATE = template0
         ENCODING = 'UTF8'
         LOCALE_PROVIDER = icu
         ICU_LOCALE = 'pt-BR';

\connect cadgestor

-- Ninguém além dos papéis do sistema conecta neste banco.
REVOKE ALL ON DATABASE cadgestor FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE cadgestor TO cadgestor_app;

-- O schema public não é usado; ninguém cria objetos nele.
REVOKE ALL ON SCHEMA public FROM PUBLIC;

CREATE SCHEMA cadgestor AUTHORIZATION cadgestor_owner;
GRANT USAGE ON SCHEMA cadgestor TO cadgestor_app;

-- Tudo que o owner criar no schema já nasce com as permissões da aplicação.
ALTER DEFAULT PRIVILEGES FOR ROLE cadgestor_owner IN SCHEMA cadgestor
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO cadgestor_app;
ALTER DEFAULT PRIVILEGES FOR ROLE cadgestor_owner IN SCHEMA cadgestor
    GRANT USAGE, SELECT ON SEQUENCES TO cadgestor_app;
ALTER DEFAULT PRIVILEGES FOR ROLE cadgestor_owner IN SCHEMA cadgestor
    GRANT EXECUTE ON FUNCTIONS TO cadgestor_app;

ALTER ROLE cadgestor_owner IN DATABASE cadgestor SET search_path = cadgestor;
ALTER ROLE cadgestor_app IN DATABASE cadgestor SET search_path = cadgestor;
ALTER ROLE cadgestor_owner IN DATABASE cadgestor SET timezone = 'UTC';
ALTER ROLE cadgestor_app IN DATABASE cadgestor SET timezone = 'UTC';
