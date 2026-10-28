-- =============================================================================
-- 0011 - Comercial: planos, assinaturas, faturas e cadastro self-service.
--
-- A plataforma CadGestor só vende e controla planos. Cada cliente
-- (organização) é dono do próprio ambiente; a plataforma não entra nele
-- (o modo suporte foi removido da aplicação).
--
-- A situação de acesso do cliente (normal, só leitura, suspenso, catracas
-- bloqueadas) NÃO é gravada: é calculada a partir do status da assinatura e
-- das datas (Dominio\SituacaoAssinatura). Assim não depende de um job rodar.
-- =============================================================================

-- Catálogo de planos (mesmo para todos os clientes). Valores iniciais são
-- PROVISÓRIOS e ajustáveis na tela de planos da plataforma.
CREATE TABLE plano (
    id               uuid         PRIMARY KEY DEFAULT uuidv7(),
    codigo           varchar(30)  NOT NULL,
    nome             varchar(60)  NOT NULL,
    descricao        varchar(255),
    preco_centavos   integer      NOT NULL,
    limite_pessoas   integer      NOT NULL,
    limite_catracas  integer      NOT NULL,
    disponivel_venda boolean      NOT NULL DEFAULT true,
    ordem            smallint     NOT NULL DEFAULT 0,
    created_at       timestamptz  NOT NULL DEFAULT now(),
    updated_at       timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT plano_codigo_uk UNIQUE (codigo),
    CONSTRAINT plano_codigo_ck CHECK (codigo ~ '^[a-z][a-z0-9_]{1,29}$'),
    CONSTRAINT plano_nome_ck CHECK (btrim(nome) <> ''),
    CONSTRAINT plano_preco_ck CHECK (preco_centavos >= 0),
    CONSTRAINT plano_limites_ck CHECK (limite_pessoas > 0 AND limite_catracas > 0)
);

CREATE TRIGGER plano_updated_at
    BEFORE UPDATE ON plano
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

INSERT INTO plano (codigo, nome, descricao, preco_centavos, limite_pessoas, limite_catracas, ordem) VALUES
    ('basico', 'Básico', 'Para começar: uma portaria e poucas pessoas.', 19900, 100, 2, 1),
    ('profissional', 'Profissional', 'Para escolas, condomínios e obras de médio porte.', 49900, 500, 6, 2),
    ('empresarial', 'Empresarial', 'Para operações grandes, com várias portarias.', 129000, 2000, 20, 3);

-- Catálogo: todos leem; só a plataforma altera.
ALTER TABLE plano ENABLE ROW LEVEL SECURITY;
CREATE POLICY leitura_publica ON plano FOR SELECT USING (true);
CREATE POLICY alteracao_plataforma ON plano FOR INSERT WITH CHECK (cg_plataforma());
CREATE POLICY atualizacao_plataforma ON plano FOR UPDATE USING (cg_plataforma()) WITH CHECK (cg_plataforma());
REVOKE DELETE ON plano FROM cadgestor_app;

-- -----------------------------------------------------------------------------
-- Dados do cliente na organização: CNPJ (evita testes grátis repetidos) e o
-- aceite dos Termos de Uso / contrato de tratamento de dados.
-- -----------------------------------------------------------------------------
ALTER TABLE organizacao
    ADD COLUMN cnpj                  char(14),
    ADD COLUMN termos_versao         varchar(20),
    ADD COLUMN termos_aceitos_em     timestamptz,
    ADD COLUMN termos_aceitos_ip     inet,
    ADD CONSTRAINT organizacao_cnpj_uk UNIQUE (cnpj),
    ADD CONSTRAINT organizacao_cnpj_ck CHECK (cnpj IS NULL OR cnpj_valido(cnpj)),
    ADD CONSTRAINT organizacao_termos_ck CHECK ((termos_versao IS NULL) = (termos_aceitos_em IS NULL));

