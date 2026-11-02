<?php
// Zona horaria de Guadalajara: asi "hoy" y "la hora actual" coinciden con la hora real y no con la del servidor
date_default_timezone_set('America/Mexico_City');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Datos de acceso a la base de datos local en XAMPP/WAMP
$host = "localhost";
$db   = "futcancha_db";
$user = "root";
$pass = "";

// Conecta a MySQL usando PDO y activa los avisos de error por si falla alguna consulta
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// Si el usuario presiona el boton Salir, limpia y borra su sesion y lo regresa al login
if (isset($_GET['logout'])) {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
    header("Location: login.php");
    exit;
}

function protegerPagina() {
    if (!isset($_SESSION['usuario'])) {
        header("Location: login.php");
        exit;
    }
    return $_SESSION['usuario'];
}

function esc($s) {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function renderNavAuth($usr) {
    return '
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-success py-2 px-3">
                <i class="fa-solid fa-user-check me-1"></i> ' . esc($usr['nombre']) . '
            </span>
            <a href="?logout=1" class="btn btn-sm btn-outline-dark">
                <i class="fa-solid fa-right-from-bracket"></i> Salir
            </a>
        </div>
    ';
}

require_once __DIR__ . "/includes/extension.php";