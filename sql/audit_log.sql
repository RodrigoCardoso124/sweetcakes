-- Executar uma vez na base sweet_cakes (MySQL 5.7+)
CREATE TABLE IF NOT EXISTS audit_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  action VARCHAR(64) NOT NULL,
  resource_type VARCHAR(64) NOT NULL,
  resource_id VARCHAR(32) NULL,
  actor_pessoa_id INT NULL,
  actor_funcionario_id INT NULL,
  meta JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_resource (resource_type, resource_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
