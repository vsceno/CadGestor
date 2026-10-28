-- =============================================================================
-- 0010 - Multi-tenant: organizações (clientes) isoladas por Row-Level Security.
--
-- Cada cliente que contrata o CadGestor é uma ORGANIZAÇÃO. Dentro dela ficam
-- empresas, perfis, catracas, pessoas, vínculos, consentimentos, biometrias,
-- eventos, alertas, usuários e auditoria. Organizações nunca se enxergam.
--
-- Como funciona (analogia: prédio com crachá por andar):
--   - a aplicação informa ao banco, no início de cada requisição, de qual
--     organização é o usuário (set_config('app.organizacao_id', ...));
--   - as políticas de RLS fazem o banco mostrar e aceitar SOMENTE linhas
--     daquela organização, mesmo que uma consulta esqueça o filtro;
--   - organizacao_id tem DEFAULT cg_org_atual(): os INSERTs existentes não
--     precisam informar a organização;
--   - chaves estrangeiras compostas (organizacao_id, id) impedem que um
--     registro aponte para outro de organização diferente;
--   - um trigger impede que um registro "mude de organização".
--
-- O papel dono (cadgestor_owner: migrações e jobs) ignora o RLS, como todo
-- dono de tabela no PostgreSQL; por isso os jobs filtram a organização de
-- forma explícita.
--
-- Usuários com organizacao_id NULL são da PLATAFORMA (papel 'plataforma'):
-- cadastram organizações e seus administradores, e não enxergam dados
-- pessoais das organizações, a não ser entrando nelas em "modo suporte",
-- o que fica registrado na auditoria da organização.
-- =============================================================================

CREATE TABLE organizacao (
    id         uuid         PRIMARY KEY DEFAULT uuidv7(),
    nome       varchar(150) NOT NULL,
    ativo      boolean      NOT NULL DEFAULT true,
    created_at timestamptz  NOT NULL DEFAULT now(),
    updated_at timestamptz  NOT NULL DEFAULT now(),
    CONSTRAINT organizacao_nome_ck CHECK (btrim(nome) <> '')
);

CREATE UNIQUE INDEX organizacao_nome_uk ON organizacao (lower(nome));

CREATE TRIGGER organizacao_updated_at
    BEFORE UPDATE ON organizacao
    FOR EACH ROW EXECUTE FUNCTION definir_updated_at();

-- -----------------------------------------------------------------------------
-- Contexto da sessão do banco, definido pela aplicação a cada requisição.
-- Sem contexto, cg_org_atual() é NULL e nenhuma linha de organização aparece.
-- -----------------------------------------------------------------------------
CREATE FUNCTION cg_org_atual() RETURNS uuid
    LANGUAGE sql STABLE PARALLEL SAFE AS
$$ SELECT NULLIF(current_setting('app.organizacao_id', true), '')::uuid $$;

CREATE FUNCTION cg_usuario_atual() RETURNS uuid
    LANGUAGE sql STABLE PARALLEL SAFE AS
$$ SELECT NULLIF(current_setting('app.usuario_id', true), '')::uuid $$;

CREATE FUNCTION cg_plataforma() RETURNS boolean
    LANGUAGE sql STABLE PARALLEL SAFE AS
$$ SELECT COALESCE(current_setting('app.plataforma', true), '') = 'on' $$;

CREATE FUNCTION impedir_troca_organizacao() RETURNS trigger
    LANGUAGE plpgsql AS
$$
BEGIN
    IF NEW.organizacao_id IS DISTINCT FROM OLD.organizacao_id THEN
        RAISE EXCEPTION 'Um registro não pode mudar de organização.'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

-- -----------------------------------------------------------------------------
-- Dados existentes: se o banco já tem cadastros, eles passam a pertencer a
-- uma "Organização padrão" (o nome pode ser alterado depois pela plataforma).
-- -----------------------------------------------------------------------------
DO $$
DECLARE
    v_tabela text;
    v_tem_dados boolean := false;
