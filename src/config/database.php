<?php
$databaseConfig = [
    // Atualizado para ler as variáveis da Vercel de forma mais segura
    'host'     => $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'localhost',
    'db_name'  => $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'sweet_cakes',
    'username' => $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: '',
    'port'     => $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: '3306'
];

$localFile = __DIR__ . '/database.local.php';
if (file_exists($localFile)) {
    $local = require $localFile;
    $databaseConfig = array_merge($databaseConfig, $local);
}

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    public $conn;

    public function __construct(array $config = []) {
        global $databaseConfig;
        $cfg = !empty($config) ? $config : $databaseConfig;

        $this->host     = $cfg['host'];
        $this->db_name  = $cfg['db_name'];
        $this->username = $cfg['username'];
        $this->password = $cfg['password'];
        $this->port     = $cfg['port'];
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ];
            
            $this->conn = new PDO(
                "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4",
                $this->username,
                $this->password,
                $options
            );
        } catch(PDOException $exception) {
            // ISTO É A MAGIA: Envia o erro real para os Logs da Vercel para não termos de adivinhar!
            error_log("ERRO CRÍTICO NA BASE DE DADOS (AIVEN): " . $exception->getMessage());
            error_log("Tentou conectar a: {$this->host} na porta {$this->port} com user {$this->username}");
        }
        return $this->conn;
    }
}