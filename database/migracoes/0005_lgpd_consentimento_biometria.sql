-- =============================================================================
-- 0005 - LGPD: termos de consentimento, aceites e biometria cifrada.
--
-- Regra não negociável: nenhuma biometria é gravada sem consentimento ativo.
-- Além da validação na aplicação (Fase 6), um trigger garante isso no banco.
--
-- Foto facial: a tabela foto_facial NÃO é criada agora. O padrão é guardar só
-- o template. Ela só será criada se o dispositivo escolhido exigir a foto para
-- gerar o template (pergunta em aberto sobre o fabricante).
-- =============================================================================

-- Versões publicadas do termo. Depois de publicado, o texto não muda:
-- uma alteração exige nova versão (o titular aceitou AQUELE texto).
CREATE TABLE termo_consentimento (
    id            uuid        PRIMARY KEY DEFAULT uuidv7(),
    versao        varchar(20) NOT NULL,
    finalidade    varchar(40) NOT NULL DEFAULT 'controle_acesso_biometrico',
    texto         text        NOT NULL,
    hash_texto    char(64)    NOT NULL,
    vigente_desde timestamptz NOT NULL DEFAULT now(),
    ativo         boolean     NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT termo_consentimento_versao_uk UNIQUE (finalidade, versao),
    CONSTRAINT termo_consentimento_finalidade_ck CHECK (finalidade IN ('controle_acesso_biometrico')),
    CONSTRAINT termo_consentimento_hash_ck CHECK (hash_texto = encode(sha256(convert_to(texto, 'UTF8')), 'hex'))
);

CREATE FUNCTION impedir_alteracao_termo() RETURNS trigger
    LANGUAGE plpgsql AS
$$
BEGIN
    IF NEW.texto IS DISTINCT FROM OLD.texto
       OR NEW.versao IS DISTINCT FROM OLD.versao
       OR NEW.finalidade IS DISTINCT FROM OLD.finalidade THEN
        RAISE EXCEPTION 'Termo publicado não pode ser alterado; publique uma nova versão.'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER termo_consentimento_imutavel
    BEFORE UPDATE ON termo_consentimento
    FOR EACH ROW EXECUTE FUNCTION impedir_alteracao_termo();

CREATE TRIGGER termo_consentimento_updated_at
    BEFORE UPDATE ON termo_consentimento
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

-- Aceite (e revogação) do titular. Menores de 18 anos exigem os dados do
-- responsável legal.
CREATE TABLE consentimento (
    id                     uuid         PRIMARY KEY DEFAULT uuidv7(),
    pessoa_id              uuid         NOT NULL REFERENCES pessoa (id) ON DELETE RESTRICT,
    termo_id               uuid         NOT NULL REFERENCES termo_consentimento (id) ON DELETE RESTRICT,
    finalidade             varchar(40)  NOT NULL DEFAULT 'controle_acesso_biometrico',
    versao_termo           varchar(20)  NOT NULL,
    hash_termo             char(64)     NOT NULL,
    aceito_em              timestamptz  NOT NULL DEFAULT now(),
    ip                     inet         NOT NULL,
    canal                  varchar(20)  NOT NULL DEFAULT 'presencial',
    titular_menor          boolean      NOT NULL,
    responsavel_nome       varchar(150),
    responsavel_cpf        char(11),
    responsavel_parentesco varchar(30),
    registrado_por         uuid         REFERENCES usuario_sistema (id) ON DELETE SET NULL,
    revogado_em            timestamptz,
    revogacao_ip           inet,
    created_at             timestamptz  NOT NULL DEFAULT now(),
    updated_at             timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT consentimento_finalidade_ck CHECK (finalidade IN ('controle_acesso_biometrico')),
    CONSTRAINT consentimento_canal_ck CHECK (canal IN ('presencial', 'digital')),
    CONSTRAINT consentimento_responsavel_ck CHECK (
        NOT titular_menor OR (
            responsavel_nome IS NOT NULL
            AND responsavel_cpf IS NOT NULL
            AND responsavel_parentesco IS NOT NULL
        )
    ),
    CONSTRAINT consentimento_responsavel_cpf_ck CHECK (responsavel_cpf IS NULL OR cpf_valido(responsavel_cpf)),
    CONSTRAINT consentimento_revogacao_ck CHECK (revogado_em IS NULL OR revogado_em >= aceito_em)
);

