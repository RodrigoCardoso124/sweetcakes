<?php
include_once __DIR__ . '/../models/FidelidadePontos.php';
require_once __DIR__ . '/../helpers/Auth.php';

class FidelidadeController
{
    private $db;
    private $fidelidade;

    public function __construct($db)
    {
        $this->db = $db;
        $this->fidelidade = new FidelidadePontos($db);
        $this->fidelidade->ensureSchema();
    }

    public function index()
    {
        $pid = Auth::pessoaId();
        if ($pid === null) {
            http_response_code(401);
            echo json_encode(['message' => 'Autenticação necessária']);
            return;
        }
        $pontos = $this->fidelidade->getPontos($pid);
        echo json_encode([
            'pontos' => (int) $pontos,
            'pessoas_pessoa_id' => (int) $pid,
        ]);
    }

    public function show($id)
    {
        $this->index();
    }

    public function store($data)
    {
        http_response_code(405);
        echo json_encode(['message' => 'Os pontos são atualizados pelo servidor ao criar encomendas']);
    }

    public function update($id, $data)
    {
        http_response_code(405);
        echo json_encode(['message' => 'Operação não permitida']);
    }

    public function destroy($id)
    {
        http_response_code(405);
        echo json_encode(['message' => 'Operação não permitida']);
    }
}