-- -----------------------------------------------------------------------------
-- Assinatura: uma por organização.
--   teste        -> período grátis até teste_ate;
--   ativa        -> em dia;
--   inadimplente -> pagamento vencido desde inadimplente_desde;
--   cancelada    -> encerrada pelo cliente ou pela plataforma.
-- -----------------------------------------------------------------------------
CREATE TABLE assinatura (
    id                    uuid         PRIMARY KEY DEFAULT uuidv7(),
    organizacao_id        uuid         NOT NULL DEFAULT cg_org_atual() REFERENCES organizacao (id),
    plano_id              uuid         NOT NULL REFERENCES plano (id),
    status                varchar(15)  NOT NULL,
    teste_ate             date,
    inadimplente_desde    date,
    cancelada_em          timestamptz,
    gateway               varchar(20),
    gateway_cliente_id    varchar(100),
    gateway_assinatura_id varchar(100),
    created_at            timestamptz  NOT NULL DEFAULT now(),
    updated_at            timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT assinatura_organizacao_uk UNIQUE (organizacao_id),
    CONSTRAINT assinatura_organizacao_id_uk UNIQUE (organizacao_id, id),
    CONSTRAINT assinatura_gateway_uk UNIQUE (gateway, gateway_assinatura_id),
    CONSTRAINT assinatura_status_ck CHECK (status IN ('teste', 'ativa', 'inadimplente', 'cancelada')),
    CONSTRAINT assinatura_teste_ck CHECK (status <> 'teste' OR teste_ate IS NOT NULL),
    CONSTRAINT assinatura_inadimplente_ck CHECK (status <> 'inadimplente' OR inadimplente_desde IS NOT NULL),
    CONSTRAINT assinatura_cancelada_ck CHECK ((status = 'cancelada') = (cancelada_em IS NOT NULL))
);

CREATE TRIGGER assinatura_updated_at
    BEFORE UPDATE ON assinatura
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();
CREATE TRIGGER assinatura_organizacao_fixa
    BEFORE UPDATE OF organizacao_id ON assinatura
    FOR EACH ROW EXECUTE FUNCTION impedir_troca_organizacao();

ALTER TABLE assinatura ENABLE ROW LEVEL SECURITY;
CREATE POLICY isolamento_organizacao ON assinatura
    USING (organizacao_id = cg_org_atual() OR cg_plataforma())
    WITH CHECK (organizacao_id = cg_org_atual() OR cg_plataforma());
REVOKE DELETE ON assinatura FROM cadgestor_app;

-- Organizações que já existiam: assinatura ativa no plano Empresarial, sem
-- gateway (cortesia/contrato manual), para não interromper quem já usa.
INSERT INTO assinatura (organizacao_id, plano_id, status)
SELECT o.id, (SELECT id FROM plano WHERE codigo = 'empresarial'), 'ativa'
  FROM organizacao o;

-- -----------------------------------------------------------------------------
-- Faturas (cobranças) espelhadas do gateway de pagamento. O pagamento em si
-- (cartão, Pix, boleto) acontece no gateway: nenhum dado de cartão passa aqui.
-- -----------------------------------------------------------------------------
CREATE TABLE fatura (
    id                  uuid          PRIMARY KEY DEFAULT uuidv7(),
    organizacao_id      uuid          NOT NULL DEFAULT cg_org_atual() REFERENCES organizacao (id),
    assinatura_id       uuid          NOT NULL,
    gateway             varchar(20)   NOT NULL,
    gateway_cobranca_id varchar(100)  NOT NULL,
    valor_centavos      integer       NOT NULL,
    vencimento          date          NOT NULL,
    status              varchar(15)   NOT NULL DEFAULT 'pendente',
    link_pagamento      varchar(500),
    pago_em             timestamptz,
    created_at          timestamptz   NOT NULL DEFAULT now(),
    updated_at          timestamptz   NOT NULL DEFAULT now(),
    CONSTRAINT fatura_assinatura_org_fk
        FOREIGN KEY (organizacao_id, assinatura_id) REFERENCES assinatura (organizacao_id, id),
    CONSTRAINT fatura_gateway_uk UNIQUE (gateway, gateway_cobranca_id),
    CONSTRAINT fatura_valor_ck CHECK (valor_centavos >= 0),
    CONSTRAINT fatura_status_ck CHECK (status IN ('pendente', 'paga', 'vencida', 'cancelada')),
    CONSTRAINT fatura_pagamento_ck CHECK ((status = 'paga') = (pago_em IS NOT NULL)),
    CONSTRAINT fatura_link_ck CHECK (link_pagamento IS NULL OR link_pagamento ~ '^(https://|/)')
);

CREATE INDEX fatura_organizacao_idx ON fatura (organizacao_id, vencimento DESC);

CREATE TRIGGER fatura_updated_at
    BEFORE UPDATE ON fatura
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();
CREATE TRIGGER fatura_organizacao_fixa
    BEFORE UPDATE OF organizacao_id ON fatura
    FOR EACH ROW EXECUTE FUNCTION impedir_troca_organizacao();

ALTER TABLE fatura ENABLE ROW LEVEL SECURITY;
CREATE POLICY isolamento_organizacao ON fatura
    USING (organizacao_id = cg_org_atual() OR cg_plataforma())
    WITH CHECK (organizacao_id = cg_org_atual() OR cg_plataforma());
