-- =============================================================================
-- Sweet Cakes — Criar primeiro administrador (após 014)
-- =============================================================================
-- Só executar DEPOIS de 014_limpar_dados_operacionais.sql
--
-- Login do painel:
--   Email:    claudia31cardoso@gmail.com
--   Password: Sweetcakes77.
-- =============================================================================

SET NAMES utf8mb4;

INSERT INTO pessoas (nome, email, telemovel, morada, nif)
VALUES (
  'Cláudia Cardoso',
  'claudia31cardoso@gmail.com',
  '916966491',
  'Sweetcakes pastelaria',
  NULL
);

SET @pid = LAST_INSERT_ID();

INSERT INTO utilizadores (password, pessoas_pessoa_id)
VALUES (
  '$2y$12$jsqoeZqtCW6bwGBcu29vbOrN7BGh6yks/42wTblf6cw2x31cKsyAG',
  @pid
);

INSERT INTO funcionarios (cargo, pessoas_pessoa_id, data_entrada)
VALUES ('Administrador', @pid, CURDATE());

-- Opcional: preencher dados da empresa em Faturação (podes editar no painel depois)
UPDATE faturacao_config SET config_value = 'Sweet Cakes' WHERE config_key = 'nome';
UPDATE faturacao_config SET config_value = 'Sweetcakes pastelaria' WHERE config_key = 'morada';
UPDATE faturacao_config SET config_value = 'claudia31cardoso@gmail.com' WHERE config_key = 'email';

-- =============================================================================
-- Se o email já existir (correste isto duas vezes), apaga antes:
-- DELETE f, u, p FROM pessoas p
-- LEFT JOIN utilizadores u ON u.pessoas_pessoa_id = p.pessoa_id
-- LEFT JOIN funcionarios f ON f.pessoas_pessoa_id = p.pessoa_id
-- WHERE p.email = 'claudia31cardoso@gmail.com';
-- =============================================================================
