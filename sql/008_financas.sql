-- Finanças: preços de matérias-primas, histórico, despesas, snapshots em encomendas.

-- Preferir: GET /api/migrate_008_financas.php (idempotente)



-- Ingredientes: preço unitário actual (€ por unidade de stock)

-- ALTER aplicado via migrate_008_financas.php



CREATE TABLE IF NOT EXISTS ingrediente_preco_historico (

  historico_id INT AUTO_INCREMENT PRIMARY KEY,

  ingrediente_id INT NOT NULL,

  preco_unitario DECIMAL(12,4) NOT NULL DEFAULT 0,

  data_vigencia DATE NOT NULL,

  pedido_id INT NULL,

  notas VARCHAR(255) NULL,

  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_iph_ing (ingrediente_id),

  INDEX idx_iph_data (data_vigencia)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS despesas (

  despesa_id INT AUTO_INCREMENT PRIMARY KEY,

  tipo ENUM('material','embalagem','equipamento','servicos','outro') NOT NULL DEFAULT 'outro',

  descricao VARCHAR(255) NOT NULL,

  valor DECIMAL(12,2) NOT NULL,

  data_despesa DATE NOT NULL,

  ingrediente_id INT NULL,

  fornecedor_id INT NULL,

  notas TEXT NULL,

  criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_desp_data (data_despesa),

  INDEX idx_desp_tipo (tipo)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- pedidos_ingrediente: valor na compra — via migrate

-- encomenda_detalhes: preco_unitario, custo_unitario_estimado — via migrate

-- produtos: custo_estimado — via migrate

