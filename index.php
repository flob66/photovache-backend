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
    die(json_encode(["error" => $e->getMessage()]));
}

$stmt = $pdo->query("SELECT * FROM vache");
$vaches = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($vaches);