BEGIN
    FOREACH v_tabela IN ARRAY ARRAY[
        'usuario_sistema', 'perfil_vinculo', 'empresa', 'catraca', 'pessoa', 'termo_consentimento',
        'evento_acesso', 'auditoria'
    ] LOOP
        EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I)', v_tabela) INTO v_tem_dados;
        EXIT WHEN v_tem_dados;
    END LOOP;

    IF v_tem_dados THEN
        INSERT INTO organizacao (nome) VALUES ('Organização padrão');
    END IF;
END;
$$;

-- -----------------------------------------------------------------------------
-- organizacao_id obrigatório nas tabelas de cada organização, com RLS.
-- -----------------------------------------------------------------------------
DO $$
DECLARE
    v_org    uuid := (SELECT id FROM organizacao ORDER BY created_at LIMIT 1);
    v_tabela text;
BEGIN
    FOREACH v_tabela IN ARRAY ARRAY[
        'perfil_vinculo', 'empresa', 'empresa_perfil', 'empresa_horario_padrao', 'catraca', 'pessoa', 'vinculo',
        'regra_acesso', 'termo_consentimento', 'consentimento', 'biometria', 'fila_sincronizacao'
    ] LOOP
        EXECUTE format('ALTER TABLE %I ADD COLUMN organizacao_id uuid', v_tabela);
        EXECUTE format('UPDATE %I SET organizacao_id = $1', v_tabela) USING v_org;
        EXECUTE format(
            'ALTER TABLE %I ALTER COLUMN organizacao_id SET NOT NULL,'
            || ' ALTER COLUMN organizacao_id SET DEFAULT cg_org_atual(),'
            || ' ADD CONSTRAINT %I FOREIGN KEY (organizacao_id) REFERENCES organizacao (id) ON DELETE RESTRICT',
            v_tabela, v_tabela || '_organizacao_fk'
        );
        EXECUTE format(
            'CREATE TRIGGER %I BEFORE UPDATE OF organizacao_id ON %I'
            || ' FOR EACH ROW EXECUTE FUNCTION impedir_troca_organizacao()',
            v_tabela || '_organizacao_fixa', v_tabela
        );
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', v_tabela);
        EXECUTE format(
            'CREATE POLICY isolamento_organizacao ON %I'
            || ' USING (organizacao_id = cg_org_atual()) WITH CHECK (organizacao_id = cg_org_atual())',
            v_tabela
        );

        -- (organizacao_id, id) é alvo das FKs compostas e serve de índice por organização.
        IF v_tabela = 'empresa_perfil' THEN
            CREATE INDEX empresa_perfil_organizacao_idx ON empresa_perfil (organizacao_id);
        ELSE
            EXECUTE format(
                'ALTER TABLE %I ADD CONSTRAINT %I UNIQUE (organizacao_id, id)',
                v_tabela, v_tabela || '_organizacao_id_uk'
            );
        END IF;
    END LOOP;
END;
$$;

-- -----------------------------------------------------------------------------
-- Tabelas em que a organização pode faltar:
--   evento_acesso/alerta: requisição com token de catraca inexistente (não se
--     sabe de qual organização é) fica no nível da plataforma;
--   auditoria: ações da plataforma e falhas de login de e-mail inexistente.
-- Linhas sem organização só aparecem quando a sessão também não tem organização.
-- -----------------------------------------------------------------------------
ALTER TABLE evento_acesso ADD COLUMN organizacao_id uuid;
ALTER TABLE alerta ADD COLUMN organizacao_id uuid;
ALTER TABLE auditoria ADD COLUMN organizacao_id uuid;

UPDATE evento_acesso SET organizacao_id = (SELECT id FROM organizacao ORDER BY created_at LIMIT 1)
 WHERE catraca_id IS NOT NULL OR pessoa_id IS NOT NULL;
UPDATE alerta SET organizacao_id = (SELECT id FROM organizacao ORDER BY created_at LIMIT 1)
 WHERE catraca_id IS NOT NULL OR pessoa_id IS NOT NULL;
