<?php
header("Content-Type: application/json");

$dotenv = parse_ini_file(__DIR__.'/.env');

$host = $dotenv['DB_HOST'];
$port = $dotenv['DB_PORT'];
$dbname = $dotenv['DB_NAME'];
$user = $dotenv['DB_USER'];
$pass = $dotenv['DB_PASS'];

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    die(json_encode(["error" => $e->getMessage()]));
}

$method = $_SERVER['REQUEST_METHOD'];
$request = explode('/', trim($_SERVER['REQUEST_URI'],'/'));

if($request[0] === 'vaches') {
    switch($method) {
        case 'GET':

            if(isset($_GET['numero'])) {
                $stmt = $pdo->prepare("SELECT * FROM vache WHERE numero = ?");
                $stmt->execute([$_GET['numero']]);
                $vaches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->query("SELECT * FROM vache");
                $vaches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode($vaches);
            break;

        case 'POST':

            $data = json_decode(file_get_contents("php://input"), true);
            if(!isset($data['numero'])) {
                http_response_code(400);
                echo json_encode(["error" => "Le numéro de vache est obligatoire"]);
                exit;
            }
            $stmt = $pdo->prepare("INSERT INTO vache (numero, nom, photo_avant, photo_arriere, photo_cote_gauche, photo_cote_droit) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['numero'],
                $data['nom'] ?? null,
                $data['photo_avant'] ?? null,
                $data['photo_arriere'] ?? null,
                $data['photo_cote_gauche'] ?? null,
                $data['photo_cote_droit'] ?? null
            ]);
            echo json_encode(["success" => true, "id" => $pdo->lastInsertId()]);
            break;

        case 'PUT':

            if(!isset($request[1])) {
                http_response_code(400);
                echo json_encode(["error" => "ID manquant"]);
                exit;
            }
            $id = intval($request[1]);
            $data = json_decode(file_get_contents("php://input"), true);

            $stmt = $pdo->prepare("UPDATE vache SET numero=?, nom=?, photo_avant=?, photo_arriere=?, photo_cote_gauche=?, photo_cote_droit=? WHERE id=?");
            $stmt->execute([
                $data['numero'] ?? null,
                $data['nom'] ?? null,
                $data['photo_avant'] ?? null,
                $data['photo_arriere'] ?? null,
                $data['photo_cote_gauche'] ?? null,
                $data['photo_cote_droit'] ?? null,
                $id
            ]);
            echo json_encode(["success" => true]);
            break;

        default:
            http_response_code(405);
            echo json_encode(["error" => "Méthode non autorisée"]);
            break;
    }
} else {
    http_response_code(404);
    echo json_encode(["error" => "Route non trouvée"]);
}