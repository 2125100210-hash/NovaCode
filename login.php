<?php
// Valida el correo y contrasena del empleado o administrador antes de darle acceso al sistema
require_once "conexion.php";

if (isset($_SESSION['usuario'])) {
    header("Location: equipos.php");
    exit;
}

$errorCorreo = "";
$errorPass   = "";
$correoValor = "";

// Revisa cuando envian el formulario de inicio de sesion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'login') {
        $correo = strtolower(trim($_POST['correo'] ?? ''));
        $pass   = $_POST['password'] ?? '';
        $correoValor = $correo;

        // Busca al empleado en la base de datos por su correo
        $stmt = $pdo->prepare("SELECT id, nombre, correo, password, rol, activo FROM usuarios WHERE correo = ?");
        $stmt->execute([$correo]);
        $u = $stmt->fetch();

        // Verifica que exista la cuenta, que este activa y que la contraseña coincida
        if (!$u) {
            $errorCorreo = "El correo es incorrecto: no existe una cuenta registrada con ese correo.";
        } elseif ((int)($u['activo'] ?? 1) === 0) {
            $errorCorreo = "Esta cuenta de empleado se encuentra desactivada. Contacta a un administrador.";
        } elseif (!password_verify($pass, $u['password'])) {
            $errorPass = "La contraseña es incorrecta. El correo sí es correcto, revisa tu contraseña.";
        } else {
            // Renueva el identificador de sesion por seguridad y guarda los datos del usuario conectado
            session_regenerate_id(true);
            unset($u['password'], $u['activo']);
            $_SESSION['usuario'] = $u;
            header("Location: equipos.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso al Sistema - FutCancha</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="bg-light d-flex flex-column min-vh-100">

    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="login.php">
                <i class="fa-solid fa-futbol text-success fs-3"></i>
                <span>FutCancha Admin</span>
            </a>
        </div>
    </nav>

    <div class="container py-5 my-auto">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card feature-card p-4 p-md-5">

                    <div class="text-center mb-4">
                        <i class="fa-solid fa-futbol text-success display-4 mb-2"></i>
                        <h3 class="fw-bold text-dark">Acceso al Sistema</h3>
                    </div>

                    <form method="POST" action="login.php">
                        <input type="hidden" name="accion" value="login">
                        <div class="mb-3">
                            <label class="form-label-minimal">Correo de Empleado</label>
                            <input type="email" name="correo" value="<?= esc($correoValor) ?>" class="form-control underline-input <?= $errorCorreo ? 'is-invalid' : '' ?>" autocomplete="off" required>
                            <?php if ($errorCorreo): ?>
                                <div class="text-danger small mt-1"><i class="fa-solid fa-circle-exclamation me-1"></i><?= esc($errorCorreo) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-4">
                            <label class="form-label-minimal">Contraseña</label>
                            <div class="position-relative">
                                <input type="password" name="password" id="loginPass" class="form-control underline-input pe-5 <?= $errorPass ? 'is-invalid' : '' ?>" required <?= $errorPass ? 'autofocus' : '' ?>>
                                <button type="button" class="btn btn-sm position-absolute top-50 end-0 translate-middle-y text-secondary" onclick="verPassword('loginPass', this)" title="Mostrar u ocultar contraseña" aria-label="Mostrar u ocultar contraseña">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                            <?php if ($errorPass): ?>
                                <div class="text-danger small mt-1"><i class="fa-solid fa-circle-exclamation me-1"></i><?= esc($errorPass) ?></div>
                            <?php endif; ?>
                        </div>
                        <button type="submit" class="btn btn-brand-primary w-100 py-2 fs-6 fw-bold">
                            <i class="fa-solid fa-right-to-bracket me-1"></i> Iniciar Sesión
                        </button>
                    </form>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function verPassword(id, btn) {
            const input = document.getElementById(id);
            const mostrar = input.type === 'password';
            input.type = mostrar ? 'text' : 'password';
            btn.querySelector('i').className = mostrar ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        }
    </script>
</body>
</html>