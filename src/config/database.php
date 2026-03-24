<?php
/**
 * Ligação à base de dados.
 * Em local (XAMPP): usa os valores por defeito abaixo.
 * No servidor: cria o ficheiro database.local.php nesta pasta com:
 *   return [
 *     'host'     => 'nome_do_servidor_mysql',
 *     'db_name'  => 'nome_da_base_dados',
 *     'username' => 'utilizador',
 *     'password' => 'password',
 *   ];
 * Não faças commit do database.local.php (está no .gitignore).
 */
$databaseConfig = [
    'host'     => 'localhost',
    'db_name'  => 'sweet_cakes',
    'username' => 'root',
    'password' => '',
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
    public $conn;

    public function __construct(array $config = []) {
        $this->host     = $config['host'] ?? 'localhost';
        $this->db_name  = $config['db_name'] ?? 'sweet_cakes';
        $this->username = $config['username'] ?? 'root';
        $this->password = $config['password'] ?? '';
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4",
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

