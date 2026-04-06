<?php
/**
 * Endpoint para servir imagens de produtos.
 * Uso: image.php?path=uploads/produtos/nome.jpg
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");

// Verifica se o parâmetro path foi enviado
if (!isset($_GET['path']) || empty(trim($_GET['path']))) {
    http_response_code(400);
    header("Content-Type: application/json");
    echo json_encode(["error" => "Parâmetro 'path' é obrigatório"]);
    exit();
}

$path = trim($_GET['path']);

// Remove barras e diretórios perigosos
$path = str_replace(['..', '\\'], '', $path);
$path = ltrim($path, '/');

// Se for apenas o nome do ficheiro, adiciona o diretório padrão
if (!str_contains($path, '/')) {
    $path = "uploads/produtos/" . $path;
}

// Caminho absoluto do ficheiro (uploads fica na raiz do projeto, fora da pasta /api)
$imagePath = realpath(__DIR__ . '/../') . '/' . $path;

// Verifica se o ficheiro existe
if (!file_exists($imagePath) || !is_file($imagePath)) {
    http_response_code(404);
    header("Content-Type: application/json");
    echo json_encode(["error" => "Imagem não encontrada", "path_requested" => $path]);
    exit();
}

// Determina tipo MIME
$extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
$mimeTypes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

$mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';

// Cabeçalhos para servir a imagem
header("Content-Type: $mimeType");
header("Content-Length: " . filesize($imagePath));
header("Cache-Control: public, max-age=3600");

// Lê e envia o conteúdo do ficheiro
readfile($imagePath);
exit();
?>
