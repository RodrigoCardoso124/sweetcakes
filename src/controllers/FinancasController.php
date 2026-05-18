<?php



require_once __DIR__ . '/../helpers/LucroCalculator.php';



class FinancasController

{

    private $db;



    public function __construct($db)

    {

        $this->db = $db;

    }



    public function index()

    {

        $de = LucroCalculator::parseData($_GET['de'] ?? null, date('Y-m-01'));

        $ate = LucroCalculator::parseData($_GET['ate'] ?? null, date('Y-m-d'));

        $view = trim((string) ($_GET['view'] ?? 'resumo'));



        if ($de > $ate) {

            http_response_code(400);

            echo json_encode(['message' => 'Data inicial posterior à final']);

            return;

        }



        switch ($view) {

            case 'produtos':

                echo json_encode([

                    'periodo' => ['de' => $de, 'ate' => $ate],

                    'produtos' => LucroCalculator::porProduto($this->db, $de, $ate),

                ], JSON_UNESCAPED_UNICODE);

                break;

            case 'caixa':

                echo json_encode(LucroCalculator::fluxoCaixa($this->db, $de, $ate), JSON_UNESCAPED_UNICODE);

                break;

            case 'movimentos':

                echo json_encode([

                    'periodo' => ['de' => $de, 'ate' => $ate],

                    'movimentos' => LucroCalculator::listarMovimentosDespesa($this->db, $de, $ate, 500),

                ], JSON_UNESCAPED_UNICODE);

                break;

            case 'resumo':

            default:

                echo json_encode(LucroCalculator::resumo($this->db, $de, $ate), JSON_UNESCAPED_UNICODE);

                break;

        }

    }



    public function show($id)

    {

        if ($id === 'recalcular-custos') {

            LucroCalculator::recalcularTodosCustosProdutos($this->db);

            echo json_encode(['message' => 'Custos estimados dos produtos actualizados'], JSON_UNESCAPED_UNICODE);

            return;

        }

        http_response_code(404);

        echo json_encode(['message' => 'Use ?view=resumo|produtos|caixa ou GET financas/recalcular-custos']);

    }



    public function store($data)

    {

        http_response_code(405);

        echo json_encode(['message' => 'Método não suportado']);

    }



    public function update($id, $data)

    {

        http_response_code(405);

        echo json_encode(['message' => 'Método não suportado']);

    }



    public function destroy($id)

    {

        http_response_code(405);

        echo json_encode(['message' => 'Método não suportado']);

    }

}