UPDATE auditoria SET organizacao_id = (SELECT id FROM organizacao ORDER BY created_at LIMIT 1);

ALTER TABLE evento_acesso
    ALTER COLUMN organizacao_id SET DEFAULT cg_org_atual(),
    ADD CONSTRAINT evento_acesso_organizacao_fk FOREIGN KEY (organizacao_id) REFERENCES organizacao (id),
    ADD CONSTRAINT evento_acesso_organizacao_id_uk UNIQUE (organizacao_id, id),
    ADD CONSTRAINT evento_acesso_organizacao_ck CHECK (
        organizacao_id IS NOT NULL OR (catraca_id IS NULL AND pessoa_id IS NULL)
    );
ALTER TABLE alerta
    ALTER COLUMN organizacao_id SET DEFAULT cg_org_atual(),
    ADD CONSTRAINT alerta_organizacao_fk FOREIGN KEY (organizacao_id) REFERENCES organizacao (id),
    ADD CONSTRAINT alerta_organizacao_ck CHECK (
        organizacao_id IS NOT NULL OR (catraca_id IS NULL AND pessoa_id IS NULL)
    );
ALTER TABLE auditoria
    ALTER COLUMN organizacao_id SET DEFAULT cg_org_atual(),
    ADD CONSTRAINT auditoria_organizacao_fk FOREIGN KEY (organizacao_id) REFERENCES organizacao (id);

CREATE INDEX alerta_organizacao_idx ON alerta (organizacao_id, data_hora DESC);
CREATE INDEX auditoria_organizacao_idx ON auditoria (organizacao_id, data_hora DESC);

DO $$
DECLARE
    v_tabela text;
BEGIN
    FOREACH v_tabela IN ARRAY ARRAY['evento_acesso', 'alerta', 'auditoria'] LOOP
        EXECUTE format(
            'CREATE TRIGGER %I BEFORE UPDATE OF organizacao_id ON %I'
            || ' FOR EACH ROW EXECUTE FUNCTION impedir_troca_organizacao()',
            v_tabela || '_organizacao_fixa', v_tabela
        );
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', v_tabela);
        EXECUTE format(
            'CREATE POLICY isolamento_organizacao ON %I'
            || ' USING (organizacao_id IS NOT DISTINCT FROM cg_org_atual())'
            || ' WITH CHECK (organizacao_id IS NOT DISTINCT FROM cg_org_atual())',
            v_tabela
        );
    END LOOP;
END;
$$;

ALTER TABLE auditoria DROP CONSTRAINT auditoria_acao_ck;
ALTER TABLE auditoria ADD CONSTRAINT auditoria_acao_ck CHECK (acao IN (
    'criar', 'alterar', 'excluir', 'anonimizar',
    'login', 'logout', 'login_falhou', 'senha_alterada',
    'consentimento_aceito', 'consentimento_revogado',
    'biometria_cadastrada', 'biometria_excluida',
    'exportar', 'job_manutencao', 'seed', 'acesso_suporte'
));

-- -----------------------------------------------------------------------------
-- Chaves estrangeiras compostas: o registro referenciado tem de ser da MESMA
-- organização. (As FKs simples originais continuam, com suas regras de ON DELETE.)
-- -----------------------------------------------------------------------------
ALTER TABLE empresa_perfil
    ADD CONSTRAINT empresa_perfil_empresa_org_fk
        FOREIGN KEY (organizacao_id, empresa_id) REFERENCES empresa (organizacao_id, id) ON DELETE CASCADE,
    ADD CONSTRAINT empresa_perfil_perfil_org_fk
        FOREIGN KEY (organizacao_id, perfil_id) REFERENCES perfil_vinculo (organizacao_id, id);

ALTER TABLE empresa_horario_padrao
    ADD CONSTRAINT empresa_horario_padrao_empresa_org_fk
        FOREIGN KEY (organizacao_id, empresa_id) REFERENCES empresa (organizacao_id, id) ON DELETE CASCADE;

