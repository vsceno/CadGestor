-- =============================================================================
-- 0003 - Perfis de vínculo, empresas, horários padrão e catracas.
-- =============================================================================

-- Perfis de vínculo (Funcionário, Aluno...). Novos perfis entram por INSERT,
-- sem mudança de código.
--
-- ATENÇÃO JURÍDICO: base_legal define o fundamento para tratar a biometria
-- (dado sensível, art. 11 da LGPD). O padrão é 'consentimento'. Qualquer outro
-- valor deve ser validado pelo jurídico do cliente (base_legal_validada).
CREATE TABLE perfil_vinculo (
    id                  uuid        PRIMARY KEY DEFAULT uuidv7(),
    nome                varchar(60) NOT NULL,
    descricao           varchar(255),
    base_legal          varchar(40) NOT NULL DEFAULT 'consentimento',
    base_legal_validada boolean     NOT NULL DEFAULT false,
    ativo               boolean     NOT NULL DEFAULT true,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT perfil_vinculo_nome_ck CHECK (btrim(nome) <> ''),
    CONSTRAINT perfil_vinculo_base_legal_ck CHECK (base_legal IN (
        'consentimento',               -- art. 11, I
        'obrigacao_legal',             -- art. 11, II, a
        'exercicio_regular_direitos',  -- art. 11, II, d
        'prevencao_fraude_seguranca'   -- art. 11, II, g
    ))
);

CREATE UNIQUE INDEX perfil_vinculo_nome_uk ON perfil_vinculo (lower(nome));

CREATE TRIGGER perfil_vinculo_updated_at
    BEFORE UPDATE ON perfil_vinculo
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

CREATE TABLE empresa (
    id                   uuid         PRIMARY KEY DEFAULT uuidv7(),
    razao_social         varchar(200) NOT NULL,
    nome_fantasia        varchar(200),
    -- 14 posições sem máscara; aceita CNPJ alfanumérico.
    cnpj                 char(14)     NOT NULL,
    cep                  char(8)      NOT NULL,
    logradouro           varchar(200) NOT NULL,
    numero               varchar(20)  NOT NULL,
    complemento          varchar(100),
    bairro               varchar(100) NOT NULL,
    municipio            varchar(100) NOT NULL,
    uf                   char(2)      NOT NULL,
    responsavel_nome     varchar(150) NOT NULL,
    responsavel_cargo    varchar(100),
    responsavel_email    varchar(254) NOT NULL,
    responsavel_telefone varchar(20)  NOT NULL,
    ativo                boolean      NOT NULL DEFAULT true,
    created_at           timestamptz  NOT NULL DEFAULT now(),
    updated_at           timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT empresa_cnpj_uk UNIQUE (cnpj),
    CONSTRAINT empresa_cnpj_ck CHECK (cnpj_valido(cnpj)),
    CONSTRAINT empresa_razao_social_ck CHECK (btrim(razao_social) <> ''),
    CONSTRAINT empresa_cep_ck CHECK (cep ~ '^[0-9]{8}$'),
    CONSTRAINT empresa_uf_ck CHECK (uf IN (
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'
    )),
    CONSTRAINT empresa_responsavel_email_ck CHECK (responsavel_email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
    CONSTRAINT empresa_responsavel_telefone_ck CHECK (responsavel_telefone ~ '^[0-9]{10,13}$')
);

CREATE TRIGGER empresa_updated_at
    BEFORE UPDATE ON empresa
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

-- Quais perfis cada empresa aceita.
CREATE TABLE empresa_perfil (
    empresa_id uuid        NOT NULL REFERENCES empresa (id) ON DELETE CASCADE,
    perfil_id  uuid        NOT NULL REFERENCES perfil_vinculo (id) ON DELETE RESTRICT,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (empresa_id, perfil_id)
);

CREATE INDEX empresa_perfil_perfil_idx ON empresa_perfil (perfil_id);

-- Horário padrão de funcionamento da empresa (último nível da hierarquia de
-- regras: vínculo > perfil na empresa > horário padrão).
-- dia_semana segue a ISO 8601: 1 = segunda ... 7 = domingo (igual ao date('N') do PHP).
-- Turnos que atravessam a meia-noite são cadastrados em dois intervalos
-- (ex.: seg 22:00-24:00 e ter 00:00-06:00).
CREATE TABLE empresa_horario_padrao (
    id          uuid        PRIMARY KEY DEFAULT uuidv7(),
    empresa_id  uuid        NOT NULL REFERENCES empresa (id) ON DELETE CASCADE,
    dia_semana  smallint    NOT NULL,
    hora_inicio time        NOT NULL,
    hora_fim    time        NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT empresa_horario_padrao_dia_ck CHECK (dia_semana BETWEEN 1 AND 7),
    CONSTRAINT empresa_horario_padrao_intervalo_ck CHECK (hora_fim > hora_inicio)
);

CREATE INDEX empresa_horario_padrao_empresa_idx ON empresa_horario_padrao (empresa_id, dia_semana);

CREATE TRIGGER empresa_horario_padrao_updated_at
    BEFORE UPDATE ON empresa_horario_padrao
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

-- Catracas (dispositivos). O token de autenticação da API é guardado só
-- como hash SHA-256; o valor original é mostrado uma única vez no cadastro.
-- modo_falha: comportamento quando o servidor não responde (ver README).
CREATE TABLE catraca (
    id                       uuid         PRIMARY KEY DEFAULT uuidv7(),
    empresa_id               uuid         NOT NULL REFERENCES empresa (id) ON DELETE RESTRICT,
    nome                     varchar(100) NOT NULL,
    localizacao              varchar(150),
    identificador_dispositivo varchar(100) NOT NULL,
    token_hash               char(64)     NOT NULL,
    tipo_identificacao       varchar(20)  NOT NULL DEFAULT 'digital_facial',
    modo_falha               varchar(10)  NOT NULL DEFAULT 'bloquear',
    fabricante               varchar(100),
    modelo                   varchar(100),
    ativo                    boolean      NOT NULL DEFAULT true,
    ultimo_contato_em        timestamptz,
    created_at               timestamptz  NOT NULL DEFAULT now(),
    updated_at               timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT catraca_identificador_uk UNIQUE (identificador_dispositivo),
    CONSTRAINT catraca_token_hash_uk UNIQUE (token_hash),
    CONSTRAINT catraca_nome_ck CHECK (btrim(nome) <> ''),
    CONSTRAINT catraca_token_hash_ck CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    CONSTRAINT catraca_tipo_identificacao_ck CHECK (tipo_identificacao IN ('digital', 'facial', 'digital_facial')),
    CONSTRAINT catraca_modo_falha_ck CHECK (modo_falha IN ('bloquear', 'liberar'))
);

CREATE INDEX catraca_empresa_idx ON catraca (empresa_id);

CREATE TRIGGER catraca_updated_at
    BEFORE UPDATE ON catraca
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();
