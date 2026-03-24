<?php
/**
 * Script para verificar e corrigir problemas com o campo estado na tabela encomendas
 * Execute este script uma vez para verificar/corrigir a estrutura da base de dados
 */

require_once __DIR__ . '/../src/config/database.php';

$db = (new Database())->getConnection();

echo "<h2>Verificação e Correção da Tabela Encomendas</h2>";

try {
    // 1. Verificar estrutura atual
    echo "<h3>1. Estrutura atual da tabela:</h3>";
    $stmt = $db->query("DESCRIBE encomendas");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "<td>{$col['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 2. Verificar se há triggers
    echo "<h3>2. Triggers na tabela:</h3>";
    $stmt = $db->query("SHOW TRIGGERS LIKE 'encomendas'");
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($triggers)) {
        echo "<p>✅ Nenhum trigger encontrado (bom!)</p>";
    } else {
        echo "<p>⚠️ Triggers encontrados:</p>";
        echo "<pre>";
        print_r($triggers);
        echo "</pre>";
    }
    
    // 3. Verificar estrutura do ENUM do estado
    echo "<h3>3. Verificando estrutura do campo estado (ENUM):</h3>";
    $estadoColumn = null;
    foreach ($columns as $col) {
        if ($col['Field'] === 'estado') {
            $estadoColumn = $col;
            break;
        }
    }
    
    if ($estadoColumn) {
        echo "<p><strong>Tipo:</strong> {$estadoColumn['Type']}</p>";
        echo "<p><strong>Valores permitidos no ENUM:</strong></p>";
        
        // Extrair valores do ENUM
        if (preg_match("/enum\((.*)\)/i", $estadoColumn['Type'], $matches)) {
            $enumValues = str_replace("'", "", $matches[1]);
            $enumArray = explode(',', $enumValues);
            echo "<ul>";
            foreach ($enumArray as $val) {
                echo "<li>'$val'</li>";
            }
            echo "</ul>";
        }
    }
    
    if ($estadoColumn) {
        $defaultValue = $estadoColumn['Default'];
        echo "<p><strong>DEFAULT atual:</strong> " . ($defaultValue !== null ? "'$defaultValue'" : 'NULL') . "</p>";
        
        if ($defaultValue === 'pendente' || $defaultValue !== null) {
            echo "<p>⚠️ O campo 'estado' tem DEFAULT: '$defaultValue'</p>";
            echo "<p>Isso pode estar causando problemas. Vamos remover o DEFAULT:</p>";
            
            // Remover DEFAULT - tentar diferentes sintaxes dependendo da versão do MySQL
            $removido = false;
            
            // Método 1: ALTER COLUMN ... DROP DEFAULT (MySQL 8.0+)
            try {
                $db->exec("ALTER TABLE encomendas ALTER COLUMN estado DROP DEFAULT");
                echo "<p>✅ DEFAULT removido com sucesso usando 'ALTER COLUMN ... DROP DEFAULT'!</p>";
                $removido = true;
            } catch (Exception $e1) {
                echo "<p>⚠️ Método 1 falhou: " . $e1->getMessage() . "</p>";
                
                // Método 2: MODIFY COLUMN sem DEFAULT (versões mais antigas)
                try {
                    $tipo = $estadoColumn['Type'];
                    $null = $estadoColumn['Null'] === 'YES' ? 'NULL' : 'NOT NULL';
                    $db->exec("ALTER TABLE encomendas MODIFY COLUMN estado $tipo $null");
                    echo "<p>✅ DEFAULT removido com sucesso usando 'MODIFY COLUMN'!</p>";
                    $removido = true;
                } catch (Exception $e2) {
                    echo "<p>❌ Método 2 também falhou: " . $e2->getMessage() . "</p>";
                    echo "<p><strong>Execute manualmente no phpMyAdmin:</strong></p>";
                    echo "<pre>ALTER TABLE encomendas ALTER COLUMN estado DROP DEFAULT;</pre>";
                    echo "<p>OU</p>";
                    echo "<pre>ALTER TABLE encomendas MODIFY COLUMN estado {$estadoColumn['Type']} {$estadoColumn['Null'] === 'YES' ? 'NULL' : 'NOT NULL'};</pre>";
                }
            }
            
            if ($removido) {
                // Verificar se foi removido
                $stmt = $db->query("DESCRIBE encomendas");
                $columnsAfter = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($columnsAfter as $col) {
                    if ($col['Field'] === 'estado') {
                        if ($col['Default'] === null) {
                            echo "<p>✅ Confirmado: DEFAULT foi removido com sucesso!</p>";
                        } else {
                            echo "<p>⚠️ Ainda tem DEFAULT: '{$col['Default']}' - pode precisar de verificação manual</p>";
                        }
                        break;
                    }
                }
            }
        } else {
            echo "<p>✅ O campo 'estado' não tem DEFAULT (correto!)</p>";
        }
    }
    
    // 4. Verificar dados atuais
    echo "<h3>4. Dados atuais (últimas 10 encomendas):</h3>";
    $stmt = $db->query("SELECT encomenda_id, estado, cliente_id, total FROM encomendas ORDER BY encomenda_id DESC LIMIT 10");
    $encomendas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Estado</th><th>Cliente ID</th><th>Total</th></tr>";
    foreach ($encomendas as $enc) {
        echo "<tr>";
        echo "<td>{$enc['encomenda_id']}</td>";
        echo "<td><strong>{$enc['estado']}</strong></td>";
        echo "<td>{$enc['cliente_id']}</td>";
        echo "<td>€{$enc['total']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 5. Verificar e corrigir ENUM
    echo "<h3>5. Verificação e correção do ENUM:</h3>";
    if ($estadoColumn && preg_match("/enum\((.*)\)/i", $estadoColumn['Type'], $matches)) {
        $enumValues = str_replace("'", "", $matches[1]);
        $enumArray = array_map('trim', explode(',', $enumValues));
        
        echo "<p><strong>Valores atuais no ENUM:</strong> " . implode(', ', $enumArray) . "</p>";
        echo "<p><strong>Valores usados no código:</strong> pendente, aceite, em_preparacao, pronta, entregue, cancelada</p>";
        
        $valoresNecessarios = ['pendente', 'aceite', 'em_preparacao', 'pronta', 'entregue', 'cancelada'];
        $faltamValores = array_diff($valoresNecessarios, $enumArray);
        
        if (!empty($faltamValores)) {
            echo "<p>⚠️ <strong>PROBLEMA ENCONTRADO:</strong> Os seguintes valores não existem no ENUM: " . implode(', ', $faltamValores) . "</p>";
            echo "<p><strong>Solução:</strong> Vamos mapear os valores antigos e atualizar o ENUM:</p>";
            
            try {
                // Primeiro, mapear valores antigos para os novos
                echo "<p>📝 Mapeando valores antigos...</p>";
                
                // Mapear valores antigos (com maiúsculas) para os novos (minúsculas)
                $mapeamentos = [
                    'Pendente' => 'pendente',
                    'Preparado' => 'em_preparacao',
                    'Concluida' => 'entregue',
                    'Concluída' => 'entregue',
                ];
                
                // Mapear valores que começam com 'Cancel'
                $stmt = $db->query("SELECT DISTINCT estado FROM encomendas WHERE estado LIKE 'Cancel%'");
                $cancelados = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($cancelados)) {
                    foreach ($cancelados as $cancel) {
                        $mapeamentos[$cancel] = 'cancelada';
                    }
                }
                
                foreach ($mapeamentos as $antigo => $novo) {
                    try {
                        $stmt = $db->prepare("UPDATE encomendas SET estado = ? WHERE estado = ?");
                        $stmt->execute([$novo, $antigo]);
                        $count = $stmt->rowCount();
                        if ($count > 0) {
                            echo "<p>   ✅ Mapeado '$antigo' → '$novo' ($count registos)</p>";
                        }
                    } catch (Exception $e) {
                        echo "<p>   ⚠️ Erro ao mapear '$antigo': " . $e->getMessage() . "</p>";
                    }
                }
                
                // Agora atualizar o ENUM
                echo "<p>📝 Atualizando ENUM...</p>";
                $novoEnum = "ENUM('pendente', 'aceite', 'em_preparacao', 'pronta', 'entregue', 'cancelada')";
                $db->exec("ALTER TABLE encomendas MODIFY COLUMN estado $novoEnum NOT NULL");
                echo "<p>✅ ENUM atualizado com sucesso!</p>";
                
                // Verificar se foi atualizado
                $stmt = $db->query("DESCRIBE encomendas");
                $columnsAfter = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($columnsAfter as $col) {
                    if ($col['Field'] === 'estado') {
                        echo "<p>✅ Novo tipo: {$col['Type']}</p>";
                        // Tentar remover DEFAULT se ainda existir
                        if ($col['Default'] !== null) {
                            try {
                                $db->exec("ALTER TABLE encomendas ALTER COLUMN estado DROP DEFAULT");
                                echo "<p>✅ DEFAULT removido!</p>";
                            } catch (Exception $e2) {
                                echo "<p>⚠️ Não foi possível remover DEFAULT automaticamente. Execute: ALTER TABLE encomendas ALTER COLUMN estado DROP DEFAULT;</p>";
                            }
                        }
                        break;
                    }
                }
            } catch (Exception $e) {
                echo "<p>❌ Erro ao atualizar ENUM: " . $e->getMessage() . "</p>";
                echo "<p><strong>Execute manualmente no phpMyAdmin:</strong></p>";
                echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ddd;'>";
                echo "-- Primeiro mapeie os valores:\n";
                echo "UPDATE encomendas SET estado = 'pendente' WHERE estado = 'Pendente';\n";
                echo "UPDATE encomendas SET estado = 'em_preparacao' WHERE estado = 'Preparado';\n";
                echo "UPDATE encomendas SET estado = 'entregue' WHERE estado = 'Concluida';\n";
                echo "UPDATE encomendas SET estado = 'cancelada' WHERE estado LIKE 'Cancel%';\n\n";
                echo "-- Depois atualize o ENUM:\n";
                echo "ALTER TABLE encomendas MODIFY COLUMN estado ENUM('pendente', 'aceite', 'em_preparacao', 'pronta', 'entregue', 'cancelada') NOT NULL;\n";
                echo "</pre>";
            }
        } else {
            echo "<p>✅ Todos os valores necessários estão no ENUM!</p>";
        }
    }
    
    echo "<h3>✅ Verificação concluída!</h3>";
    echo "<p>Se o problema persistir, verifique os logs do PHP para ver o que está acontecendo.</p>";
    
} catch (Exception $e) {
    echo "<p>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
