<?php
/**
 * API principal Sweet Cakes.
 * Raiz → admin (como no projeto original no InfinityFree). Catálogo público: /landing.html
 */
function sc_debug_log(string $hypothesisId, string $location, string $message, array $data = []): void
{
    $line = [
        'sessionId' => '6bdd51',
        'runId' => 'pre-fix',
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) round(microtime(true) * 1000),
    ];
    @file_put_contents(__DIR__ . '/debug-6bdd51.log', json_encode($line, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$requestPath = $requestPath !== null ? rtrim($requestPath, '/') : '';
$hasRouteParam = isset($_GET['route']) && trim((string) $_GET['route']) !== '';

if ($requestPath === '' || $requestPath === '/') {
    header('Location: admin/login.html');
    exit();
}
if (preg_match('#index\\.php$#', $requestPath) && strpos($requestPath, 'index.php/') === false && !$hasRouteParam) {
    header('Location: admin/login.html');
    exit();
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

ob_start();

$appConfig = require __DIR__ . '../src/config/app_config.php';
define('APP_DEBUG', !empty($appConfig['app_debug']));
// #region agent log
sc_debug_log('H1', 'index.php:40', 'Configuração app carregada', [
    'app_env' => $appConfig['app_env'] ?? null,
    'app_debug' => !empty($appConfig['app_debug']),
    'has_local_app_config' => file_exists(__DIR__ . '/src/config/app_config.local.php'),
]);
// #endregion

require_once __DIR__ . '../src/helpers/Auth.php';
require_once __DIR__ . '../src/helpers/PasswordHelper.php';
require_once __DIR__ . '../src/helpers/AuditHelper.php';
Auth::setConfig($appConfig);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = $appConfig['cors_origins'] ?? [];
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
} elseif ($origin !== '' && empty($allowedOrigins)) {
    $oh = parse_url($origin, PHP_URL_HOST);
    $self = $_SERVER['HTTP_HOST'] ?? '';
    if ($oh && strcasecmp($oh, explode(':', $self)[0]) === 0) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
}

header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Id');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

header('Content-Type: application/json; charset=UTF-8');

require_once '../src/config/app_config.php';
if (!defined('SC_DEBUG_APP_ENV')) {
    define('SC_DEBUG_APP_ENV', (string) ($appConfig['app_env'] ?? ''));
}
if (!defined('SC_DEBUG_APP_DEBUG')) {
    define('SC_DEBUG_APP_DEBUG', !empty($appConfig['app_debug']) ? '1' : '0');
}
if (!defined('SC_DEBUG_DB_HOST')) {
    define('SC_DEBUG_DB_HOST', (string) ($databaseConfig['host'] ?? ''));
}
if (!defined('SC_DEBUG_DB_NAME')) {
    define('SC_DEBUG_DB_NAME', (string) ($databaseConfig['db_name'] ?? ''));
}
if (!defined('SC_DEBUG_DB_USER')) {
    define('SC_DEBUG_DB_USER', (string) ($databaseConfig['username'] ?? ''));
}
// #region agent log
sc_debug_log('H2', 'index.php:74', 'Configuração BD carregada', [
    'db_host' => $databaseConfig['host'] ?? null,
    'db_name' => $databaseConfig['db_name'] ?? null,
    'db_user' => $databaseConfig['username'] ?? null,
    'has_local_db_config' => file_exists(__DIR__ . '/src/config/database.local.php'),
]);
// #endregion

$controllerPath = __DIR__ . '/src/controllers/';
$modelPath = __DIR__ . '/src/models/';

foreach (glob($modelPath . '*.php') as $filename) {
    require_once $filename;
}
foreach (glob($controllerPath . '*.php') as $filename) {
    require_once $filename;
}

$db = (new Database($databaseConfig))->getConnection();

if ($db === null) {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(503);
    echo json_encode([
        'error' => 'Serviço indisponível',
        'message' => 'Não foi possível conectar à base de dados.',
    ]);
    exit();
}

$routeParam = isset($_GET['route']) ? trim((string) $_GET['route'], '/') : '';
if ($routeParam !== '') {
    $uri = explode('/', $routeParam);
} elseif (!empty($_SERVER['PATH_INFO'])) {
    // Alguns alojamentos (Apache) servem /index.php/encomendas com PATH_INFO em vez de ?route=
    $pi = trim((string) $_SERVER['PATH_INFO'], '/');
    $uri = $pi === '' ? [] : explode('/', $pi);
} else {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (($pos = strpos($requestUri, '?')) !== false) {
        $requestUri = substr($requestUri, 0, $pos);
    }

    $uri = explode('/', trim($requestUri, '/'));
    $indexPos = array_search('index.php', $uri, true);

    if ($indexPos === false) {
        echo json_encode(['error' => 'A rota deve começar com index.php']);
        exit();
    }

    $uri = array_slice($uri, $indexPos + 1);
}
$resource = $uri[0] ?? null;
$id = $uri[1] ?? null;
$subResource = $uri[1] ?? null;

$input = json_decode(file_get_contents('php://input'), true);
$files = $_FILES;
$httpMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$routes = [
    'pessoas' => 'PessoaController',
    'funcionarios' => 'FuncionarioController',
    'ingredientes' => 'IgredienteController',
    'produtos' => 'ProdutoController',
    'produto_ingredientes' => 'ProdutoIngredienteController',
    'vendas' => 'VendaController',
    'produtos_vendidos' => 'ProdutosVendidosController',
    'fornecedores' => 'FornecedorController',
    'encomendas' => 'EncomendaController',
    'encomenda_detalhes' => 'EncomendaDetalheController',
    'utilizadores' => 'UtilizadorController',
];

/**
 * Rotas públicas da API (sem sessão).
 */
function sc_is_public_api_route(?string $resource, string $method): bool
{
    return $resource === 'produtos' && $method === 'GET';
}

/**
 * Requer utilizador com sessão de funcionário (painel / operações internas).
 */
function sc_route_requires_admin(?string $resource, string $method): bool
{
    $adminOnly = ['pessoas', 'funcionarios', 'ingredientes', 'produto_ingredientes', 'vendas', 'produtos_vendidos', 'fornecedores', 'utilizadores'];
    if (in_array($resource, $adminOnly, true)) {
        return true;
    }
    if ($resource === 'produtos' && $method !== 'GET') {
        return true;
    }
    if ($resource === 'encomendas' && in_array($method, ['PUT', 'DELETE'], true)) {
        return true;
    }
    if ($resource === 'encomenda_detalhes') {
        if (in_array($method, ['PUT', 'DELETE'], true)) {
            return true;
        }
        if ($method === 'GET' && empty($_GET['encomenda_id'])) {
            return true;
        }
    }
    return false;
}

if ($resource === 'login' && $httpMethod === 'POST') {
    $controller = new UtilizadorController($db);
    $controller->login($input);
    exit();
}

if ($resource === 'admin' && $subResource === 'login' && $httpMethod === 'POST') {
    $controller = new UtilizadorController($db);
    $controller->adminLogin($input);
    exit();
}

if ($resource === 'logout' && $httpMethod === 'POST') {
    Auth::startSession();
    Auth::destroySession();
    echo json_encode(['success' => true, 'message' => 'Sessão terminada']);
    exit();
}

if ($resource === null || $resource === '' || !isset($routes[$resource])) {
    http_response_code(404);
    echo json_encode([
        'error' => 'Rota não encontrada',
        'hint' => 'Use /index.php/produtos, /index.php/login, etc.',
    ]);
    exit();
}

if (!sc_is_public_api_route($resource, $httpMethod)) {
    Auth::startSession();
    if (!Auth::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['message' => 'Não autenticado. Inicia sessão (login ou admin/login).']);
        exit();
    }
    if (sc_route_requires_admin($resource, $httpMethod) && !Auth::isAdmin()) {
        http_response_code(403);
        echo json_encode(['message' => 'Acesso reservado a administradores.']);
        exit();
    }
}

try {
    $controllerName = $routes[$resource];
    $controller = new $controllerName($db);

    switch ($httpMethod) {
        case 'GET':
            if ($id) {
                $controller->show($id);
            } else {
                if ($resource === 'encomenda_detalhes' && isset($_GET['encomenda_id'])) {
                    $controller->index($_GET['encomenda_id']);
                } else {
                    $controller->index();
                }
            }
            break;

        case 'POST':
            if (!empty($files)) {
                $controller->store($_POST, $files);
            } else {
                $controller->store($input);
            }
            break;

        case 'PUT':
            if (!$id) {
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                http_response_code(400);
                echo json_encode(['error' => 'ID obrigatório']);
                break;
            }
            if (!empty($files)) {
                $controller->update($id, $_POST, $files);
            } else {
                $controller->update($id, $input ?? []);
            }
            break;

        case 'DELETE':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'ID obrigatório']);
                break;
            }
            $controller->destroy($id);
            break;

        default:
            http_response_code(405);
            if (ob_get_level() > 0) {
                ob_clean();
            }
            echo json_encode(['error' => 'Método não permitido']);
    }
} catch (Exception $e) {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(500);
    $out = [
        'error' => 'Erro interno do servidor',
        'message' => APP_DEBUG ? $e->getMessage() : 'Ocorreu um erro. Tenta mais tarde.',
    ];
    if (APP_DEBUG) {
        $out['file'] = $e->getFile();
        $out['line'] = $e->getLine();
    }
    echo json_encode($out);
} catch (Error $e) {
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code(500);
    $out = [
        'error' => 'Erro fatal do servidor',
        'message' => APP_DEBUG ? $e->getMessage() : 'Ocorreu um erro. Tenta mais tarde.',
    ];
    if (APP_DEBUG) {
        $out['file'] = $e->getFile();
        $out['line'] = $e->getLine();
    }
    echo json_encode($out);
}
