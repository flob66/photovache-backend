<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

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

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    die(json_encode(["error" => $e->getMessage()]));
}

// =====================
// TABLES INITIALES
// =====================

$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nom VARCHAR(100),
    email VARCHAR(150) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    token VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS vache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(100) NOT NULL,
    nom VARCHAR(100),
    photo_avant TEXT,
    photo_arriere TEXT,
    photo_cote_gauche TEXT,
    photo_cote_droit TEXT
)
");

// =====================
// HELPERS
// =====================

function generateToken($email) {
    return hash('sha256', $email . time() . bin2hex(random_bytes(16)));
}

function getBearerToken() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    return null;
}

function requireAuth($pdo) {
    $token = getBearerToken();
    if (!$token) {
        http_response_code(401);
        echo json_encode(["error" => "Token manquant"]);
        exit;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(401);
        echo json_encode(["error" => "Token invalide"]);
        exit;
    }
    return $user;
}

function handlePhotoUpdate($newData, $oldValue, $key) {
    global $uploadDir;
    if (array_key_exists($key, $newData)) {
        if ($newData[$key] === null && $oldValue) {
            $filePath = $uploadDir . basename($oldValue);
            if (file_exists($filePath)) unlink($filePath);
        }
        return $newData[$key] ?? null;
    }
    return $oldValue;
}

// =====================
// ROUTAGE
// =====================
$request = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));

// =====================
// AUTH ROUTES
// =====================
if ($request[0] === 'auth') {
    switch ($request[1] ?? '') {

        // -------- INSCRIPTION --------
        case 'register':
            $data = json_decode(file_get_contents("php://input"), true);
            if (empty($data['email']) || empty($data['password'])) {
                http_response_code(400);
                echo json_encode(["error" => "Email et mot de passe requis"]);
                exit;
            }

            $hash = password_hash($data['password'], PASSWORD_DEFAULT);
            $token = generateToken($data['email']);

            try {
                $stmt = $pdo->prepare("INSERT INTO users (nom, email, password_hash, token) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $data['nom'] ?? null,
                    $data['email'],
                    $hash,
                    $token
                ]);
                echo json_encode(["success" => true, "token" => $token]);
            } catch (PDOException $e) {
                http_response_code(400);
                echo json_encode(["error" => "Email déjà utilisé"]);
            }
            break;

        // -------- CONNEXION --------
        case 'login':
            $data = json_decode(file_get_contents("php://input"), true);
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email=?");
            $stmt->execute([$data['email']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($data['password'], $user['password_hash'])) {
                http_response_code(401);
                echo json_encode(["error" => "Identifiants invalides"]);
                exit;
            }

            $token = generateToken($user['email']);
            $stmt = $pdo->prepare("UPDATE users SET token=? WHERE id=?");
            $stmt->execute([$token, $user['id']]);

            echo json_encode(["success" => true, "token" => $token, "user" => [
                "id" => $user['id'],
                "nom" => $user['nom'],
                "email" => $user['email']
            ]]);
            break;

        default:
            http_response_code(404);
            echo json_encode(["error" => "Route auth non trouvée"]);
    }
    exit;
}

// =====================
// VACHES ROUTES (PROTÉGÉES)
// =====================
if ($request[0] === 'vaches') {
    $user = requireAuth($pdo);

    switch ($_SERVER['REQUEST_METHOD']) {

        case 'GET':
            if (isset($request[1])) {
                $id = intval($request[1]);
                $stmt = $pdo->prepare("SELECT * FROM vache WHERE id=?");
                $stmt->execute([$id]);
            } else if (isset($_GET['numero'])) {
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
            if (empty($data['numero'])) {
                http_response_code(400);
                echo json_encode(["error" => "Le numéro de vache est obligatoire"]);
                exit;
            }

            $stmt = $pdo->prepare("INSERT INTO vache (numero, nom, photo_avant, photo_arriere, photo_cote_gauche, photo_cote_droit)
                                   VALUES (?, ?, ?, ?, ?, ?)");
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
            if (!isset($request[1])) {
                http_response_code(400);
                echo json_encode(["error" => "ID manquant"]);
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

            echo json_encode(["success" => true]);
            break;

        case 'DELETE':
            if (!isset($request[1])) {
                http_response_code(400);
                echo json_encode(["error" => "ID manquant"]);
                exit;
            }

            $id = intval($request[1]);
            $stmt = $pdo->prepare("SELECT * FROM vache WHERE id=?");
            $stmt->execute([$id]);
            $vache = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($vache) {
                foreach (['photo_avant','photo_arriere','photo_cote_gauche','photo_cote_droit'] as $field) {
                    if (!empty($vache[$field])) {
                        $filePath = $uploadDir . basename($vache[$field]);
                        if (file_exists($filePath)) unlink($filePath);
                    }
                }
            }

            $stmt = $pdo->prepare("DELETE FROM vache WHERE id=?");
            $stmt->execute([$id]);

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
?>
