-- Stock de produtos, receitas em lote, pedidos de matéria-prima, log de produção.
-- Preferir executar api/migrate_007_stock.php (idempotente) em vez de colar isto à mão.

CREATE TABLE IF NOT EXISTS receitas (
  receita_id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  produto_id INT NOT NULL,
  rendimento INT NOT NULL DEFAULT 1 COMMENT 'Unidades de produto produzidas por cada execução da receita',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  notas TEXT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_receita_produto FOREIGN KEY (produto_id) REFERENCES produtos(produto_id) ON DELETE CASCADE,
  INDEX idx_receitas_produto (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receita_ingredientes (
  receita_id INT NOT NULL,
  ingrediente_id INT NOT NULL,
  quantidade DECIMAL(12,4) NOT NULL COMMENT 'Quantidade consumida por cada execução completa (1 lote = rendimento unidades)',
  PRIMARY KEY (receita_id, ingrediente_id),
  CONSTRAINT fk_ri_receita FOREIGN KEY (receita_id) REFERENCES receitas(receita_id) ON DELETE CASCADE,
  CONSTRAINT fk_ri_ing FOREIGN KEY (ingrediente_id) REFERENCES ingredientes(ingrediente_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedidos_ingrediente (
  pedido_id INT AUTO_INCREMENT PRIMARY KEY,
  ingrediente_id INT NOT NULL,
  quantidade DECIMAL(12,4) NOT NULL,
  estado ENUM('pendente','recebido','cancelado') NOT NULL DEFAULT 'pendente',
  notas VARCHAR(500) NULL,
  email_fornecedor VARCHAR(255) NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pi_ing FOREIGN KEY (ingrediente_id) REFERENCES ingredientes(ingrediente_id) ON DELETE CASCADE,
  INDEX idx_pi_estado (estado),
  INDEX idx_pi_ing (ingrediente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS producao_log (
  log_id INT AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('manual_produto','receita') NOT NULL,
  receita_id INT NULL,
  produto_id INT NULL,
  quantidade_produto INT NULL,
  vezes_receita INT NULL,
  funcionario_id INT NULL,
  pessoa_id INT NOT NULL,
  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pl_tipo (tipo),
  INDEX idx_pl_criado (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
