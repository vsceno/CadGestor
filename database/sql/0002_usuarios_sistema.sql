-- =============================================================================
-- 0002 - Usuários do sistema (operadores), papéis e tentativas de login.
-- =============================================================================

CREATE TABLE usuario_sistema (
    id                uuid        PRIMARY KEY DEFAULT uuidv7(),
    nome              varchar(150) NOT NULL,
    email             varchar(254) NOT NULL,
    senha_hash        varchar(255) NOT NULL,
    ativo             boolean     NOT NULL DEFAULT true,
    -- Força a troca da senha no próximo login (ex.: senha gerada pelo seed).
    deve_trocar_senha boolean     NOT NULL DEFAULT false,
    ultimo_login_em   timestamptz,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT usuario_sistema_nome_ck CHECK (btrim(nome) <> ''),
    CONSTRAINT usuario_sistema_email_ck CHECK (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$')
);

-- E-mail único sem diferenciar maiúsculas/minúsculas.
CREATE UNIQUE INDEX usuario_sistema_email_uk ON usuario_sistema (lower(email));

CREATE TRIGGER usuario_sistema_updated_at
    BEFORE UPDATE ON usuario_sistema
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

-- Um usuário pode acumular papéis; cada papel é uma permissão separada.
CREATE TABLE usuario_papel (
    usuario_id uuid        NOT NULL REFERENCES usuario_sistema (id) ON DELETE CASCADE,
    papel      varchar(30) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (usuario_id, papel),
    CONSTRAINT usuario_papel_papel_ck CHECK (
        papel IN ('administrador', 'cadastro_empresa', 'cadastro_pessoa', 'consulta')
    )
);

-- Base para bloqueio por força bruta (Fase 2). Guarda o hash do e-mail,
-- não o e-mail em si, para não acumular dado pessoal em tabela de controle.
CREATE TABLE tentativa_login (
    id         uuid        PRIMARY KEY DEFAULT uuidv7(),
    email_hash char(64)    NOT NULL,
    ip         inet        NOT NULL,
    sucesso    boolean     NOT NULL,
    data_hora  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX tentativa_login_email_idx ON tentativa_login (email_hash, data_hora DESC);
CREATE INDEX tentativa_login_ip_idx ON tentativa_login (ip, data_hora DESC);
