-- =============================================================================
-- 0009 - Suporte à política de retenção (Fase 9).
--
-- Ao anonimizar um titular, os dados do responsável legal no consentimento
-- (nome e CPF de terceiro) também são apagados. O registro do consentimento
-- (versão, hash, data) permanece como prova de que o tratamento foi lícito.
-- =============================================================================

ALTER TABLE consentimento ADD COLUMN anonimizado_em timestamptz;

ALTER TABLE consentimento DROP CONSTRAINT consentimento_responsavel_ck;
ALTER TABLE consentimento ADD CONSTRAINT consentimento_responsavel_ck CHECK (
    NOT titular_menor
    OR anonimizado_em IS NOT NULL
    OR (
        responsavel_nome IS NOT NULL
        AND responsavel_cpf IS NOT NULL
        AND responsavel_parentesco IS NOT NULL
    )
);

-- Busca de candidatos à retenção e de vínculos vencidos.
CREATE INDEX vinculo_pessoa_status_idx ON vinculo (pessoa_id, status);
CREATE INDEX pessoa_nao_anonimizada_idx ON pessoa (created_at) WHERE anonimizada_em IS NULL;

-- Limpeza técnica periódica.
CREATE INDEX fila_sincronizacao_concluida_idx ON fila_sincronizacao (processado_em) WHERE status = 'concluido';
CREATE INDEX tentativa_login_data_idx ON tentativa_login (data_hora);
