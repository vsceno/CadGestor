-- =============================================================================
-- 0008 - Corrige CHECKs que aceitavam NULL indevidamente.
--
-- No PostgreSQL um CHECK só rejeita quando a expressão é FALSE; se ela der
-- NULL (desconhecido), a linha é aceita. "motivo IN (...)" com motivo NULL
-- resulta em NULL, então um evento negado sem motivo passava. O mesmo valia
-- para biometria digital sem dedo. As expressões abaixo usam IS NOT NULL
-- explicitamente para nunca resultar em NULL.
-- =============================================================================

ALTER TABLE evento_acesso DROP CONSTRAINT evento_acesso_motivo_ck;
ALTER TABLE evento_acesso ADD CONSTRAINT evento_acesso_motivo_ck CHECK (
    (resultado = 'liberado' AND motivo_negacao IS NULL)
    OR (resultado = 'negado' AND motivo_negacao IS NOT NULL AND motivo_negacao IN (
        'catraca_inexistente', 'catraca_inativa',
        'pessoa_nao_identificada', 'pessoa_inativa',
        'sem_vinculo_ativo', 'perfil_nao_permitido',
        'fora_do_horario', 'biometria_nao_confere',
        'falha_sistema'
    ))
);

ALTER TABLE biometria DROP CONSTRAINT biometria_dedo_ck;
ALTER TABLE biometria ADD CONSTRAINT biometria_dedo_ck CHECK (
    (tipo = 'digital' AND dedo IS NOT NULL AND dedo BETWEEN 1 AND 10)
    OR (tipo = 'facial' AND dedo IS NULL)
);
