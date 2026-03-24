<?php
/**
 * Cria um PaymentIntent no Stripe e devolve o client_secret para o Flutter.
 * O Flutter usa este secret com a chave pública para mostrar o Payment Sheet.
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit();
}

require_once __DIR__ . '/src/config/stripe_config.php';

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$amountCents = isset($input['amount_cents']) ? (int) $input['amount_cents'] : 0;
$currency = isset($input['currency']) ? preg_replace('/[^a-z]/', '', strtolower($input['currency'])) : 'eur';

if ($amountCents < 50) {
    http_response_code(400);
    echo json_encode(['error' => 'Valor mínimo é 0.50 EUR (50 cêntimos).']);
    exit();
}

if (STRIPE_SECRET_KEY === 'sk_test_XXXXXXXX') {
    http_response_code(500);
    echo json_encode([
        'error' => 'Stripe não configurado',
        'message' => 'Defina STRIPE_SECRET_KEY em sweet_cakes_api/public/src/config/stripe_config.php',
    ]);
    exit();
}

// Apenas MB Way (Portugal) e cartão – sem Link nem outros métodos
$postFields = 'amount=' . $amountCents
    . '&currency=' . $currency
    . '&payment_method_types[]=mb_way'
    . '&payment_method_types[]=card';

$ch = curl_init('https://api.stripe.com/v1/payment_intents');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_POSTFIELDS => $postFields,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro de ligação ao Stripe', 'message' => $curlError]);
    exit();
}

$data = json_decode($response, true);

if ($httpCode !== 200 || empty($data['client_secret'])) {
    http_response_code($httpCode >= 400 ? $httpCode : 500);
    echo json_encode([
        'error' => $data['error']['message'] ?? 'Erro ao criar pagamento',
        'message' => $response,
    ]);
    exit();
}

echo json_encode(['client_secret' => $data['client_secret']]);
