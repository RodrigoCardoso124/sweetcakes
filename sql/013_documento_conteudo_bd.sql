-- Migração 013 — PDF na base de dados (funciona no Vercel)
-- Executar via /api/migrate_013_documento_conteudo.php

ALTER TABLE documento_ficheiros
    ADD COLUMN conteudo MEDIUMBLOB NULL COMMENT 'PDF guardado na BD (produção/Vercel)' AFTER caminho_relativo;
