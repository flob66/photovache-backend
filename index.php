<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header("Content-Type: application/json");

// =====================
// CONFIG
// =====================
$host   = getenv('DB_HOST');
$port   = getenv('DB_PORT');
$dbname = getenv('DB_NAME');
$user   = getenv('DB_USER');
$pass   = getenv('DB_PASS');

$uploadDir = __DIR__.'/uploads/'; // dossier serveur si tu veux stocker certaines photos
if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    die(json_encode(["error" => $e->getMessage()]));
}

// =====================
// HELPERS
// =====================

// Met à jour une photo : supprime l'ancienne si le champ est mis à null, sinon garde la nouvelle valeur
function handlePhotoUpdate($newData, $oldValue, $key) {
    global $uploadDir;

    if(array_key_exists($key, $newData)) {
        // Si l'utilisateur supprime la photo, supprime le fichier serveur si présent
        if($newData[$key] === null && $oldValue) {
            $filePath = $uploadDir . basename($oldValue);
            if(file_exists($filePath)) unlink($filePath);
        }
        // Renvoie soit le nouveau chemin, soit null
        return $newData[$key] ?? null;
    }
    return $oldValue;
}

// =====================
// ROUTAGE
// =====================
$request = explode('/', trim($_SERVER['REQUEST_URI'],'/'));

if($request[0] === 'vaches') {
    switch($_SERVER['REQUEST_METHOD']) {

        // -----------------
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
            $vaches = array_filter($vaches, fn($v) => !empty($v['numero']));
            echo json_encode(array_values($vaches));
            break;

        // -----------------
        case 'POST':
            $data = json_decode(file_get_contents("php://input"), true);
            if(empty($data['numero'])) {
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
                $data['photo_avant'] ?? null,
                $data['photo_arriere'] ?? null,
                $data['photo_cote_gauche'] ?? null,
                $data['photo_cote_droit'] ?? null
            ]);

            echo json_encode(["success"=>true, "id"=>$pdo->lastInsertId()]);
            break;

        // -----------------
        case 'PUT':
            if(!isset($request[1])) {
                http_response_code(400);
                echo json_encode(["error"=>"ID manquant"]);
                exit;
            }

            $id = intval($request[1]);
            $data = json_decode(file_get_contents("php://input"), true);

            $stmtCheck = $pdo->prepare("SELECT * FROM vache WHERE id=?");
            $stmtCheck->execute([$id]);
            $old = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE vache SET 
                numero=?, nom=?, photo_avant=?, photo_arriere=?, photo_cote_gauche=?, photo_cote_droit=?
                WHERE id=?");

            $stmt->execute([
                $data['numero'] ?? $old['numero'],
                $data['nom'] ?? $old['nom'],
                handlePhotoUpdate($data, $old['photo_avant'], 'photo_avant'),
                handlePhotoUpdate($data, $old['photo_arriere'], 'photo_arriere'),
                handlePhotoUpdate($data, $old['photo_cote_gauche'], 'photo_cote_gauche'),
                handlePhotoUpdate($data, $old['photo_cote_droit'], 'photo_cote_droit'),
                $id
            ]);

            echo json_encode(["success"=>true]);
            break;

        // -----------------
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
                        $filePath = $uploadDir . basename($vache[$field]);
                        if(file_exists($filePath)) unlink($filePath);
                    }
                }
            }

            $stmt = $pdo->prepare("DELETE FROM vache WHERE id=?");
            $stmt->execute([$id]);

            echo json_encode(["success"=>true]);
            break;

        // -----------------
        default:
            http_response_code(405);
            echo json_encode(["error"=>"Méthode non autorisée"]);
            break;
    }
} else {
    http_response_code(404);
    echo json_encode(["error"=>"Route non trouvée"]);
}
