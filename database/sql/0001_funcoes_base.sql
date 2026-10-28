-- =============================================================================
-- 0001 - Funções base: updated_at automático e validação de CPF/CNPJ.
--
-- A validação também existe em PHP (camada de serviço). No banco ela é a
-- última trava: mesmo um INSERT manual não grava documento inválido.
-- =============================================================================

-- Atualiza a coluna updated_at em todo UPDATE.
CREATE FUNCTION definir_updated_at() RETURNS trigger
    LANGUAGE plpgsql AS
$$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

-- CPF: 11 dígitos, sem máscara, com os dois dígitos verificadores corretos.
-- Sequências repetidas (000..., 111...) são rejeitadas.
CREATE FUNCTION cpf_valido(p_cpf text) RETURNS boolean
    LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE AS
$$
DECLARE
    v_soma  integer;
    v_resto integer;
    v_dv1   integer;
    v_dv2   integer;
BEGIN
    IF p_cpf !~ '^[0-9]{11}$' OR p_cpf ~ '^(.)\1{10}$' THEN
        RETURN false;
    END IF;

    v_soma := 0;
    FOR i IN 1..9 LOOP
        v_soma := v_soma + substr(p_cpf, i, 1)::integer * (11 - i);
    END LOOP;
    v_resto := v_soma % 11;
    v_dv1 := CASE WHEN v_resto < 2 THEN 0 ELSE 11 - v_resto END;

    v_soma := 0;
    FOR i IN 1..10 LOOP
        v_soma := v_soma + substr(p_cpf, i, 1)::integer * (12 - i);
    END LOOP;
    v_resto := v_soma % 11;
    v_dv2 := CASE WHEN v_resto < 2 THEN 0 ELSE 11 - v_resto END;

    RETURN v_dv1 = substr(p_cpf, 10, 1)::integer
       AND v_dv2 = substr(p_cpf, 11, 1)::integer;
END;
$$;

-- CNPJ: 14 posições sem máscara. Aceita o formato alfanumérico da Receita
-- Federal (vigente desde julho/2026): as 12 primeiras posições podem ter
-- letras maiúsculas; os 2 dígitos verificadores são sempre numéricos.
-- Cada caractere vale (código ASCII - 48), o que mantém o cálculo antigo
-- para CNPJs só numéricos.
CREATE FUNCTION cnpj_valido(p_cnpj text) RETURNS boolean
    LANGUAGE plpgsql IMMUTABLE STRICT PARALLEL SAFE AS
$$
DECLARE
    v_pesos1 integer[] := ARRAY[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    v_pesos2 integer[] := ARRAY[6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
    v_soma   integer;
    v_resto  integer;
    v_dv1    integer;
    v_dv2    integer;
BEGIN
    IF p_cnpj !~ '^[0-9A-Z]{12}[0-9]{2}$' OR p_cnpj ~ '^(.)\1{13}$' THEN
        RETURN false;
    END IF;

    v_soma := 0;
    FOR i IN 1..12 LOOP
        v_soma := v_soma + (ascii(substr(p_cnpj, i, 1)) - 48) * v_pesos1[i];
    END LOOP;
    v_resto := v_soma % 11;
    v_dv1 := CASE WHEN v_resto < 2 THEN 0 ELSE 11 - v_resto END;

    v_soma := 0;
    FOR i IN 1..13 LOOP
        v_soma := v_soma + (ascii(substr(p_cnpj, i, 1)) - 48) * v_pesos2[i];
    END LOOP;
    v_resto := v_soma % 11;
    v_dv2 := CASE WHEN v_resto < 2 THEN 0 ELSE 11 - v_resto END;

    RETURN v_dv1 = substr(p_cnpj, 13, 1)::integer
       AND v_dv2 = substr(p_cnpj, 14, 1)::integer;
END;
$$;
