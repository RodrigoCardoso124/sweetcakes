<?php
/**
 * Script de teste para verificar login de funcionário
 * Execute: http://localhost/pap_flutter/sweet_cakes_api/public/admin/test_login.php
 */

require_once __DIR__ . "/src/config/database.php";
require_once __DIR__ . "/src/models/Pessoa.php";
require_once __DIR__ . "/src/models/Utilizador.php";
require_once __DIR__ . "/src/models/Funcionario.php";

header("Content-Type: text/html; charset=UTF-8");

echo "<h1>Teste de Login de Funcionário</h1>";

try {
    $db = (new Database())->getConnection();
    
    if (!$db) {
        die("❌ Erro ao conectar à base de dados");
    }

    // Listar todos os funcionários
    $funcionario = new Funcionario($db);
    $stmt = $funcionario->getAll();
    $funcionarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h2>Funcionários na Base de Dados:</h2>";
    
    if (empty($funcionarios)) {
        echo "<p style='color: red;'>❌ Nenhum funcionário encontrado na base de dados!</p>";
    } else {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr><th>Funcionário ID</th><th>Pessoa ID</th><th>Cargo</th><th>Email</th><th>Nome</th><th>Password</th></tr>";
        
        $pessoa = new Pessoa($db);
        $utilizador = new Utilizador($db);
        
        foreach ($funcionarios as $func) {
            // Buscar pessoa
            $pessoa->pessoa_id = $func['pessoas_pessoa_id'];
            $stmt = $pessoa->getById();
            $pessoaData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Buscar utilizador
            $utilizador->pessoas_pessoa_id = $func['pessoas_pessoa_id'];
            $stmt = $utilizador->getByPessoaId();
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo "<tr>";
            echo "<td>" . $func['funcionario_id'] . "</td>";
            echo "<td>" . $func['pessoas_pessoa_id'] . "</td>";
            echo "<td>" . htmlspecialchars($func['cargo']) . "</td>";
            echo "<td>" . htmlspecialchars($pessoaData['email'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($pessoaData['nome'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($userData['password'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        echo "<hr>";
        echo "<h2>Teste de Login via API:</h2>";
        echo "<form method='POST' style='background: #f0f0f0; padding: 20px; border-radius: 8px;'>";
        echo "<p><label>Email: <input type='email' name='test_email' required></label></p>";
        echo "<p><label>Password: <input type='password' name='test_password' required></label></p>";
        echo "<p><button type='submit'>Testar Login</button></p>";
        echo "</form>";
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_email']) && isset($_POST['test_password'])) {
            echo "<hr>";
            echo "<h3>Resultado do Teste:</h3>";
            
            // Simular o adminLogin
            $testEmail = $_POST['test_email'];
            $testPassword = $_POST['test_password'];
            
            // 1. Buscar pessoa
            $pessoa->email = $testEmail;
            $stmt = $pessoa->getByEmail();
            $pessoaData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pessoaData) {
                echo "<p style='color: red;'>❌ Email não encontrado</p>";
            } else {
                echo "<p>✅ Pessoa encontrada: " . htmlspecialchars($pessoaData['nome']) . " (ID: " . $pessoaData['pessoa_id'] . ")</p>";
                
                // 2. Buscar utilizador
                $utilizador->pessoas_pessoa_id = $pessoaData['pessoa_id'];
                $stmt = $utilizador->getByPessoaId();
                $userData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$userData) {
                    echo "<p style='color: red;'>❌ Utilizador não encontrado para esta pessoa</p>";
                } else {
                    echo "<p>✅ Utilizador encontrado (ID: " . $userData['utilizador_id'] . ")</p>";
                    echo "<p>Password na BD: " . htmlspecialchars($userData['password']) . "</p>";
                    echo "<p>Password inserida: " . htmlspecialchars($testPassword) . "</p>";
                    
                    // 3. Comparar passwords
                    if ($testPassword !== $userData['password']) {
                        echo "<p style='color: red;'>❌ Password incorreta!</p>";
                        echo "<p>Comparação: '" . htmlspecialchars($testPassword) . "' !== '" . htmlspecialchars($userData['password']) . "'</p>";
                    } else {
                        echo "<p style='color: green;'>✅ Password correta!</p>";
                        
                        // 4. Verificar se é funcionário
                        $funcionario->pessoas_pessoa_id = $pessoaData['pessoa_id'];
                        $stmt = $funcionario->getByPessoaId();
                        $funcData = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$funcData) {
                            echo "<p style='color: red;'>❌ Esta pessoa NÃO é funcionário!</p>";
                        } else {
                            echo "<p style='color: green;'>✅ É funcionário! (ID: " . $funcData['funcionario_id'] . ", Cargo: " . htmlspecialchars($funcData['cargo']) . ")</p>";
                            echo "<p style='color: green; font-weight: bold;'>🎉 LOGIN DEVERIA FUNCIONAR!</p>";
                        }
                    }
                }
            }
        }
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

