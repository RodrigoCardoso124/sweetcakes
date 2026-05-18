<?php
include_once __DIR__ . "/../models/Fornecedor.php";
include_once __DIR__ . "/../models/Pessoa.php";

class FornecedorController {
    private $db;
    private $fornecedor;
    private $pessoa;

    public function __construct($db) {
        $this->db = $db;
        $this->fornecedor = new Fornecedor($db);
        $this->pessoa = new Pessoa($db);
    }

    // ----------------------------------------------------------

    public function index() {
        $stmt = $this->fornecedor->getAll();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ----------------------------------------------------------

    public function show($id) {
        $this->fornecedor->fornecedor_id = $id;
        $stmt = $this->fornecedor->getById();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            echo json_encode($data);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Fornecedor não encontrado"]);
        }
    }

    // ----------------------------------------------------------
 
    public function store($data) {
        $empresa = trim((string) ($data['empresa'] ?? ''));
        if ($empresa === '') {
            http_response_code(400);
            echo json_encode(["message" => "empresa é obrigatória"]);
            return;
        }

        $pessoaId = isset($data['pessoa_id']) ? (int) $data['pessoa_id'] : 0;
        if ($pessoaId <= 0) {
            $nome = trim((string) ($data['nome_contato'] ?? $empresa));
            if ($nome === '') {
                http_response_code(400);
                echo json_encode(["message" => "nome_contato ou pessoa_id é obrigatório"]);
                return;
            }
            $this->pessoa->nome = $nome;
            $this->pessoa->email = trim((string) ($data['email'] ?? '')) ?: null;
            $this->pessoa->telemovel = trim((string) ($data['telemovel'] ?? '')) ?: null;
            $this->pessoa->morada = trim((string) ($data['morada'] ?? '')) ?: null;
            if (!$this->pessoa->create()) {
                http_response_code(500);
                echo json_encode(["message" => "Erro ao criar contacto"]);
                return;
            }
            $pessoaId = (int) $this->db->lastInsertId();
        } else {
            $this->pessoa->pessoa_id = $pessoaId;
            $stmt = $this->pessoa->getById();
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(400);
                echo json_encode(["message" => "Pessoa associada não existe"]);
                return;
            }
        }

        $this->fornecedor->empresa = $empresa;
        $this->fornecedor->pessoas_pessoa_id = $pessoaId;

        if ($this->fornecedor->create()) {
            http_response_code(201);
            echo json_encode([
                "message" => "Fornecedor criado com sucesso",
                "fornecedor_id" => (int) $this->db->lastInsertId(),
                "pessoa_id" => $pessoaId,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao criar fornecedor"]);
        }
    }

    // ----------------------------------------------------------

    public function update($id, $data) {
        $this->fornecedor->fornecedor_id = $id;

        $stmt = $this->fornecedor->getById();
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            http_response_code(404);
            echo json_encode(["message" => "Fornecedor não encontrado"]);
            return;
        }

        if (isset($data['pessoa_id'])) {
            $this->pessoa->pessoa_id = (int) $data['pessoa_id'];
            $stmtP = $this->pessoa->getById();
            if (!$stmtP->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(400);
                echo json_encode(["message" => "Pessoa associada não existe"]);
                return;
            }
        }

        $this->fornecedor->empresa = isset($data['empresa'])
            ? trim((string) $data['empresa'])
            : (string) $existing['empresa'];
        $this->fornecedor->pessoas_pessoa_id = isset($data['pessoa_id'])
            ? (int) $data['pessoa_id']
            : (int) $existing['pessoas_pessoa_id'];

        if ($this->fornecedor->update()) {
            $pid = (int) $this->fornecedor->pessoas_pessoa_id;
            if ($pid > 0 && (isset($data['nome_contato']) || isset($data['email']) || isset($data['telemovel']))) {
                $this->pessoa->pessoa_id = $pid;
                $stmtP = $this->pessoa->getById();
                $rowP = $stmtP->fetch(PDO::FETCH_ASSOC);
                if ($rowP) {
                    $this->pessoa->nome = isset($data['nome_contato']) ? trim((string) $data['nome_contato']) : $rowP['nome'];
                    $this->pessoa->email = isset($data['email']) ? (trim((string) $data['email']) ?: null) : $rowP['email'];
                    $this->pessoa->telemovel = isset($data['telemovel']) ? (trim((string) $data['telemovel']) ?: null) : $rowP['telemovel'];
                    $this->pessoa->morada = $rowP['morada'] ?? null;
                    $this->pessoa->update();
                }
            }
            echo json_encode(["message" => "Fornecedor atualizado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao atualizar fornecedor"]);
        }
    }

    // ----------------------------------------------------------

    public function destroy($id) {
        $this->fornecedor->fornecedor_id = $id;

        if ($this->fornecedor->delete()) {
            echo json_encode(["message" => "Fornecedor removido com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao remover fornecedor"]);
        }
    }
}
?>
