-- =============================================================================
-- 0007 - Trilha de auditoria e permissões finas do papel da aplicação.
-- =============================================================================

-- Quem fez o quê, quando, de onde. dados_antes/dados_depois guardam só os
-- campos alterados (minimização); templates biométricos nunca entram aqui.
CREATE TABLE auditoria (
    id           uuid         PRIMARY KEY DEFAULT uuidv7(),
    usuario_id   uuid         REFERENCES usuario_sistema (id) ON DELETE SET NULL,
    acao         varchar(30)  NOT NULL,
    tabela       varchar(60),
    registro_id  uuid,
    dados_antes  jsonb,
    dados_depois jsonb,
    data_hora    timestamptz  NOT NULL DEFAULT now(),
    ip           inet,
    CONSTRAINT auditoria_acao_ck CHECK (acao IN (
        'criar', 'alterar', 'excluir', 'anonimizar',
        'login', 'logout', 'login_falhou', 'senha_alterada',
        'consentimento_aceito', 'consentimento_revogado',
        'biometria_cadastrada', 'biometria_excluida',
        'exportar', 'job_manutencao', 'seed'
    ))
);

CREATE INDEX auditoria_data_hora_idx ON auditoria (data_hora DESC);
CREATE INDEX auditoria_registro_idx ON auditoria (tabela, registro_id);
CREATE INDEX auditoria_usuario_idx ON auditoria (usuario_id, data_hora DESC);

-- -----------------------------------------------------------------------------
-- Permissões finas do papel cadgestor_app.
-- Por padrão (setup) ele tem SELECT/INSERT/UPDATE/DELETE em tudo. Aqui
-- retiramos o que não deve fazer: histórico e trilhas são só-acrescentar.
-- Exclusões por retenção (Fase 9) rodam com o papel de manutenção (owner).
-- -----------------------------------------------------------------------------
REVOKE UPDATE, DELETE ON auditoria FROM cadgestor_app;
REVOKE UPDATE, DELETE ON evento_acesso FROM cadgestor_app;
REVOKE DELETE ON vinculo FROM cadgestor_app;
REVOKE DELETE ON pessoa FROM cadgestor_app;
REVOKE DELETE ON consentimento FROM cadgestor_app;
REVOKE DELETE ON biometria FROM cadgestor_app;
REVOKE DELETE ON termo_consentimento FROM cadgestor_app;
REVOKE UPDATE, DELETE ON tentativa_login FROM cadgestor_app;
REVOKE INSERT, UPDATE, DELETE ON migrations FROM cadgestor_app;