ALTER TABLE catraca
    ADD CONSTRAINT catraca_empresa_org_fk
        FOREIGN KEY (organizacao_id, empresa_id) REFERENCES empresa (organizacao_id, id);

ALTER TABLE vinculo
    ADD CONSTRAINT vinculo_pessoa_org_fk
        FOREIGN KEY (organizacao_id, pessoa_id) REFERENCES pessoa (organizacao_id, id),
    ADD CONSTRAINT vinculo_empresa_org_fk
        FOREIGN KEY (organizacao_id, empresa_id) REFERENCES empresa (organizacao_id, id),
    ADD CONSTRAINT vinculo_perfil_org_fk
        FOREIGN KEY (organizacao_id, perfil_id) REFERENCES perfil_vinculo (organizacao_id, id);

ALTER TABLE regra_acesso
    ADD CONSTRAINT regra_acesso_empresa_org_fk
        FOREIGN KEY (organizacao_id, empresa_id) REFERENCES empresa (organizacao_id, id) ON DELETE CASCADE,
    ADD CONSTRAINT regra_acesso_perfil_org_fk
        FOREIGN KEY (organizacao_id, perfil_id) REFERENCES perfil_vinculo (organizacao_id, id) ON DELETE CASCADE,
    ADD CONSTRAINT regra_acesso_vinculo_org_fk
        FOREIGN KEY (organizacao_id, vinculo_id) REFERENCES vinculo (organizacao_id, id) ON DELETE CASCADE,
    ADD CONSTRAINT regra_acesso_catraca_org_fk
        FOREIGN KEY (organizacao_id, catraca_id) REFERENCES catraca (organizacao_id, id) ON DELETE CASCADE;

ALTER TABLE consentimento
    ADD CONSTRAINT consentimento_pessoa_org_fk
        FOREIGN KEY (organizacao_id, pessoa_id) REFERENCES pessoa (organizacao_id, id),
    ADD CONSTRAINT consentimento_termo_org_fk
        FOREIGN KEY (organizacao_id, termo_id) REFERENCES termo_consentimento (organizacao_id, id);

ALTER TABLE biometria
    ADD CONSTRAINT biometria_pessoa_org_fk
        FOREIGN KEY (organizacao_id, pessoa_id) REFERENCES pessoa (organizacao_id, id),
    ADD CONSTRAINT biometria_consentimento_org_fk
        FOREIGN KEY (organizacao_id, consentimento_id) REFERENCES consentimento (organizacao_id, id);

ALTER TABLE fila_sincronizacao
    ADD CONSTRAINT fila_sincronizacao_catraca_org_fk
        FOREIGN KEY (organizacao_id, catraca_id) REFERENCES catraca (organizacao_id, id) ON DELETE CASCADE,
    ADD CONSTRAINT fila_sincronizacao_pessoa_org_fk
        FOREIGN KEY (organizacao_id, pessoa_id) REFERENCES pessoa (organizacao_id, id);

ALTER TABLE evento_acesso
    ADD CONSTRAINT evento_acesso_catraca_org_fk
        FOREIGN KEY (organizacao_id, catraca_id) REFERENCES catraca (organizacao_id, id),
    ADD CONSTRAINT evento_acesso_empresa_org_fk
        FOREIGN KEY (organizacao_id, empresa_id) REFERENCES empresa (organizacao_id, id),
    ADD CONSTRAINT evento_acesso_pessoa_org_fk
        FOREIGN KEY (organizacao_id, pessoa_id) REFERENCES pessoa (organizacao_id, id),
    ADD CONSTRAINT evento_acesso_vinculo_org_fk
        FOREIGN KEY (organizacao_id, vinculo_id) REFERENCES vinculo (organizacao_id, id),
    ADD CONSTRAINT evento_acesso_perfil_org_fk
        FOREIGN KEY (organizacao_id, perfil_id) REFERENCES perfil_vinculo (organizacao_id, id);

