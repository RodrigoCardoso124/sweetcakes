-- Migração 011 — backfill de NIFs (executar via PHP para gerar dígitos de controlo válidos)
-- GET /api/migrate_011_backfill_nifs.php

-- A coluna já deve existir (migrate 009/010):
-- ALTER TABLE pessoas ADD COLUMN nif VARCHAR(20) NULL DEFAULT NULL AFTER morada;