REVOKE DELETE ON fatura FROM cadgestor_app;

-- -----------------------------------------------------------------------------
-- Notificações recebidas do gateway (webhook). O identificador do evento é
-- único: a mesma notificação reenviada não é processada duas vezes.
-- Guarda só o necessário (nada de dados do pagador).
-- -----------------------------------------------------------------------------
CREATE TABLE evento_gateway (
    id                  uuid          PRIMARY KEY DEFAULT uuidv7(),
    gateway             varchar(20)   NOT NULL,
    evento_id           varchar(150)  NOT NULL,
    tipo                varchar(40)   NOT NULL,
    gateway_cobranca_id varchar(100),
    recebido_em         timestamptz   NOT NULL DEFAULT now(),
    resultado           varchar(40)   NOT NULL,
    CONSTRAINT evento_gateway_uk UNIQUE (gateway, evento_id)
);

REVOKE UPDATE, DELETE ON evento_gateway FROM cadgestor_app;

-- -----------------------------------------------------------------------------
-- Confirmação de e-mail do cadastro self-service. Usuários criados por um
-- administrador já nascem confirmados (o administrador responde por eles).
-- -----------------------------------------------------------------------------
ALTER TABLE usuario_sistema
    ADD COLUMN email_verificado_em      timestamptz DEFAULT now(),
    ADD COLUMN verificacao_token_hash   char(64),
    ADD COLUMN verificacao_expira_em    timestamptz,
    ADD CONSTRAINT usuario_sistema_verificacao_uk UNIQUE (verificacao_token_hash),
    ADD CONSTRAINT usuario_sistema_verificacao_ck CHECK ((verificacao_token_hash IS NULL) = (verificacao_expira_em IS NULL));

UPDATE usuario_sistema SET email_verificado_em = created_at WHERE email_verificado_em IS NULL;

ALTER TABLE auditoria DROP CONSTRAINT auditoria_acao_ck;
ALTER TABLE auditoria ADD CONSTRAINT auditoria_acao_ck CHECK (acao IN (
    'criar', 'alterar', 'excluir', 'anonimizar',
    'login', 'logout', 'login_falhou', 'senha_alterada',
    'consentimento_aceito', 'consentimento_revogado',
    'biometria_cadastrada', 'biometria_excluida',
    'exportar', 'job_manutencao', 'seed', 'acesso_suporte',
    'cadastro_cliente', 'email_confirmado', 'assinatura_alterada', 'pagamento'
));

-- -----------------------------------------------------------------------------
-- Funções do dono para pontos sem organização conhecida.
-- -----------------------------------------------------------------------------

-- Confirmação de e-mail pelo link: o token chega sem sessão.
CREATE FUNCTION cg_confirmar_email(p_token_hash text)
    RETURNS TABLE (usuario_id uuid, organizacao_id uuid)
    LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path FROM CURRENT AS
$$
    UPDATE usuario_sistema
       SET email_verificado_em = now(), verificacao_token_hash = NULL, verificacao_expira_em = NULL
     WHERE verificacao_token_hash = p_token_hash AND verificacao_expira_em > now()
    RETURNING id, organizacao_id
$$;

-- Webhook: descobre a organização dona de uma cobrança/assinatura do gateway.
CREATE FUNCTION cg_organizacao_por_gateway(p_gateway text, p_cobranca text, p_assinatura text)
    RETURNS uuid
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path FROM CURRENT AS
$$
    SELECT COALESCE(
        (SELECT f.organizacao_id FROM fatura f WHERE f.gateway = p_gateway AND f.gateway_cobranca_id = p_cobranca),
        (SELECT a.organizacao_id FROM assinatura a
          WHERE a.gateway = p_gateway AND a.gateway_assinatura_id = p_assinatura)
    )
$$;

-- CNPJ já usado por outra organização (o RLS esconderia as demais).
CREATE FUNCTION cg_cnpj_cliente_em_uso(p_cnpj text)
    RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path FROM CURRENT AS
$$ SELECT EXISTS (SELECT 1 FROM organizacao WHERE cnpj = p_cnpj) $$;

REVOKE ALL ON FUNCTION cg_confirmar_email(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION cg_organizacao_por_gateway(text, text, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION cg_cnpj_cliente_em_uso(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION cg_confirmar_email(text) TO cadgestor_app;
GRANT EXECUTE ON FUNCTION cg_organizacao_por_gateway(text, text, text) TO cadgestor_app;
GRANT EXECUTE ON FUNCTION cg_cnpj_cliente_em_uso(text) TO cadgestor_app;