ALTER TABLE alerta
    ADD CONSTRAINT alerta_catraca_org_fk
        FOREIGN KEY (organizacao_id, catraca_id) REFERENCES catraca (organizacao_id, id),
    ADD CONSTRAINT alerta_pessoa_org_fk
        FOREIGN KEY (organizacao_id, pessoa_id) REFERENCES pessoa (organizacao_id, id),
    ADD CONSTRAINT alerta_evento_org_fk
        FOREIGN KEY (organizacao_id, evento_id) REFERENCES evento_acesso (organizacao_id, id);

-- -----------------------------------------------------------------------------
-- Unicidades passam a valer DENTRO da organização: a mesma pessoa (CPF) ou a
-- mesma prestadora (CNPJ) pode existir em dois clientes, como cadastros separados.
-- O hash do token da catraca continua único no sistema todo.
-- -----------------------------------------------------------------------------
DROP INDEX perfil_vinculo_nome_uk;
CREATE UNIQUE INDEX perfil_vinculo_nome_uk ON perfil_vinculo (organizacao_id, lower(nome));

ALTER TABLE empresa DROP CONSTRAINT empresa_cnpj_uk;
ALTER TABLE empresa ADD CONSTRAINT empresa_cnpj_uk UNIQUE (organizacao_id, cnpj);

ALTER TABLE pessoa DROP CONSTRAINT pessoa_cpf_uk;
ALTER TABLE pessoa ADD CONSTRAINT pessoa_cpf_uk UNIQUE (organizacao_id, cpf);

ALTER TABLE catraca DROP CONSTRAINT catraca_identificador_uk;
ALTER TABLE catraca ADD CONSTRAINT catraca_identificador_uk UNIQUE (organizacao_id, identificador_dispositivo);

ALTER TABLE termo_consentimento DROP CONSTRAINT termo_consentimento_versao_uk;
ALTER TABLE termo_consentimento
    ADD CONSTRAINT termo_consentimento_versao_uk UNIQUE (organizacao_id, finalidade, versao);

-- -----------------------------------------------------------------------------
-- Usuários: da organização (organizacao_id preenchido) ou da plataforma (NULL).
-- O e-mail continua único no sistema todo, pois o login é só por e-mail.
-- -----------------------------------------------------------------------------
ALTER TABLE usuario_sistema ADD COLUMN organizacao_id uuid;
UPDATE usuario_sistema SET organizacao_id = (SELECT id FROM organizacao ORDER BY created_at LIMIT 1);
ALTER TABLE usuario_sistema
    ALTER COLUMN organizacao_id SET DEFAULT cg_org_atual(),
    ADD CONSTRAINT usuario_sistema_organizacao_fk FOREIGN KEY (organizacao_id) REFERENCES organizacao (id);

CREATE INDEX usuario_sistema_organizacao_idx ON usuario_sistema (organizacao_id);

CREATE TRIGGER usuario_sistema_organizacao_fixa
    BEFORE UPDATE OF organizacao_id ON usuario_sistema
    FOR EACH ROW EXECUTE FUNCTION impedir_troca_organizacao();

ALTER TABLE usuario_papel DROP CONSTRAINT usuario_papel_papel_ck;
ALTER TABLE usuario_papel ADD CONSTRAINT usuario_papel_papel_ck CHECK (
    papel IN ('plataforma', 'administrador', 'cadastro_empresa', 'cadastro_pessoa', 'consulta')
);

-- O papel 'plataforma' é exclusivo de usuários sem organização, e os papéis
-- de organização são exclusivos de quem tem organização: um administrador de
-- cliente nunca consegue se promover a administrador da plataforma.
CREATE FUNCTION verificar_papel_usuario() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path FROM CURRENT AS
$$
DECLARE
    v_org uuid;
BEGIN
    SELECT organizacao_id INTO v_org FROM usuario_sistema WHERE id = NEW.usuario_id;

    IF (NEW.papel = 'plataforma') <> (v_org IS NULL) THEN
        RAISE EXCEPTION 'Papel incompatível com o tipo de usuário (plataforma x organização).'
            USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER usuario_papel_compativel
    BEFORE INSERT OR UPDATE ON usuario_papel
    FOR EACH ROW EXECUTE FUNCTION verificar_papel_usuario();

