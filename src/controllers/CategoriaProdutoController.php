<?php

include_once __DIR__ . '/../models/CategoriaProduto.php';
require_once __DIR__ . '/../helpers/Auth.php';

class CategoriaProdutoController
{
    private PDO $db;
    private CategoriaProduto $categoria;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        CategoriaProduto::ensureSchema($db);
        $this->categoria = new CategoriaProduto($db);
    }

    public function index(): void
    {
        $apenasAtivas = !Auth::isAdmin() && !Auth::isFuncionario();
        if (isset($_GET['ativo']) && $_GET['ativo'] === '0' && (Auth::isAdmin() || Auth::isFuncionario())) {
            $apenasAtivas = false;
        }
        $stmt = $this->categoria->getAll($apenasAtivas);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function show($id): void
    {
        $this->categoria->categoria_id = (int) $id;
        $row = $this->categoria->getById()->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['message' => 'Categoria não encontrada']);

            return;
        }
        echo json_encode($row);
    }

    public function store($data): void
    {
        if (!is_array($data)) {
            $data = [];
        }
        $nome = trim((string) ($data['nome'] ?? ''));
        if ($nome === '') {
            http_response_code(400);
            echo json_encode(['message' => 'Nome da categoria é obrigatório']);

            return;
        }
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = CategoriaProduto::slugify($nome);
        }
        $this->categoria->nome = $nome;
        $this->categoria->slug = $slug;
        $this->categoria->ordem = (int) ($data['ordem'] ?? 0);
        $this->categoria->ativo = isset($data['ativo']) ? ((int) $data['ativo'] ? 1 : 0) : 1;

        if ($this->categoria->create()) {
            http_response_code(201);
            echo json_encode([
                'message' => 'Categoria criada',
                'categoria_id' => (int) $this->db->lastInsertId(),
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao criar categoria']);
        }
    }

    public function update($id, $data): void
    {
        if (!is_array($data)) {
            $data = [];
        }
        $this->categoria->categoria_id = (int) $id;
        $atual = $this->categoria->getById()->fetch(PDO::FETCH_ASSOC);
        if (!$atual) {
            http_response_code(404);
            echo json_encode(['message' => 'Categoria não encontrada']);

            return;
        }
        $nome = trim((string) ($data['nome'] ?? $atual['nome']));
        $slug = trim((string) ($data['slug'] ?? $atual['slug']));
        if ($slug === '') {
            $slug = CategoriaProduto::slugify($nome);
        }
        $this->categoria->nome = $nome;
        $this->categoria->slug = $slug;
        $this->categoria->ordem = (int) ($data['ordem'] ?? $atual['ordem']);
        $this->categoria->ativo = isset($data['ativo'])
            ? ((int) $data['ativo'] ? 1 : 0)
            : (int) $atual['ativo'];

        if ($this->categoria->update()) {
            echo json_encode(['message' => 'Categoria atualizada']);
        } else {
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao atualizar categoria']);
        }
    }

    public function destroy($id): void
    {
        $this->categoria->categoria_id = (int) $id;
        if ($this->categoria->delete()) {
            echo json_encode(['message' => 'Categoria removida']);
        } else {
            http_response_code(400);
            echo json_encode([
                'message' => 'Não é possível apagar: existem produtos nesta categoria',
            ]);
        }
    }
}
