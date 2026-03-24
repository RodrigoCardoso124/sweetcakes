<?php
/**
 * Ligação à base de dados.
 * Na Vercel: usa as Variáveis de Ambiente (getenv).
 * Em local (XAMPP): usa o fallback para 'localhost' ou o ficheiro database.local.php.
 */

// Puxa as variáveis da Vercel. Se não existirem (no teu PC local), usa os valores por defeito do XAMPP.
$databaseConfig = [
    'host'     => getenv('DB_HOST') ?: 'localhost',
    'db_name'  => getenv('DB_NAME') ?: 'sweet_cakes',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'port'     => getenv('DB_PORT') ?: '3306'
];

// A tua lógica do ficheiro local (muito bem pensada, mantivemos intacta!)
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
            // CORREÇÃO CRÍTICA: Adicionada a porta "port={$this->port}" na string do PDO!
            $this->conn = new PDO(
                "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            echo "Erro na conexão: " . $exception->getMessage();
        }
        return $this->conn;
    }
}