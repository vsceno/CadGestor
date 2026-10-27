-- =============================================================================
-- 0006 - Eventos de acesso, alertas e fila de sincronização com dispositivos.
-- =============================================================================

-- Cada tentativa de passagem na catraca. Registro imutável (sem UPDATE/DELETE
-- para a aplicação; ver 0007). Campos de referência são opcionais porque um
-- evento negado pode não ter pessoa identificada.
CREATE TABLE evento_acesso (
    id                 uuid         PRIMARY KEY DEFAULT uuidv7(),
    catraca_id         uuid         REFERENCES catraca (id) ON DELETE RESTRICT,
    empresa_id         uuid         REFERENCES empresa (id) ON DELETE RESTRICT,
    pessoa_id          uuid         REFERENCES pessoa (id) ON DELETE RESTRICT,
    vinculo_id         uuid         REFERENCES vinculo (id) ON DELETE RESTRICT,
    perfil_id          uuid         REFERENCES perfil_vinculo (id) ON DELETE RESTRICT,
    data_hora          timestamptz  NOT NULL DEFAULT now(),
    tipo_identificacao varchar(10)  NOT NULL,
    resultado          varchar(10)  NOT NULL,
    motivo_negacao     varchar(40),
    -- Pontuação de confiança informada pelo identificador (0 a 1), se houver.
    score              numeric(5, 4),
    -- Evento registrado pela própria catraca enquanto estava sem servidor.
    offline            boolean      NOT NULL DEFAULT false,
    created_at         timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT evento_acesso_tipo_ck CHECK (tipo_identificacao IN ('digital', 'facial')),
    CONSTRAINT evento_acesso_resultado_ck CHECK (resultado IN ('liberado', 'negado')),
    CONSTRAINT evento_acesso_motivo_ck CHECK (
        (resultado = 'liberado' AND motivo_negacao IS NULL)
        OR (resultado = 'negado' AND motivo_negacao IN (
            'catraca_inexistente', 'catraca_inativa',
            'pessoa_nao_identificada', 'pessoa_inativa',
            'sem_vinculo_ativo', 'perfil_nao_permitido',
            'fora_do_horario', 'biometria_nao_confere',
            'falha_sistema'
        ))
    ),
    CONSTRAINT evento_acesso_score_ck CHECK (score IS NULL OR score BETWEEN 0 AND 1)
);

CREATE INDEX evento_acesso_data_hora_idx ON evento_acesso (data_hora DESC);
CREATE INDEX evento_acesso_catraca_idx ON evento_acesso (catraca_id, data_hora DESC);
CREATE INDEX evento_acesso_pessoa_idx ON evento_acesso (pessoa_id, data_hora DESC);
CREATE INDEX evento_acesso_empresa_idx ON evento_acesso (empresa_id, data_hora DESC);
CREATE INDEX evento_acesso_perfil_idx ON evento_acesso (perfil_id, data_hora DESC);

-- Alertas para a equipe de segurança (negações, falhas de dispositivo...).
CREATE TABLE alerta (
    id            uuid         PRIMARY KEY DEFAULT uuidv7(),
    tipo          varchar(40)  NOT NULL,
    descricao     varchar(500) NOT NULL,
    catraca_id    uuid         REFERENCES catraca (id) ON DELETE RESTRICT,
    pessoa_id     uuid         REFERENCES pessoa (id) ON DELETE RESTRICT,
    evento_id     uuid         REFERENCES evento_acesso (id) ON DELETE RESTRICT,
    data_hora     timestamptz  NOT NULL DEFAULT now(),
    tratado_por   uuid         REFERENCES usuario_sistema (id) ON DELETE SET NULL,
    tratado_em    timestamptz,
    observacao    varchar(500),
    created_at    timestamptz  NOT NULL DEFAULT now(),
    updated_at    timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT alerta_tipo_ck CHECK (tipo IN (
        'acesso_negado', 'tentativas_repetidas', 'dispositivo_offline',
        'dispositivo_desconhecido', 'falha_sincronizacao', 'emergencia'
    )),
    -- tratado_por pode virar NULL se o usuário for removido; tratado_em fica.
    CONSTRAINT alerta_tratamento_ck CHECK (tratado_por IS NULL OR tratado_em IS NOT NULL)
);

CREATE INDEX alerta_pendente_idx ON alerta (data_hora DESC) WHERE tratado_em IS NULL;
CREATE INDEX alerta_catraca_idx ON alerta (catraca_id, data_hora DESC);

CREATE TRIGGER alerta_updated_at
    BEFORE UPDATE ON alerta
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

-- Fila de envio/remoção de templates nos dispositivos. Ex.: na revogação do
-- consentimento, entra uma operação 'remover' para cada catraca da pessoa.
CREATE TABLE fila_sincronizacao (
    id             uuid         PRIMARY KEY DEFAULT uuidv7(),
    catraca_id     uuid         NOT NULL REFERENCES catraca (id) ON DELETE CASCADE,
    pessoa_id      uuid         NOT NULL REFERENCES pessoa (id) ON DELETE RESTRICT,
    biometria_id   uuid         REFERENCES biometria (id) ON DELETE SET NULL,
    operacao       varchar(10)  NOT NULL,
    status         varchar(15)  NOT NULL DEFAULT 'pendente',
    tentativas     smallint     NOT NULL DEFAULT 0,
    -- Código técnico do erro, sem dados pessoais.
    ultimo_erro    varchar(255),
    agendado_para  timestamptz  NOT NULL DEFAULT now(),
    processado_em  timestamptz,
    created_at     timestamptz  NOT NULL DEFAULT now(),
    updated_at     timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT fila_sincronizacao_operacao_ck CHECK (operacao IN ('cadastrar', 'remover')),
    CONSTRAINT fila_sincronizacao_status_ck CHECK (status IN ('pendente', 'processando', 'concluido', 'falhou')),
    CONSTRAINT fila_sincronizacao_tentativas_ck CHECK (tentativas >= 0)
);

CREATE INDEX fila_sincronizacao_pendente_idx ON fila_sincronizacao (agendado_para)
    WHERE status IN ('pendente', 'falhou');
CREATE INDEX fila_sincronizacao_catraca_idx ON fila_sincronizacao (catraca_id, status);

CREATE TRIGGER fila_sincronizacao_updated_at
    BEFORE UPDATE ON fila_sincronizacao
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();
