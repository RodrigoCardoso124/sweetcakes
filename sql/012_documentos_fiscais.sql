-- Migração 012 — Arquivo de documentos fiscais (PDF/ficheiros)
-- Executar via /api/migrate_012_documentos.php

CREATE TABLE IF NOT EXISTS documento_ficheiros (
    ficheiro_id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_documento ENUM('emitida', 'recebida') NOT NULL,
    documento_id INT NOT NULL,
    nome_original VARCHAR(255) NOT NULL,
    caminho_relativo VARCHAR(500) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    tamanho_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    origem ENUM('upload', 'gerado') NOT NULL DEFAULT 'upload',
    criado_por INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_doc_origem (tipo_documento, documento_id, origem),
    INDEX idx_tipo (tipo_documento, documento_id),
    INDEX idx_criado (criado_em)
);
