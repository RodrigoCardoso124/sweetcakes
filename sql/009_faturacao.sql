-- Migração 009 — Faturação (emitidas, recebidas, IVA informativo)
-- Executar via /api/migrate_009_faturacao.php

CREATE TABLE IF NOT EXISTS fatura_series (
    serie_id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(10) NOT NULL UNIQUE,
    descricao VARCHAR(120) NULL,
    proximo_numero INT NOT NULL DEFAULT 1,
    activa TINYINT(1) NOT NULL DEFAULT 1
);

INSERT IGNORE INTO fatura_series (codigo, descricao, proximo_numero, activa)
VALUES ('FT', 'Fatura', 1, 1);

CREATE TABLE IF NOT EXISTS faturas_emitidas (
    fatura_id INT AUTO_INCREMENT PRIMARY KEY,
    serie VARCHAR(10) NOT NULL DEFAULT 'FT',
    numero INT NOT NULL,
    encomenda_id INT NULL,
    cliente_nome VARCHAR(200) NOT NULL,
    cliente_nif VARCHAR(20) NULL,
    cliente_morada VARCHAR(500) NULL,
    cliente_email VARCHAR(255) NULL,
    data_emissao DATE NOT NULL,
    estado ENUM('emitida', 'anulada') NOT NULL DEFAULT 'emitida',
    total_base DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_iva DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_com_iva DECIMAL(12,2) NOT NULL DEFAULT 0,
    notas TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_serie_num (serie, numero),
    UNIQUE KEY uk_encomenda (encomenda_id),
    INDEX idx_data (data_emissao),
    INDEX idx_estado (estado)
);

CREATE TABLE IF NOT EXISTS fatura_linhas (
    linha_id INT AUTO_INCREMENT PRIMARY KEY,
    fatura_id INT NOT NULL,
    produto_id INT NULL,
    descricao VARCHAR(255) NOT NULL,
    quantidade DECIMAL(12,4) NOT NULL DEFAULT 1,
    preco_unitario_sem_iva DECIMAL(12,4) NOT NULL,
    taxa_iva_pct DECIMAL(5,2) NOT NULL DEFAULT 23.00,
    base_linha DECIMAL(12,2) NOT NULL,
    iva_linha DECIMAL(12,2) NOT NULL,
    total_linha DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (fatura_id) REFERENCES faturas_emitidas(fatura_id) ON DELETE CASCADE,
    INDEX idx_fatura (fatura_id)
);

CREATE TABLE IF NOT EXISTS faturas_recebidas (
    recebida_id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('fornecedor', 'despesa', 'outro') NOT NULL DEFAULT 'outro',
    pedido_id INT NULL,
    despesa_id INT NULL,
    numero VARCHAR(80) NULL,
    data_documento DATE NOT NULL,
    entidade_nome VARCHAR(200) NOT NULL,
    entidade_nif VARCHAR(20) NULL,
    total_base DECIMAL(12,2) NOT NULL,
    taxa_iva_pct DECIMAL(5,2) NOT NULL DEFAULT 23.00,
    total_iva DECIMAL(12,2) NOT NULL,
    total_com_iva DECIMAL(12,2) NOT NULL,
    notas TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_data (data_documento),
    INDEX idx_tipo (tipo)
);

-- ALTER pessoas / despesas aplicados no migrate PHP (idempotente)