ALTER TABLE usuario_sistema ENABLE ROW LEVEL SECURITY;
CREATE POLICY isolamento_organizacao ON usuario_sistema
    USING (organizacao_id = cg_org_atual() OR id = cg_usuario_atual() OR cg_plataforma())
    WITH CHECK (organizacao_id = cg_org_atual() OR id = cg_usuario_atual() OR cg_plataforma());

ALTER TABLE usuario_papel ENABLE ROW LEVEL SECURITY;
CREATE POLICY isolamento_organizacao ON usuario_papel
    USING (EXISTS (SELECT 1 FROM usuario_sistema u WHERE u.id = usuario_id))
    WITH CHECK (EXISTS (SELECT 1 FROM usuario_sistema u WHERE u.id = usuario_id));

-- A organização em si: a própria organização se enxerga; só a plataforma altera.
ALTER TABLE organizacao ENABLE ROW LEVEL SECURITY;
CREATE POLICY isolamento_organizacao ON organizacao
    USING (id = cg_org_atual() OR cg_plataforma())
    WITH CHECK (cg_plataforma());

REVOKE DELETE ON organizacao FROM cadgestor_app;

-- -----------------------------------------------------------------------------
-- Funções com privilégio do dono (SECURITY DEFINER) para os poucos pontos em
-- que a organização ainda não é conhecida. Devolvem só identificadores.
-- -----------------------------------------------------------------------------

-- Login: descobre o usuário (e a organização dele) pelo e-mail.
CREATE FUNCTION cg_login_usuario(p_email text)
    RETURNS TABLE (usuario_id uuid, organizacao_id uuid)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path FROM CURRENT AS
$$
    SELECT u.id, u.organizacao_id FROM usuario_sistema u WHERE lower(u.email) = lower(p_email)
$$;

-- E-mail é único no sistema todo, mas o RLS só mostra os da própria organização.
CREATE FUNCTION cg_email_em_uso(p_email text, p_exceto uuid)
    RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path FROM CURRENT AS
$$
    SELECT EXISTS (
        SELECT 1 FROM usuario_sistema
         WHERE lower(email) = lower(p_email) AND id IS DISTINCT FROM p_exceto
    )
$$;

-- API: descobre a catraca (e a organização) pelo hash do token. Organização
-- desativada = dispositivos recusados.
CREATE FUNCTION cg_catraca_por_token(p_token_hash text)
    RETURNS TABLE (catraca_id uuid, organizacao_id uuid)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path FROM CURRENT AS
$$
    SELECT c.id, c.organizacao_id
      FROM catraca c JOIN organizacao o ON o.id = c.organizacao_id
     WHERE c.token_hash = p_token_hash AND o.ativo
$$;

