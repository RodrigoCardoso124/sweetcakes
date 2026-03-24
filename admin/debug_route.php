<?php
/**
 * Script de debug para verificar o roteamento
 * Execute: http://localhost/pap_flutter/sweet_cakes_api/public/admin/debug_route.php
 */

header("Content-Type: text/html; charset=UTF-8");

echo "<h1>Debug de Roteamento</h1>";

// Simular a mesma lógica do index.php
$uri = explode("/", trim($_SERVER['REQUEST_URI'], "/"));

echo "<h2>URI Completa:</h2>";
echo "<pre>" . print_r($uri, true) . "</pre>";

$indexPos = array_search("index.php", $uri);

if ($indexPos === false) {
    echo "<p style='color: red;'>❌ 'index.php' não encontrado na URI</p>";
} else {
    echo "<p>✅ 'index.php' encontrado na posição: $indexPos</p>";
    
    $uri = array_slice($uri, $indexPos + 1);
    
    echo "<h2>URI após index.php:</h2>";
    echo "<pre>" . print_r($uri, true) . "</pre>";
    
    $resource = $uri[0] ?? null;
    $id = $uri[1] ?? null;
    $subResource = $uri[1] ?? null;
    
    echo "<h2>Variáveis de Roteamento:</h2>";
    echo "<ul>";
    echo "<li><strong>resource:</strong> " . ($resource ?? 'null') . "</li>";
    echo "<li><strong>id:</strong> " . ($id ?? 'null') . "</li>";
    echo "<li><strong>subResource:</strong> " . ($subResource ?? 'null') . "</li>";
    echo "</ul>";
    
    echo "<h2>Teste de Rota admin/login:</h2>";
    if ($resource === "admin" && $subResource === "login") {
        echo "<p style='color: green;'>✅ Rota 'admin/login' seria capturada corretamente!</p>";
    } else {
        echo "<p style='color: red;'>❌ Rota 'admin/login' NÃO seria capturada</p>";
        echo "<p>Condição: resource === 'admin' && subResource === 'login'</p>";
        echo "<p>Resultado: " . ($resource === "admin" ? "✅" : "❌") . " resource === 'admin'</p>";
        echo "<p>Resultado: " . ($subResource === "login" ? "✅" : "❌") . " subResource === 'login'</p>";
    }
}

echo "<hr>";
echo "<h2>Teste de Requisição Real:</h2>";
echo "<p>Para testar a rota real, faça uma requisição POST para:</p>";
echo "<code>http://localhost/pap_flutter/sweet_cakes_api/public/index.php/admin/login</code>";
echo "<p>Com o seguinte JSON no body:</p>";
echo "<pre>{\"email\": \"seu@email.com\", \"password\": \"sua_password\"}</pre>";

echo "<hr>";
echo "<h2>Teste via JavaScript:</h2>";
echo "<button onclick='testLogin()'>Testar Login via API</button>";
echo "<div id='result' style='margin-top: 20px; padding: 10px; background: #f0f0f0; border-radius: 5px;'></div>";

echo "<script>
async function testLogin() {
    const resultDiv = document.getElementById('result');
    resultDiv.innerHTML = 'A testar...';
    
    const email = prompt('Email:');
    const password = prompt('Password:');
    
    if (!email || !password) {
        resultDiv.innerHTML = '❌ Email e password são obrigatórios';
        return;
    }
    
    try {
        const response = await fetch('http://localhost/pap_flutter/sweet_cakes_api/public/index.php/admin/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ email, password })
        });
        
        const data = await response.json();
        
        resultDiv.innerHTML = '<h3>Resposta:</h3><pre>' + JSON.stringify(data, null, 2) + '</pre>';
        resultDiv.innerHTML += '<p>Status: ' + response.status + '</p>';
        
        if (response.ok && data.success) {
            resultDiv.style.background = '#d4edda';
            resultDiv.innerHTML += '<p style=\"color: green;\">✅ Login bem-sucedido!</p>';
        } else {
            resultDiv.style.background = '#f8d7da';
            resultDiv.innerHTML += '<p style=\"color: red;\">❌ Login falhou</p>';
        }
    } catch (error) {
        resultDiv.style.background = '#f8d7da';
        resultDiv.innerHTML = '<p style=\"color: red;\">❌ Erro: ' + error.message + '</p>';
    }
}
</script>";
?>