-- No máximo um consentimento ativo por pessoa e finalidade.
CREATE UNIQUE INDEX consentimento_ativo_uk ON consentimento (pessoa_id, finalidade)
    WHERE revogado_em IS NULL;
CREATE INDEX consentimento_pessoa_idx ON consentimento (pessoa_id);

CREATE TRIGGER consentimento_updated_at
    BEFORE UPDATE ON consentimento
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

-- Templates biométricos. Somente o template cifrado (XChaCha20-Poly1305,
-- chave fora do banco). Imagem bruta de digital NUNCA é gravada.
CREATE TABLE biometria (
    id               uuid        PRIMARY KEY DEFAULT uuidv7(),
    pessoa_id        uuid        NOT NULL REFERENCES pessoa (id) ON DELETE RESTRICT,
    consentimento_id uuid        NOT NULL REFERENCES consentimento (id) ON DELETE RESTRICT,
    tipo             varchar(10) NOT NULL,
    -- Dedo (1 = polegar direito ... 10 = mínimo esquerdo), só para digital.
    dedo             smallint,
    template_cifrado bytea,
    nonce            bytea,
    versao_chave     smallint    NOT NULL DEFAULT 1,
    -- Hash com chave (BLAKE2b) do template, para conferir integridade e
    -- sincronização com os dispositivos sem decifrar.
    hash_integridade char(64),
    ativo            boolean     NOT NULL DEFAULT true,
    excluido_em      timestamptz,
    motivo_exclusao  varchar(40),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT biometria_tipo_ck CHECK (tipo IN ('facial', 'digital')),
    CONSTRAINT biometria_dedo_ck CHECK (
        (tipo = 'digital' AND dedo BETWEEN 1 AND 10) OR (tipo = 'facial' AND dedo IS NULL)
    ),
    -- Excluída = dados apagados de fato (não só marcados).
    CONSTRAINT biometria_exclusao_ck CHECK (
        (excluido_em IS NULL AND template_cifrado IS NOT NULL AND nonce IS NOT NULL AND hash_integridade IS NOT NULL)
        OR (excluido_em IS NOT NULL AND NOT ativo AND template_cifrado IS NULL AND nonce IS NULL
            AND hash_integridade IS NULL AND motivo_exclusao IS NOT NULL)
    ),
    CONSTRAINT biometria_motivo_ck CHECK (motivo_exclusao IS NULL OR motivo_exclusao IN (
        'revogacao_consentimento', 'retencao_vencida', 'substituicao', 'solicitacao_titular'
    ))
);

CREATE INDEX biometria_pessoa_idx ON biometria (pessoa_id) WHERE ativo;
CREATE UNIQUE INDEX biometria_ativa_uk ON biometria (pessoa_id, tipo, COALESCE(dedo, 0)) WHERE ativo;

CREATE TRIGGER biometria_updated_at
    BEFORE UPDATE ON biometria
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

-- Trava no banco: biometria ativa só com consentimento ativo da própria pessoa.
CREATE FUNCTION verificar_consentimento_biometria() RETURNS trigger
    LANGUAGE plpgsql AS
$$
BEGIN
    IF NEW.ativo AND NOT EXISTS (
        SELECT 1
          FROM consentimento c
         WHERE c.id = NEW.consentimento_id
           AND c.pessoa_id = NEW.pessoa_id
           AND c.finalidade = 'controle_acesso_biometrico'
           AND c.revogado_em IS NULL
    ) THEN
        RAISE EXCEPTION 'Biometria exige consentimento ativo do titular.'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER biometria_exige_consentimento
    BEFORE INSERT OR UPDATE OF ativo, consentimento_id, pessoa_id ON biometria
    FOR EACH ROW EXECUTE FUNCTION verificar_consentimento_biometria();
