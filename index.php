<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

$request = explode('/', trim($_SERVER['REQUEST_URI'],'/'));

$uploadDir = __DIR__.'/uploads/';
if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

function saveBase64Image(?string $dataUrl, string $prefix): ?string {
    if(!$dataUrl) return null;
    if(!preg_match('#^data:image/(\w+);base64,#i', $dataUrl, $matches)) return null;

    $ext = strtolower($matches[1]);
    $base64 = preg_replace('#^data:image/\w+;base64,#i', '', $dataUrl);
    $binary = base64_decode($base64);

    if($binary === false) return null;

    $filename = $prefix.'_'.time().'_'.rand(1000,9999).'.'.$ext;
    file_put_contents(__DIR__.'/uploads/'.$filename, $binary);
    return 'http://localhost:8000/uploads/'.$filename;
}

if($request[0] === 'vaches') {
    switch($_SERVER['REQUEST_METHOD']) {

        case 'GET':
            if(isset($request[1])) {
                $id = intval($request[1]);
                $stmt = $pdo->prepare("SELECT * FROM vache WHERE id=?");
                $stmt->execute([$id]);
            } else if(isset($_GET['numero'])) {
                $stmt = $pdo->prepare("SELECT * FROM vache WHERE numero=?");
                $stmt->execute([$_GET['numero']]);
            } else {
                $stmt = $pdo->query("SELECT * FROM vache");
            }
            $vaches = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($vaches);
            break;

        case 'POST':
            $data = json_decode(file_get_contents("php://input"), true);
            if(!isset($data['numero'])) {
                http_response_code(400);
                echo json_encode(["error"=>"Le numéro de vache est obligatoire"]);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO vache 
                (numero, nom, photo_avant, photo_arriere, photo_cote_gauche, photo_cote_droit)
                VALUES (?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $data['numero'],
                $data['nom'] ?? null,
                saveBase64Image($data['photo_avant'], 'avant'),
                saveBase64Image($data['photo_arriere'], 'arriere'),
                saveBase64Image($data['photo_cote_gauche'], 'gauche'),
                saveBase64Image($data['photo_cote_droit'], 'droite')
            ]);

            echo json_encode(["success"=>true, "id"=>$pdo->lastInsertId()]);
            break;

        case 'PUT':
            if(!isset($request[1])) {
                http_response_code(400);
                echo json_encode(["error"=>"ID manquant"]);
                exit;
            }
            $id = intval($request[1]);
            $data = json_decode(file_get_contents("php://input"), true);

            $stmt = $pdo->prepare("UPDATE vache SET 
                numero=?, nom=?, photo_avant=?, photo_arriere=?, photo_cote_gauche=?, photo_cote_droit=?
                WHERE id=?");

            $stmt->execute([
                $data['numero'] ?? null,
                $data['nom'] ?? null,
                isset($data['photo_avant']) ? saveBase64Image($data['photo_avant'], 'avant') : null,
                isset($data['photo_arriere']) ? saveBase64Image($data['photo_arriere'], 'arriere') : null,
                isset($data['photo_cote_gauche']) ? saveBase64Image($data['photo_cote_gauche'], 'gauche') : null,
                isset($data['photo_cote_droit']) ? saveBase64Image($data['photo_cote_droit'], 'droite') : null,
                $id
            ]);

            echo json_encode(["success"=>true]);
            break;

        case 'DELETE':
            if(!isset($request[1])) {
                http_response_code(400);
                echo json_encode(["error"=>"ID manquant"]);
                exit;
            }
            $id = intval($request[1]);

            $stmt = $pdo->prepare("SELECT photo_avant, photo_arriere, photo_cote_gauche, photo_cote_droit FROM vache WHERE id=?");
            $stmt->execute([$id]);
            $vache = $stmt->fetch(PDO::FETCH_ASSOC);

            if($vache) {
                foreach (['photo_avant','photo_arriere','photo_cote_gauche','photo_cote_droit'] as $field) {
                    if(!empty($vache[$field])) {
                        $file = __DIR__ . parse_url($vache[$field], PHP_URL_PATH);
                        if(file_exists($file)) unlink($file);
                    }
                }
            }

            $stmt = $pdo->prepare("DELETE FROM vache WHERE id=?");
            $stmt->execute([$id]);

            echo json_encode(["success"=>true]);
            break;

        default:
            http_response_code(405);
            echo json_encode(["error"=>"Méthode non autorisée"]);
            break;
    }
} else {
    http_response_code(404);
    echo json_encode(["error"=>"Route non trouvée"]);
}
