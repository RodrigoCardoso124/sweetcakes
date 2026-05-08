-- Sweet Cakes - Security/Validation Triggers + Stored Procedures
-- Compatível com MySQL 8+ (Aiven MySQL).
-- Executar nesta ordem: 1) audit_log.sql 2) este ficheiro.

SET @create_email_unique_idx_sql = (
    SELECT IF(
        EXISTS (
            SELECT 1
              FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'pessoas'
               AND index_name = 'uq_pessoas_email'
        ),
        'SELECT 1',
        'ALTER TABLE pessoas ADD UNIQUE KEY uq_pessoas_email (email)'
    )
);
PREPARE stmt_create_email_unique_idx FROM @create_email_unique_idx_sql;
EXECUTE stmt_create_email_unique_idx;
DEALLOCATE PREPARE stmt_create_email_unique_idx;

DROP TRIGGER IF EXISTS trg_pessoas_bi_validate;
DROP TRIGGER IF EXISTS trg_pessoas_bu_validate;
DROP TRIGGER IF EXISTS trg_produtos_bi_validate;
DROP TRIGGER IF EXISTS trg_produtos_bu_validate;
DROP TRIGGER IF EXISTS trg_encomendas_bi_validate;
DROP TRIGGER IF EXISTS trg_encomendas_bu_validate;
DROP TRIGGER IF EXISTS trg_encomenda_detalhes_bi_validate;
DROP TRIGGER IF EXISTS trg_encomenda_detalhes_bu_validate;
DROP TRIGGER IF EXISTS trg_encomendas_au_audit_estado;

DROP PROCEDURE IF EXISTS sp_mudar_estado_encomenda;
DROP PROCEDURE IF EXISTS sp_promover_funcionario_admin;
DROP PROCEDURE IF EXISTS sp_rebaixar_funcionario_admin;

DELIMITER $$

CREATE TRIGGER trg_pessoas_bi_validate
BEFORE INSERT ON pessoas
FOR EACH ROW
BEGIN
    SET NEW.email = LOWER(TRIM(NEW.email));
    SET NEW.nome = TRIM(NEW.nome);
    SET NEW.telemovel = TRIM(NEW.telemovel);

    IF NEW.nome IS NULL OR CHAR_LENGTH(NEW.nome) < 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nome inválido (mínimo 2 caracteres)';
    END IF;

    IF NEW.email IS NULL
       OR NEW.email = ''
       OR NEW.email NOT REGEXP '^[^@[:space:]]+@[^@[:space:]]+\\.[^@[:space:]]+$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Email inválido';
    END IF;

    IF NEW.telemovel IS NOT NULL AND NEW.telemovel <> '' AND NEW.telemovel NOT REGEXP '^[0-9+ ]{9,20}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telemóvel inválido';
    END IF;
END$$

CREATE TRIGGER trg_pessoas_bu_validate
BEFORE UPDATE ON pessoas
FOR EACH ROW
BEGIN
    SET NEW.email = LOWER(TRIM(NEW.email));
    SET NEW.nome = TRIM(NEW.nome);
    SET NEW.telemovel = TRIM(NEW.telemovel);

    IF NEW.nome IS NULL OR CHAR_LENGTH(NEW.nome) < 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nome inválido (mínimo 2 caracteres)';
    END IF;

    IF NEW.email IS NULL
       OR NEW.email = ''
       OR NEW.email NOT REGEXP '^[^@[:space:]]+@[^@[:space:]]+\\.[^@[:space:]]+$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Email inválido';
    END IF;

    IF NEW.telemovel IS NOT NULL AND NEW.telemovel <> '' AND NEW.telemovel NOT REGEXP '^[0-9+ ]{9,20}$' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Telemóvel inválido';
    END IF;
END$$

CREATE TRIGGER trg_produtos_bi_validate
BEFORE INSERT ON produtos
FOR EACH ROW
BEGIN
    SET NEW.nome = TRIM(NEW.nome);
    SET NEW.descricao = TRIM(NEW.descricao);

    IF NEW.nome IS NULL OR CHAR_LENGTH(NEW.nome) < 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nome do produto inválido';
    END IF;

    IF NEW.preco IS NULL OR NEW.preco < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preço do produto inválido';
    END IF;
END$$

CREATE TRIGGER trg_produtos_bu_validate
BEFORE UPDATE ON produtos
FOR EACH ROW
BEGIN
    SET NEW.nome = TRIM(NEW.nome);
    SET NEW.descricao = TRIM(NEW.descricao);

    IF NEW.nome IS NULL OR CHAR_LENGTH(NEW.nome) < 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Nome do produto inválido';
    END IF;

    IF NEW.preco IS NULL OR NEW.preco < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Preço do produto inválido';
    END IF;
END$$

CREATE TRIGGER trg_encomendas_bi_validate
BEFORE INSERT ON encomendas
FOR EACH ROW
BEGIN
    IF NEW.total IS NULL OR NEW.total < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Total da encomenda inválido';
    END IF;

    IF NEW.estado NOT IN ('pendente', 'aceite', 'em_preparacao', 'pronta', 'entregue', 'cancelada') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Estado da encomenda inválido';
    END IF;
END$$

CREATE TRIGGER trg_encomendas_bu_validate
BEFORE UPDATE ON encomendas
FOR EACH ROW
BEGIN
    IF NEW.total IS NULL OR NEW.total < 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Total da encomenda inválido';
    END IF;

    IF NEW.estado NOT IN ('pendente', 'aceite', 'em_preparacao', 'pronta', 'entregue', 'cancelada') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Estado da encomenda inválido';
    END IF;

    IF OLD.estado IN ('entregue', 'cancelada') AND NEW.estado <> OLD.estado THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Estado final não pode ser alterado';
    END IF;
