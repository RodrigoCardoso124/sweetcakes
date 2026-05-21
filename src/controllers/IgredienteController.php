<?php
include_once __DIR__ . "/../models/Ingredientes.php";
require_once __DIR__ . '/../helpers/stock_alert_mail.php';
class IgredienteController {
    private $db;
    private $ingrediente;

    public function __construct($db) {
        $this->db = $db;
        $this->ingrediente = new Ingredientes($db);
    }

    // ----------------------------------------------------------------

    public function index() {
        $stmt = $this->ingrediente->getAll();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // ----------------------------------------------------------------

    public function show($id) {
        $this->ingrediente->ingrediente_id = $id;
        $stmt = $this->ingrediente->getById();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            echo json_encode($data);
        } else {
            http_response_code(404);
            echo json_encode(["message" => "Ingrediente não encontrado"]);
        }
    }

    // ----------------------------------------------------------------

    public function store($data) {
        if (!isset($data['nome'], $data['quantidade_atual'], $data['unidade'], $data['quantidade_minima'])) {
            http_response_code(400);
            echo json_encode(["message" => "Todos os campos são obrigatórios"]);
            return;
        }

        $this->ingrediente->nome = $data['nome'];
        $this->ingrediente->quantidade_atual = $data['quantidade_atual'];
        $this->ingrediente->unidade = $data['unidade'];
        $this->ingrediente->quantidade_minima = $data['quantidade_minima'];

        if ($this->ingrediente->create()) {
            $newId = $this->ingrediente->lastInsertId();
            $this->ingrediente->ingrediente_id = $newId;
            $row = $this->ingrediente->getById()->fetch(PDO::FETCH_ASSOC);
            if ($row && sc_stock_is_low((float) ($row['quantidade_atual'] ?? 0), (float) ($row['quantidade_minima'] ?? 0))) {
                sc_stock_mail_notify_admins_ingredient_low(
                    $this->db,
                    (string) ($row['nome'] ?? ''),
                    (float) ($row['quantidade_atual'] ?? 0),
                    (float) ($row['quantidade_minima'] ?? 0),
                    (string) ($row['unidade'] ?? '')
                );
            }
            http_response_code(201);
            echo json_encode(["message" => "Ingrediente criado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao criar ingrediente"]);
        }
    }

    // ----------------------------------------------------------------

    public function update($id, $data) {
        $this->ingrediente->ingrediente_id = $id;
        $stmtBefore = $this->ingrediente->getById();
        $before = $stmtBefore->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            http_response_code(404);
            echo json_encode(["message" => "Ingrediente não encontrado"]);
            return;
        }

        $this->ingrediente->nome = $data['nome'] ?? null;
        $this->ingrediente->quantidade_atual = $data['quantidade_atual'] ?? null;
        $this->ingrediente->unidade = $data['unidade'] ?? null;
        $this->ingrediente->quantidade_minima = $data['quantidade_minima'] ?? null;

        if ($this->ingrediente->update()) {
            $this->ingrediente->ingrediente_id = $id;
            $after = $this->ingrediente->getById()->fetch(PDO::FETCH_ASSOC);
            if ($after && sc_ingredient_entered_low_state($before, $after)) {
                sc_stock_mail_notify_admins_ingredient_low(
                    $this->db,
                    (string) ($after['nome'] ?? ''),
                    (float) ($after['quantidade_atual'] ?? 0),
                    (float) ($after['quantidade_minima'] ?? 0),
                    (string) ($after['unidade'] ?? '')
                );
            }
            echo json_encode(["message" => "Ingrediente atualizado com sucesso"]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Erro ao atualizar ingrediente"]);
        }
    }

    // ----------------------------------------------------------------

    public function destroy($id) {
        $id = (int) $id;
        $this->ingrediente->ingrediente_id = $id;
        $stmt = $this->ingrediente->getById();
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(404);
            echo json_encode(['message' => 'Ingrediente não encontrado']);
            return;
        }

        try {
            $chk = $this->db->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            $chk->execute([':t' => 'produto_ingrediente']);
            if ((int) $chk->fetchColumn() > 0) {
                $n = $this->db->prepare(
                    'SELECT COUNT(*) FROM produto_ingrediente WHERE ingrediente_id = :id'
                );
                $n->execute([':id' => $id]);
                if ((int) $n->fetchColumn() > 0) {
                    http_response_code(400);
                    echo json_encode([
                        'message' => 'Não é possível apagar: o material está ligado a produtos. Remova-o primeiro nas fichas de produto.',
                    ]);
                    return;
                }
            }
        } catch (Throwable $e) {
            error_log('destroy ingrediente check: ' . $e->getMessage());
        }

        $this->ingrediente->ingrediente_id = $id;
        if ($this->ingrediente->delete()) {
            echo json_encode(['message' => 'Material removido com sucesso']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao remover material']);
        }
    }
}
?>