-- Painel da plataforma: só contagens, nenhum dado pessoal.
CREATE FUNCTION cg_resumo_organizacoes()
    RETURNS TABLE (organizacao_id uuid, usuarios bigint, empresas bigint, pessoas bigint, catracas bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path FROM CURRENT AS
$$
BEGIN
    IF NOT cg_plataforma() THEN
        RAISE EXCEPTION 'Somente a plataforma pode consultar o resumo das organizações.'
            USING ERRCODE = 'insufficient_privilege';
    END IF;

    RETURN QUERY
    SELECT o.id,
           (SELECT count(*) FROM usuario_sistema u WHERE u.organizacao_id = o.id AND u.ativo),
           (SELECT count(*) FROM empresa e WHERE e.organizacao_id = o.id AND e.ativo),
           (SELECT count(*) FROM pessoa p WHERE p.organizacao_id = o.id AND p.ativo AND p.anonimizada_em IS NULL),
           (SELECT count(*) FROM catraca c WHERE c.organizacao_id = o.id AND c.ativo)
      FROM organizacao o;
END;
$$;

-- Cadastros iniciais de uma organização nova: perfis padrão e termo MODELO 1.0.
-- Idempotente. O termo é um MODELO e DEVE ser revisado pelo jurídico do cliente.
-- Base legal 'consentimento' em todos os perfis até validação do jurídico.
CREATE FUNCTION cg_provisionar_organizacao(p_org uuid)
    RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path FROM CURRENT AS
$$
DECLARE
    v_texto text :=
'TERMO DE CONSENTIMENTO PARA TRATAMENTO DE DADOS BIOMÉTRICOS (MODELO - REVISAR COM O JURÍDICO)

1. Finalidade específica: os dados biométricos (template de impressão digital e/ou template facial) serão tratados exclusivamente para identificar o(a) titular e liberar ou bloquear a passagem nas catracas de controle de acesso físico das empresas com as quais ele(a) possui vínculo.

2. Dados tratados: apenas o template biométrico (representação matemática), armazenado de forma cifrada. A imagem da digital não é armazenada. A foto facial, quando capturada, é usada somente para gerar o template e descartada em seguida.

3. Compartilhamento: os templates são enviados apenas aos dispositivos (catracas) das empresas com vínculo ativo, e a nenhum outro terceiro.

4. Retenção: os dados biométricos são excluídos em até 30 dias após o término do vínculo, ou imediatamente após a revogação deste consentimento.

5. Direitos do titular: confirmar a existência do tratamento, acessar, corrigir, solicitar a exclusão e revogar este consentimento a qualquer momento, sem custo, junto à administração. A revogação não afeta a legalidade do tratamento já realizado; após ela, o acesso deverá ocorrer por meio alternativo oferecido pela empresa.

6. Menores de 18 anos: o consentimento deve ser dado por pelo menos um dos pais ou pelo responsável legal.

Declaro que li e compreendi este termo e consinto com o tratamento dos meus dados biométricos para a finalidade descrita.';
BEGIN
    -- Uma sessão de organização só pode provisionar a si mesma.
    IF cg_org_atual() IS NOT NULL AND cg_org_atual() <> p_org THEN
        RAISE EXCEPTION 'Organização fora do contexto atual.' USING ERRCODE = 'insufficient_privilege';
    END IF;

    INSERT INTO perfil_vinculo (organizacao_id, nome, descricao, base_legal, base_legal_validada)
    VALUES
        (p_org, 'Funcionário', 'Empregado da empresa (CLT, estagiário ou temporário).', 'consentimento', false),
        (p_org, 'Aluno', 'Estudante matriculado. Menores de 18 anos exigem consentimento do responsável.',
         'consentimento', false),
        (p_org, 'Faxineiro', 'Prestador de serviço de limpeza e conservação.', 'consentimento', false),
        (p_org, 'Pedreiro', 'Prestador de serviço de obras e manutenção predial.', 'consentimento', false)
    ON CONFLICT DO NOTHING;

    INSERT INTO termo_consentimento (organizacao_id, versao, finalidade, texto, hash_texto)
    VALUES (p_org, '1.0', 'controle_acesso_biometrico', v_texto,
            encode(sha256(convert_to(v_texto, 'UTF8')), 'hex'))
    ON CONFLICT DO NOTHING;
END;
$$;

REVOKE ALL ON FUNCTION cg_login_usuario(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION cg_email_em_uso(text, uuid) FROM PUBLIC;
REVOKE ALL ON FUNCTION cg_catraca_por_token(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION cg_resumo_organizacoes() FROM PUBLIC;
REVOKE ALL ON FUNCTION cg_provisionar_organizacao(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION cg_login_usuario(text) TO cadgestor_app;
GRANT EXECUTE ON FUNCTION cg_email_em_uso(text, uuid) TO cadgestor_app;
GRANT EXECUTE ON FUNCTION cg_catraca_por_token(text) TO cadgestor_app;
GRANT EXECUTE ON FUNCTION cg_resumo_organizacoes() TO cadgestor_app;
GRANT EXECUTE ON FUNCTION cg_provisionar_organizacao(uuid) TO cadgestor_app;
