<?php
require_once "conexion.php";
$usr = protegerPagina();

$mensaje = "";
$esExito = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual    = $_POST['actual'] ?? '';
    $nueva     = $_POST['nueva'] ?? '';
    $confirmar = $_POST['confirmar'] ?? '';

    $q = $pdo->prepare("SELECT password FROM usuarios WHERE id = ?");
    $q->execute([$usr['id']]);
    $u = $q->fetch();

    // Valida que la clave actual este bien escrita, que la nueva tenga minimo 6 letras/numeros
    // y que las dos cajas de la nueva contrasena coincidan
    if (!$u || !password_verify($actual, $u['password'])) {
        $mensaje = "La contraseña actual es incorrecta.";
    } elseif (strlen($nueva) < 6) {
        $mensaje = "La nueva contraseña debe tener al menos 6 caracteres.";
    } elseif ($nueva !== $confirmar) {
        $mensaje = "Las contraseñas nuevas no coinciden.";
    } elseif ($nueva === $actual) {
        $mensaje = "La nueva contraseña debe ser diferente a la actual.";
    } else {
        // Guarda la nueva contrasena encriptada en la base de datos
        $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?")
            ->execute([password_hash($nueva, PASSWORD_DEFAULT), $usr['id']]);
        $mensaje = "Contraseña actualizada correctamente.";
        $esExito = true;

        // Historial de movimientos: anota que el empleado cambio su contrasena
        registrarMovimiento('Acceso', 'Cambio de contraseña', "El empleado {$usr['nombre']} cambió su contraseña.");
    }
}

fcHead("Cambiar contraseña");
?>
<body class="bg-light es-empleado">
<?php fcNavbar($usr, 'cambiar_password.php'); ?>
<?php fcHero("Cambiar Contraseña", "Actualiza la contraseña actual de tu cuenta"); ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card feature-card p-4 p-md-5">
                <?= fcAlerta($mensaje, $esExito) ?>
                <form method="POST" action="cambiar_password.php" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label-minimal">Contraseña actual</label>
                        <div class="position-relative"><input type="password" name="actual" id="pAct" class="form-control underline-input pe-5" required><button type="button" class="btn btn-sm position-absolute top-50 end-0 translate-middle-y text-secondary" onclick="verPassword('pAct', this)" aria-label="Mostrar u ocultar contraseña"><i class="fa-solid fa-eye"></i></button></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Nueva contraseña (mínimo 6 caracteres)</label>
                        <div class="position-relative"><input type="password" name="nueva" id="pNue" class="form-control underline-input pe-5" required><button type="button" class="btn btn-sm position-absolute top-50 end-0 translate-middle-y text-secondary" onclick="verPassword('pNue', this)" aria-label="Mostrar u ocultar contraseña"><i class="fa-solid fa-eye"></i></button></div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label-minimal">Confirmar nueva contraseña</label>
                        <div class="position-relative"><input type="password" name="confirmar" id="pCon" class="form-control underline-input pe-5" required><button type="button" class="btn btn-sm position-absolute top-50 end-0 translate-middle-y text-secondary" onclick="verPassword('pCon', this)" aria-label="Mostrar u ocultar contraseña"><i class="fa-solid fa-eye"></i></button></div>
                    </div>
                    <button type="submit" class="btn btn-brand-primary w-100"><i class="fa-solid fa-key me-1"></i> Guardar contraseña</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
    function verPassword(id, btn) {
        const input = document.getElementById(id);
        const mostrar = input.type === 'password';
        input.type = mostrar ? 'text' : 'password';
        btn.querySelector('i').className = mostrar ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    }
</script>
<?php fcPie(); ?>