END$$

CREATE TRIGGER trg_encomenda_detalhes_bi_validate
BEFORE INSERT ON encomenda_detalhes
FOR EACH ROW
BEGIN
    IF NEW.quantidade IS NULL OR NEW.quantidade <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quantidade inválida no detalhe da encomenda';
    END IF;

    SET NEW.especifico = TRIM(NEW.especifico);
END$$

CREATE TRIGGER trg_encomenda_detalhes_bu_validate
BEFORE UPDATE ON encomenda_detalhes
FOR EACH ROW
BEGIN
    IF NEW.quantidade IS NULL OR NEW.quantidade <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quantidade inválida no detalhe da encomenda';
    END IF;

    SET NEW.especifico = TRIM(NEW.especifico);
END$$

CREATE TRIGGER trg_encomendas_au_audit_estado
AFTER UPDATE ON encomendas
FOR EACH ROW
BEGIN
    IF NEW.estado <> OLD.estado THEN
        INSERT INTO audit_log (action, resource_type, resource_id, actor_pessoa_id, actor_funcionario_id, meta)
        VALUES (
            'estado_alterado',
            'encomenda',
            CAST(NEW.encomenda_id AS CHAR(32)),
            NULL,
            NEW.funcionario_id,
            JSON_OBJECT('estado_antigo', OLD.estado, 'estado_novo', NEW.estado)
        );
    END IF;
END$$

CREATE PROCEDURE sp_mudar_estado_encomenda(
    IN p_encomenda_id INT,
    IN p_novo_estado VARCHAR(32),
    IN p_actor_pessoa_id INT,
    IN p_actor_funcionario_id INT
)
BEGIN
    DECLARE v_estado_atual VARCHAR(32);

    SELECT estado
      INTO v_estado_atual
      FROM encomendas
     WHERE encomenda_id = p_encomenda_id
     LIMIT 1;

    IF v_estado_atual IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Encomenda não encontrada';
    END IF;

    IF p_novo_estado NOT IN ('pendente', 'aceite', 'em_preparacao', 'pronta', 'entregue', 'cancelada') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Novo estado inválido';
    END IF;

    IF v_estado_atual IN ('entregue', 'cancelada') AND p_novo_estado <> v_estado_atual THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Encomenda em estado final não pode ser alterada';
    END IF;

    UPDATE encomendas
       SET estado = p_novo_estado
     WHERE encomenda_id = p_encomenda_id;

    INSERT INTO audit_log (action, resource_type, resource_id, actor_pessoa_id, actor_funcionario_id, meta)
    VALUES (
        'sp_mudar_estado_encomenda',
        'encomenda',
        CAST(p_encomenda_id AS CHAR(32)),
        p_actor_pessoa_id,
        p_actor_funcionario_id,
        JSON_OBJECT('estado_antigo', v_estado_atual, 'estado_novo', p_novo_estado)
    );
END$$

CREATE PROCEDURE sp_promover_funcionario_admin(
    IN p_funcionario_id INT,
    IN p_actor_funcionario_id INT
)
BEGIN
    DECLARE v_ja_admin INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_ja_admin
      FROM funcionarios
     WHERE LOWER(TRIM(cargo)) IN ('admin', 'administrador', 'administradora', 'gerente', 'gestor', 'owner', 'dono', 'ceo');

    IF v_ja_admin >= 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Já existe um administrador ativo';
    END IF;

    UPDATE funcionarios
       SET cargo = 'admin'
     WHERE funcionario_id = p_funcionario_id;

    IF ROW_COUNT() = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Funcionário não encontrado';
    END IF;

    INSERT INTO audit_log (action, resource_type, resource_id, actor_pessoa_id, actor_funcionario_id, meta)
    VALUES (
        'promover_admin',
        'funcionario',
        CAST(p_funcionario_id AS CHAR(32)),
        NULL,
        p_actor_funcionario_id,
        JSON_OBJECT('novo_cargo', 'admin')
    );
END$$

CREATE PROCEDURE sp_rebaixar_funcionario_admin(
    IN p_funcionario_id INT,
    IN p_novo_cargo VARCHAR(64),
    IN p_actor_funcionario_id INT
)
BEGIN
    DECLARE v_total_admins INT DEFAULT 0;
    DECLARE v_alvo_e_admin INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_total_admins
      FROM funcionarios
     WHERE LOWER(TRIM(cargo)) IN ('admin', 'administrador', 'administradora', 'gerente', 'gestor', 'owner', 'dono', 'ceo');

    SELECT COUNT(*)
      INTO v_alvo_e_admin
      FROM funcionarios
     WHERE funcionario_id = p_funcionario_id
       AND LOWER(TRIM(cargo)) IN ('admin', 'administrador', 'administradora', 'gerente', 'gestor', 'owner', 'dono', 'ceo');

    IF v_alvo_e_admin = 1 AND v_total_admins <= 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Não podes remover o último administrador';
    END IF;

    UPDATE funcionarios
       SET cargo = TRIM(p_novo_cargo)
     WHERE funcionario_id = p_funcionario_id;

    IF ROW_COUNT() = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Funcionário não encontrado';
    END IF;

    INSERT INTO audit_log (action, resource_type, resource_id, actor_pessoa_id, actor_funcionario_id, meta)
    VALUES (
        'rebaixar_admin',
        'funcionario',
        CAST(p_funcionario_id AS CHAR(32)),
        NULL,
        p_actor_funcionario_id,
        JSON_OBJECT('novo_cargo', TRIM(p_novo_cargo))
    );
END$$

DELIMITER ;
