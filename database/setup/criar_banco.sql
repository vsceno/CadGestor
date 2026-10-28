-- =============================================================================
-- CadGestor v2 - criação do banco e do schema (executar UMA vez por banco, como postgres)
--
-- Uso (PowerShell, na raiz do projeto), para o banco principal e o de testes:
--   & psql.exe -h 127.0.0.1 -U postgres -v banco=cadgestor_v2 -f database/setup/criar_banco.sql
--   & psql.exe -h 127.0.0.1 -U postgres -v banco=cadgestor_v2_teste -f database/setup/criar_banco.sql
--
-- Papéis (criados pelo database/setup/criar_papeis.sql, se ainda não existirem):
--   cadgestor_owner -> dono do schema; só migrações e jobs de manutenção (DDL).
--   cadgestor_app   -> aplicação web e API; só SELECT/INSERT/UPDATE/DELETE,
--                      e mesmo isso limitado pelo RLS e pelas restrições das migrações.
-- Se a aplicação for comprometida, o invasor não consegue apagar tabelas,
-- mudar a estrutura nem ler dados de outro cliente.
-- =============================================================================

\set ON_ERROR_STOP on

CREATE DATABASE :"banco"
    WITH OWNER = cadgestor_owner
         TEMPLATE = template0
         ENCODING = 'UTF8'
         LOCALE_PROVIDER = icu
         ICU_LOCALE = 'pt-BR';

\connect :"banco"

-- Ninguém além dos papéis do sistema conecta neste banco.
REVOKE ALL ON DATABASE :"banco" FROM PUBLIC;
GRANT CONNECT, TEMPORARY ON DATABASE :"banco" TO cadgestor_app;

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

ALTER ROLE cadgestor_owner IN DATABASE :"banco" SET search_path = cadgestor;
ALTER ROLE cadgestor_app IN DATABASE :"banco" SET search_path = cadgestor;
ALTER ROLE cadgestor_owner IN DATABASE :"banco" SET timezone = 'UTC';
ALTER ROLE cadgestor_app IN DATABASE :"banco" SET timezone = 'UTC';
