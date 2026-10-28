-- =============================================================================
-- 0012 - Cadastro completo do cliente, redefinição de senha por link e
--        ferramentas comerciais da plataforma.
--
-- Princípio mantido: a plataforma só vende e controla planos. Ela não entra
-- no ambiente do cliente e NUNCA define a senha de ninguém: só dispara um
-- link de redefinição para o e-mail do próprio usuário.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Dados cadastrais do cliente (pessoa jurídica). Colunas opcionais no banco
-- porque os clientes antigos não as têm; a aplicação exige o conjunto ao
-- cadastrar/editar. Endereço é "tudo ou nada".
-- -----------------------------------------------------------------------------
ALTER TABLE organizacao
    ADD COLUMN nome_fantasia        varchar(150),
    ADD COLUMN inscricao_municipal  varchar(20),
    ADD COLUMN inscricao_estadual   varchar(20),
    ADD COLUMN cep                  char(8),
    ADD COLUMN logradouro           varchar(200),
    ADD COLUMN numero               varchar(20),
    ADD COLUMN complemento          varchar(100),
    ADD COLUMN bairro               varchar(100),
    ADD COLUMN municipio            varchar(100),
    ADD COLUMN uf                   char(2),
    ADD COLUMN telefone             varchar(11),
    ADD COLUMN email_financeiro     varchar(254),
    ADD COLUMN contato_nome         varchar(150),
    ADD COLUMN contato_telefone     varchar(11),
    ADD CONSTRAINT organizacao_cep_ck CHECK (cep IS NULL OR cep ~ '^[0-9]{8}$'),
    ADD CONSTRAINT organizacao_uf_ck CHECK (uf IS NULL OR uf IN (
        'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'
    )),
    ADD CONSTRAINT organizacao_endereco_ck CHECK (
        (cep IS NULL AND logradouro IS NULL AND numero IS NULL AND bairro IS NULL
            AND municipio IS NULL AND uf IS NULL AND complemento IS NULL)
        OR (cep IS NOT NULL AND logradouro IS NOT NULL AND numero IS NOT NULL AND bairro IS NOT NULL
            AND municipio IS NOT NULL AND uf IS NOT NULL)
    ),
    ADD CONSTRAINT organizacao_telefone_ck CHECK (telefone IS NULL OR telefone ~ '^[0-9]{10,11}$'),
    ADD CONSTRAINT organizacao_contato_telefone_ck
        CHECK (contato_telefone IS NULL OR contato_telefone ~ '^[0-9]{10,11}$'),
    ADD CONSTRAINT organizacao_email_financeiro_ck
        CHECK (email_financeiro IS NULL OR email_financeiro ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'),
    ADD CONSTRAINT organizacao_inscricoes_ck CHECK (
        (inscricao_municipal IS NULL OR inscricao_municipal ~ '^[0-9A-Za-z./-]{1,20}$')
        AND (inscricao_estadual IS NULL OR inscricao_estadual ~ '^[0-9A-Za-z./-]{1,20}$')
    );

-- -----------------------------------------------------------------------------
-- Anotações internas da plataforma sobre o cliente (atendimento comercial).
-- O cliente NÃO enxerga. Só-acrescentar: nada se edita nem se apaga.
-- -----------------------------------------------------------------------------
CREATE TABLE organizacao_nota (
    id             uuid          PRIMARY KEY DEFAULT uuidv7(),
    organizacao_id uuid          NOT NULL REFERENCES organizacao (id),
    autor_id       uuid          NOT NULL REFERENCES usuario_sistema (id),
    texto          varchar(1000) NOT NULL,
    created_at     timestamptz   NOT NULL DEFAULT now(),
    CONSTRAINT organizacao_nota_texto_ck CHECK (btrim(texto) <> '')
);

CREATE INDEX organizacao_nota_organizacao_idx ON organizacao_nota (organizacao_id, created_at DESC);

ALTER TABLE organizacao_nota ENABLE ROW LEVEL SECURITY;
CREATE POLICY somente_plataforma ON organizacao_nota
    USING (cg_plataforma())
    WITH CHECK (cg_plataforma() AND autor_id = cg_usuario_atual());
REVOKE UPDATE, DELETE ON organizacao_nota FROM cadgestor_app;

-- -----------------------------------------------------------------------------
-- Redefinição de senha por link (do token, só o hash) e versão das
-- credenciais: toda troca de senha incrementa a versão e derruba as sessões
-- abertas com a versão anterior.
-- -----------------------------------------------------------------------------
ALTER TABLE usuario_sistema
    ADD COLUMN redefinicao_token_hash char(64),
    ADD COLUMN redefinicao_expira_em  timestamptz,
    ADD COLUMN credenciais_versao     integer NOT NULL DEFAULT 1,
    ADD CONSTRAINT usuario_sistema_redefinicao_uk UNIQUE (redefinicao_token_hash),
    ADD CONSTRAINT usuario_sistema_redefinicao_ck
        CHECK ((redefinicao_token_hash IS NULL) = (redefinicao_expira_em IS NULL)),
    ADD CONSTRAINT usuario_sistema_credenciais_versao_ck CHECK (credenciais_versao > 0);

ALTER TABLE auditoria DROP CONSTRAINT auditoria_acao_ck;
ALTER TABLE auditoria ADD CONSTRAINT auditoria_acao_ck CHECK (acao IN (
    'criar', 'alterar', 'excluir', 'anonimizar',
    'login', 'logout', 'login_falhou', 'senha_alterada',
    'consentimento_aceito', 'consentimento_revogado',
    'biometria_cadastrada', 'biometria_excluida',
    'exportar', 'job_manutencao', 'seed', 'acesso_suporte',
    'cadastro_cliente', 'email_confirmado', 'assinatura_alterada', 'pagamento',
    'redefinicao_solicitada', 'confirmacao_reenviada', 'login_desbloqueado'
));

-- -----------------------------------------------------------------------------
-- Funções do dono (SECURITY DEFINER) para os pontos sem sessão.
-- -----------------------------------------------------------------------------

-- Link de redefinição aberto: conta válida para o token (sem alterar nada).
CREATE FUNCTION cg_conta_por_redefinicao(p_token_hash text)
    RETURNS TABLE (usuario_id uuid, organizacao_id uuid, email varchar)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path FROM CURRENT AS
$$
    SELECT u.id, u.organizacao_id, u.email
      FROM usuario_sistema u
     WHERE u.redefinicao_token_hash = p_token_hash AND u.redefinicao_expira_em > now() AND u.ativo
$$;

-- Redefine a senha pelo token (uso único). O link prova a posse do e-mail,
-- então também conta como e-mail confirmado.
CREATE FUNCTION cg_redefinir_senha(p_token_hash text, p_senha_hash text)
    RETURNS TABLE (usuario_id uuid, organizacao_id uuid)
    LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path FROM CURRENT AS
$$
    UPDATE usuario_sistema
       SET senha_hash = p_senha_hash,
           deve_trocar_senha = false,
           redefinicao_token_hash = NULL,
           redefinicao_expira_em = NULL,
           credenciais_versao = credenciais_versao + 1,
           email_verificado_em = COALESCE(email_verificado_em, now()),
           verificacao_token_hash = NULL,
           verificacao_expira_em = NULL
     WHERE redefinicao_token_hash = p_token_hash AND redefinicao_expira_em > now() AND ativo
    RETURNING id, organizacao_id
$$;

-- Dados de contato que o PRÓPRIO cliente (administrador) pode atualizar.
-- Razão social, CNPJ e situação continuam só com a plataforma (contrato).
CREATE FUNCTION cg_atualizar_contato_organizacao(
    p_nome_fantasia text, p_inscricao_municipal text, p_inscricao_estadual text,
    p_cep text, p_logradouro text, p_numero text, p_complemento text, p_bairro text,
    p_municipio text, p_uf text, p_telefone text, p_email_financeiro text,
    p_contato_nome text, p_contato_telefone text
)
    RETURNS void
    LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path FROM CURRENT AS
$$
BEGIN
    IF cg_org_atual() IS NULL OR NOT EXISTS (
        SELECT 1 FROM usuario_sistema u JOIN usuario_papel p ON p.usuario_id = u.id
         WHERE u.id = cg_usuario_atual() AND u.organizacao_id = cg_org_atual()
           AND u.ativo AND p.papel = 'administrador'
    ) THEN
        RAISE EXCEPTION 'Somente o administrador da organização pode alterar os dados dela.'
            USING ERRCODE = 'insufficient_privilege';
    END IF;

    UPDATE organizacao
       SET nome_fantasia = p_nome_fantasia, inscricao_municipal = p_inscricao_municipal,
           inscricao_estadual = p_inscricao_estadual, cep = p_cep, logradouro = p_logradouro,
           numero = p_numero, complemento = p_complemento, bairro = p_bairro, municipio = p_municipio,
           uf = p_uf, telefone = p_telefone, email_financeiro = p_email_financeiro,
           contato_nome = p_contato_nome, contato_telefone = p_contato_telefone
     WHERE id = cg_org_atual();
END;
$$;

-- Histórico comercial de um cliente para a plataforma. Devolve SÓ ações
-- comerciais (e as feitas pela própria plataforma no cliente) e, dos dados,
-- só chaves de uma lista fechada: nenhum nome, e-mail ou dado de pessoas.
CREATE FUNCTION cg_historico_comercial(p_org uuid, p_limite integer DEFAULT 100)
    RETURNS TABLE (data_hora timestamptz, acao varchar, origem text, tabela varchar, antes jsonb, depois jsonb)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path FROM CURRENT AS
$$
DECLARE
    v_chaves text[] := ARRAY[
        'plano', 'status', 'evento', 'valor_centavos', 'vencimento', 'motivo', 'dias', 'teste_ate',
        'ativo', 'manual', 'termos_versao', 'via', 'campos'
    ];
BEGIN
    IF NOT cg_plataforma() THEN
        RAISE EXCEPTION 'Somente a plataforma pode consultar o histórico comercial.'
            USING ERRCODE = 'insufficient_privilege';
    END IF;

    RETURN QUERY
    SELECT a.data_hora, a.acao,
           CASE WHEN a.usuario_id IS NULL THEN 'sistema'
                WHEN u.organizacao_id IS NULL THEN 'plataforma'
                ELSE 'cliente' END,
           a.tabela,
           (SELECT jsonb_object_agg(k, v) FROM jsonb_each(a.dados_antes) e (k, v) WHERE k = ANY (v_chaves)),
           (SELECT jsonb_object_agg(k, v) FROM jsonb_each(a.dados_depois) e (k, v) WHERE k = ANY (v_chaves))
      FROM auditoria a
      LEFT JOIN usuario_sistema u ON u.id = a.usuario_id
     WHERE (a.organizacao_id = p_org AND (
                a.acao IN ('cadastro_cliente', 'email_confirmado', 'assinatura_alterada', 'pagamento')
                OR (a.tabela = 'organizacao' AND a.registro_id = p_org)
                OR (u.id IS NOT NULL AND u.organizacao_id IS NULL)))
        OR (a.organizacao_id IS NULL AND a.tabela = 'organizacao' AND a.registro_id = p_org)
     ORDER BY a.data_hora DESC
     LIMIT LEAST(GREATEST(p_limite, 1), 500);
END;
$$;

-- CNPJ já usado por OUTRO cliente (edição pela plataforma).
CREATE FUNCTION cg_cnpj_cliente_em_uso(p_cnpj text, p_exceto uuid)
    RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path FROM CURRENT AS
$$ SELECT EXISTS (SELECT 1 FROM organizacao WHERE cnpj = p_cnpj AND id IS DISTINCT FROM p_exceto) $$;

REVOKE ALL ON FUNCTION cg_cnpj_cliente_em_uso(text, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION cg_cnpj_cliente_em_uso(text, uuid) TO cadgestor_app;
REVOKE ALL ON FUNCTION cg_conta_por_redefinicao(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION cg_redefinir_senha(text, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION cg_atualizar_contato_organizacao(
    text, text, text, text, text, text, text, text, text, text, text, text, text, text
) FROM PUBLIC;
REVOKE ALL ON FUNCTION cg_historico_comercial(uuid, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION cg_conta_por_redefinicao(text) TO cadgestor_app;
GRANT EXECUTE ON FUNCTION cg_redefinir_senha(text, text) TO cadgestor_app;
GRANT EXECUTE ON FUNCTION cg_atualizar_contato_organizacao(
    text, text, text, text, text, text, text, text, text, text, text, text, text, text
) TO cadgestor_app;
GRANT EXECUTE ON FUNCTION cg_historico_comercial(uuid, integer) TO cadgestor_app;
