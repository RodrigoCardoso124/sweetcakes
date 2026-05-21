-- =============================================================================
-- Sweet Cakes — Limpar TODOS os dados (base quase vazia)
-- =============================================================================
-- Executar NO phpMyAdmin, UMA vez, com BACKUP feito antes.
--
-- APAGA TUDO: clientes, encomendas, produtos, faturas, funcionários,
--             utilizadores, pessoas, configuração de faturação, etc.
--
-- Depois:
--   1) Opcional: executar 015_criar_admin_inicial.sql para voltar a ter login
--   2) Inserir dados reais pelo painel ou com SQL que fores preparando
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
-- Workbench: evita erro 1175 (safe update mode) em DELETE sem WHERE
SET SQL_SAFE_UPDATES = 0;

-- --- Documentos e faturação (completo) ---
TRUNCATE TABLE documento_ficheiros;
TRUNCATE TABLE fatura_linhas;
TRUNCATE TABLE faturas_emitidas;
TRUNCATE TABLE faturas_recebidas;
TRUNCATE TABLE faturacao_config;
TRUNCATE TABLE fatura_series;

-- Série FT por defeito (estrutura mínima)
INSERT INTO fatura_series (codigo, descricao, proximo_numero, activa)
VALUES ('FT', 'Fatura', 1, 1);

-- Config empresa vazia (preenche em Faturação → Dados empresa)
INSERT INTO faturacao_config (config_key, config_value) VALUES
('nome', 'Sweet Cakes'),
('nif', ''),
('morada', ''),
('email', ''),
('taxa_iva_padrao', '23');

-- --- Encomendas ---
TRUNCATE TABLE encomenda_detalhes;
TRUNCATE TABLE encomendas;

-- --- Vendas ---
TRUNCATE TABLE produtos_vendidos;
TRUNCATE TABLE vendas;

-- --- Produção, stock, receitas ---
TRUNCATE TABLE producao_log;
TRUNCATE TABLE pedidos_ingrediente;
TRUNCATE TABLE ingrediente_preco_historico;
TRUNCATE TABLE receita_ingredientes;
TRUNCATE TABLE receitas;
TRUNCATE TABLE produto_ingrediente;

-- --- Despesas e catálogo ---
TRUNCATE TABLE despesas;
TRUNCATE TABLE promocao_uso;
TRUNCATE TABLE promocoes;
TRUNCATE TABLE produtos;
TRUNCATE TABLE ingredientes;
TRUNCATE TABLE fornecedores;

-- --- Fidelidade / auditoria ---
TRUNCATE TABLE fidelidade_pontos;
TRUNCATE TABLE audit_log;

-- --- Pessoas, funcionários e logins (TUDO) ---
TRUNCATE TABLE funcionarios;
TRUNCATE TABLE utilizadores;
TRUNCATE TABLE pessoas;

SET FOREIGN_KEY_CHECKS = 1;
SET SQL_SAFE_UPDATES = 1;

-- =============================================================================
-- ATENÇÃO: já não consegues entrar no painel até criares um funcionário.
-- Corre a seguir: 015_criar_admin_inicial.sql (ou cria manualmente no phpMyAdmin).
-- =============================================================================
