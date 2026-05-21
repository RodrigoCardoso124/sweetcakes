-- Sweet Cakes — catálogo completo (preçário das fotos, sem tamanhos)
-- Executar UMA VEZ no MySQL Workbench / phpMyAdmin (Aiven).
-- Se já tiveres produtos com estes nomes, apaga-os antes ou comenta as linhas duplicadas.
--
-- Campos: nome, descricao (categoria), preco, disponivel, stock, alergenios (JSON)
-- Imagens: adicionar depois no painel Produtos.

SET NAMES utf8mb4;

INSERT INTO produtos (nome, descricao, preco, disponivel, stock_atual, stock_minimo, imagem, alergenios) VALUES

-- ========== SEMIFRIOS ==========
('Semifrio de frutos silvestres', 'Semifrio', 13.50, 1, 0, 0, NULL, '["Leite","Ovo","Glúten"]'),
('Semifrio de Caramelo Salgado', 'Semifrio', 13.50, 1, 0, 0, NULL, '["Leite","Ovo","Glúten"]'),
('Semifrio de Morango', 'Semifrio', 13.50, 1, 0, 0, NULL, '["Leite","Ovo","Glúten"]'),
('Semifrio de Pistacho', 'Semifrio', 14.00, 1, 0, 0, NULL, '["Leite","Ovo","Glúten","Frutos secos"]'),

-- ========== BOLOS ==========
('3 Delícias', 'Bolo', 14.00, 1, 0, 0, NULL, '["Glúten","Leite","Ovo"]'),
('Bolo de Bolacha', 'Bolo', 13.50, 1, 0, 0, NULL, '["Glúten","Leite"]'),
('Bolo de Chocolate', 'Bolo', 14.50, 1, 0, 0, NULL, '["Glúten","Leite","Ovo"]'),
('Profiteroles', 'Bolo', 15.00, 1, 0, 0, NULL, '["Glúten","Leite","Ovo"]'),
('Bolo Floresta Negra', 'Bolo', 15.00, 1, 0, 0, NULL, '["Glúten","Leite","Ovo"]'),
('Bolo de Chantilly e Frutas', 'Bolo', 15.00, 1, 0, 0, NULL, '["Glúten","Leite","Ovo"]'),
('Bolo Red Velvet', 'Bolo', 15.00, 1, 0, 0, NULL, '["Glúten","Leite","Ovo"]'),
('Molotof', 'Bolo', 9.50, 1, 0, 0, NULL, '["Ovo"]'),
('Pudim', 'Bolo', 8.00, 1, 0, 0, NULL, '["Leite","Ovo"]'),
('Toucinho do Céu', 'Bolo', 14.50, 1, 0, 0, NULL, '["Ovo","Frutos secos"]'),
('Doce de avó', 'Bolo', 15.00, 1, 0, 0, NULL, '["Glúten","Leite","Ovo"]'),
('Banoffe', 'Bolo', 14.50, 1, 0, 0, NULL, '["Glúten","Leite"]'),

-- ========== TARTES ==========
('Tarte de Maçã', 'Tarte', 13.50, 1, 0, 0, NULL, '["Glúten","Ovo","Leite"]'),
('Tarte de Limão', 'Tarte', 14.50, 1, 0, 0, NULL, '["Glúten","Ovo","Leite"]'),
('Tarte de Coco', 'Tarte', 13.50, 1, 0, 0, NULL, '["Glúten","Ovo","Leite"]'),
('Tarte de Bom Bocado', 'Tarte', 13.50, 1, 0, 0, NULL, '["Glúten","Ovo","Leite","Frutos secos"]'),
('Tarte de Frutas', 'Tarte', 15.00, 1, 0, 0, NULL, '["Glúten","Ovo","Leite"]'),
('Tarte de D''rodrigo', 'Tarte', 14.50, 1, 0, 0, NULL, '["Glúten","Ovo","Leite"]'),
('Tarte de Noz', 'Tarte', 14.50, 1, 0, 0, NULL, '["Glúten","Ovo","Leite","Frutos secos"]'),
('Tarte de Natas', 'Tarte', 12.50, 1, 0, 0, NULL, '["Glúten","Ovo","Leite"]'),

-- ========== TORTAS ==========
('Torta de Amêndoa', 'Torta', 14.50, 1, 0, 0, NULL, '["Glúten","Ovo","Frutos secos"]'),
('Torta de Laranja', 'Torta', 13.50, 1, 0, 0, NULL, '["Glúten","Ovo"]'),
('Torta de Laranja c/ Alfarroba', 'Torta', 14.00, 1, 0, 0, NULL, '["Glúten","Ovo"]'),
('Torta de Alfarroba', 'Torta', 15.00, 1, 0, 0, NULL, '["Glúten","Ovo"]'),
('Torta de Batata-Doce', 'Torta', 14.50, 1, 0, 0, NULL, '["Glúten","Ovo"]'),
('Torta de Claras', 'Torta', 12.50, 1, 0, 0, NULL, '["Glúten","Ovo"]');

-- Total: 30 produtos (4 semifrios + 12 bolos + 8 tartes + 6 tortas)
