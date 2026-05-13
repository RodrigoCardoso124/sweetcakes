-- Sistema de promocoes dinamicas configuravel pelo painel admin.
-- A app vai buscar a lista de promocoes activas (data dentro do intervalo)
-- e mostra-as no banner em carrossel.

USE sweet_cakes;

-- Tabela principal: cada linha define uma promocao
CREATE TABLE IF NOT EXISTS promocoes (
  promocao_id      INT AUTO_INCREMENT PRIMARY KEY,
  titulo           VARCHAR(100) NOT NULL,
  subtitulo        VARCHAR(255) NULL DEFAULT NULL,
  tipo             ENUM('percentual','valor_fixo','oferta','leve_pague') NOT NULL DEFAULT 'percentual',
  valor_percentual DECIMAL(5,2) NULL DEFAULT NULL,
  valor_fixo       DECIMAL(10,2) NULL DEFAULT NULL,
  leve_qtd         INT UNSIGNED NULL DEFAULT NULL,
  pague_qtd        INT UNSIGNED NULL DEFAULT NULL,
  mensagem_oferta  VARCHAR(255) NULL DEFAULT NULL,
  min_compra       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  uso_unico        TINYINT(1) NOT NULL DEFAULT 0,
  data_inicio      DATETIME NOT NULL,
  data_fim         DATETIME NOT NULL,
  criado_em        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_promocao_datas (data_inicio, data_fim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela de registo de utilizacoes (para promocoes uso_unico).
-- A unique key garante que cada utilizador so usa uma vez cada promocao.
CREATE TABLE IF NOT EXISTS promocao_uso (
  promocao_uso_id INT AUTO_INCREMENT PRIMARY KEY,
  promocao_id     INT NOT NULL,
  pessoa_id       INT NOT NULL,
  encomenda_id    INT NULL DEFAULT NULL,
  desconto        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  usado_em        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_promocao_pessoa (promocao_id, pessoa_id),
  INDEX idx_promocao_uso_pessoa (pessoa_id),
  CONSTRAINT fk_pu_promocao FOREIGN KEY (promocao_id) REFERENCES promocoes(promocao_id) ON DELETE CASCADE,
  CONSTRAINT fk_pu_pessoa   FOREIGN KEY (pessoa_id)   REFERENCES pessoas(pessoa_id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colunas opcionais na encomendas para registar promocao aplicada (sem FK
-- forte para nao falhar com dados historicos).
ALTER TABLE encomendas
  ADD COLUMN IF NOT EXISTS promocao_id INT NULL DEFAULT NULL AFTER total,
  ADD COLUMN IF NOT EXISTS desconto    DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER promocao_id;

CREATE INDEX IF NOT EXISTS idx_encomenda_promocao ON encomendas(promocao_id);

-- Exemplo de promocao inicial (descomenta para inserir).
-- INSERT INTO promocoes (titulo, subtitulo, tipo, valor_percentual, data_inicio, data_fim)
-- VALUES ('PROMOCAO', 'Em encomendas feitas hoje!', 'percentual', 15.00, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY));
