-- Migração 010 — Fatura com contribuinte na encomenda
-- Executar via /api/migrate_010_encomenda_fatura.php

ALTER TABLE encomendas
    ADD COLUMN quer_fatura_contribuinte TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN fatura_nif VARCHAR(20) NULL DEFAULT NULL;
