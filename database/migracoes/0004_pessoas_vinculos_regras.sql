-- =============================================================================
-- 0004 - Pessoas, vínculos com empresas e regras de acesso.
-- =============================================================================

-- Pessoa física. O mesmo CPF é uma única pessoa, que pode ter vários vínculos.
-- Após o prazo de retenção, a pessoa é ANONIMIZADA (não excluída), para que
-- os eventos de acesso continuem íntegros sem identificar o titular.
CREATE TABLE pessoa (
    id                 uuid         PRIMARY KEY DEFAULT uuidv7(),
    nome               varchar(150) NOT NULL,
    cpf                char(11),
    documento_tipo     varchar(20),
    documento_numero   varchar(30),
    documento_orgao    varchar(20),
    documento_uf       char(2),
    -- Necessária para a regra de menores. Sem ela, a coleta biométrica é bloqueada.
    data_nascimento    date,
    email              varchar(254),
    telefone           varchar(20),
    ativo              boolean      NOT NULL DEFAULT true,
    anonimizada_em     timestamptz,
    created_at         timestamptz  NOT NULL DEFAULT now(),
    updated_at         timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT pessoa_cpf_uk UNIQUE (cpf),
    CONSTRAINT pessoa_cpf_ck CHECK (cpf IS NULL OR cpf_valido(cpf)),
    -- CPF só pode faltar em registro anonimizado.
    CONSTRAINT pessoa_cpf_obrigatorio_ck CHECK ((cpf IS NULL) = (anonimizada_em IS NOT NULL)),
    CONSTRAINT pessoa_nome_ck CHECK (btrim(nome) <> ''),
    CONSTRAINT pessoa_documento_tipo_ck CHECK (
        documento_tipo IS NULL OR documento_tipo IN ('rg', 'cnh', 'passaporte', 'rnm', 'outro')
    ),
    CONSTRAINT pessoa_documento_ck CHECK ((documento_tipo IS NULL) = (documento_numero IS NULL)),
    CONSTRAINT pessoa_data_nascimento_ck CHECK (data_nascimento IS NULL OR data_nascimento > DATE '1900-01-01'),
    CONSTRAINT pessoa_email_ck CHECK (email IS NULL OR email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
    CONSTRAINT pessoa_telefone_ck CHECK (telefone IS NULL OR telefone ~ '^[0-9]{10,13}$')
);

CREATE TRIGGER pessoa_updated_at
    BEFORE UPDATE ON pessoa
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

-- Vínculo pessoa x empresa x perfil. Vínculos nunca são apagados: o fim é
-- registrado em data_fim/status, preservando o histórico.
CREATE TABLE vinculo (
    id                  uuid         PRIMARY KEY DEFAULT uuidv7(),
    pessoa_id           uuid         NOT NULL REFERENCES pessoa (id) ON DELETE RESTRICT,
    empresa_id          uuid         NOT NULL REFERENCES empresa (id) ON DELETE RESTRICT,
    perfil_id           uuid         NOT NULL REFERENCES perfil_vinculo (id) ON DELETE RESTRICT,
    data_inicio         date         NOT NULL,
    data_fim            date,
    status              varchar(15)  NOT NULL DEFAULT 'ativo',
    motivo_encerramento varchar(255),
    encerrado_em        timestamptz,
    created_at          timestamptz  NOT NULL DEFAULT now(),
    updated_at          timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT vinculo_status_ck CHECK (status IN ('ativo', 'suspenso', 'encerrado')),
    CONSTRAINT vinculo_periodo_ck CHECK (data_fim IS NULL OR data_fim >= data_inicio),
    CONSTRAINT vinculo_encerramento_ck CHECK ((status = 'encerrado') = (encerrado_em IS NOT NULL))
);

-- Uma pessoa não pode ter dois vínculos ativos iguais (mesma empresa e perfil).
-- Pode, porém, ser Funcionário e Aluno na mesma empresa.
CREATE UNIQUE INDEX vinculo_ativo_uk ON vinculo (pessoa_id, empresa_id, perfil_id)
    WHERE status <> 'encerrado';
CREATE INDEX vinculo_pessoa_idx ON vinculo (pessoa_id);
CREATE INDEX vinculo_empresa_idx ON vinculo (empresa_id, status);
CREATE INDEX vinculo_data_fim_idx ON vinculo (data_fim) WHERE status <> 'encerrado';

CREATE TRIGGER vinculo_updated_at
    BEFORE UPDATE ON vinculo
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

-- Regras de acesso (lista de permissões: o que não está aqui é negado).
--
-- Hierarquia aplicada pela DecisaoAcesso (Fase 7):
--   1. Se o vínculo tem regras próprias (vinculo_id), SÓ elas valem (exceção).
--   2. Senão, valem as regras do perfil na empresa (empresa_id + perfil_id).
--   3. Senão, vale o horário padrão da empresa (empresa_horario_padrao).
-- catraca_id NULL significa "todas as catracas da empresa".
CREATE TABLE regra_acesso (
    id          uuid        PRIMARY KEY DEFAULT uuidv7(),
    empresa_id  uuid        NOT NULL REFERENCES empresa (id) ON DELETE CASCADE,
    perfil_id   uuid        REFERENCES perfil_vinculo (id) ON DELETE CASCADE,
    vinculo_id  uuid        REFERENCES vinculo (id) ON DELETE CASCADE,
    catraca_id  uuid        REFERENCES catraca (id) ON DELETE CASCADE,
    dia_semana  smallint    NOT NULL,
    hora_inicio time        NOT NULL,
    hora_fim    time        NOT NULL,
    ativo       boolean     NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT regra_acesso_alvo_ck CHECK ((perfil_id IS NULL) <> (vinculo_id IS NULL)),
    CONSTRAINT regra_acesso_dia_ck CHECK (dia_semana BETWEEN 1 AND 7),
    CONSTRAINT regra_acesso_intervalo_ck CHECK (hora_fim > hora_inicio)
);

CREATE INDEX regra_acesso_perfil_idx ON regra_acesso (empresa_id, perfil_id, dia_semana) WHERE ativo;
CREATE INDEX regra_acesso_vinculo_idx ON regra_acesso (vinculo_id, dia_semana) WHERE ativo;
CREATE INDEX regra_acesso_catraca_idx ON regra_acesso (catraca_id);

CREATE TRIGGER regra_acesso_updated_at
    BEFORE UPDATE ON regra_acesso
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();
