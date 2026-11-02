<?php
// activa o desactiva cuentas y restablece contraseñas
require_once "conexion.php";

// Permisos de administrador
$usr = protegerAdmin();
$miId = (int)$usr['id'];

$csrfToken = fcCsrfToken();

$mensaje = $_SESSION['flash_mensaje'] ?? "";
$esExito = $_SESSION['flash_exito'] ?? false;
unset($_SESSION['flash_mensaje'], $_SESSION['flash_exito']);

function redirigirConMensaje(string $msg, bool $exito = false): void
{
    $_SESSION['flash_mensaje'] = $msg;
    $_SESSION['flash_exito'] = $exito;
    header("Location: empleados.php");
    exit;
}

function contarAdminsActivos(PDO $pdo): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'Administrador' AND activo = 1")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    // 1. Alta de un empleado o administrador nuevo
    if ($accion === 'crear') {
        $nombre     = trim($_POST['nombre'] ?? '');
        $correo     = strtolower(trim($_POST['correo'] ?? ''));
        $telefono   = preg_replace('/\D/', '', $_POST['telefono'] ?? '');
        $rol        = $_POST['rol'] ?? 'Empleado';
        $pass       = $_POST['password'] ?? '';

        if ($nombre === "") {
            redirigirConMensaje("Escribe el nombre del empleado.");
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            redirigirConMensaje("Escribe un correo electrónico válido.");
        } elseif (strlen($telefono) !== 10) {
            redirigirConMensaje("El teléfono debe tener 10 dígitos.");
        } elseif (!in_array($rol, ['Empleado', 'Administrador'], true)) {
            redirigirConMensaje("Rol no válido.");
        } elseif (strlen($pass) < 6) {
            redirigirConMensaje("La contraseña debe tener al menos 6 caracteres.");
        }

        // Revisa que el correo no este repetido
        $c = $pdo->prepare("SELECT id FROM usuarios WHERE correo = ?");
        $c->execute([$correo]);
        if ($c->fetch()) {
            redirigirConMensaje("Ese correo ya está registrado.");
        }

        // Guarda el usuario con su contraseña encriptada
        $pdo->prepare("INSERT INTO usuarios (nombre, correo, telefono, password, rol, activo) VALUES (?, ?, ?, ?, ?, 1)")
            ->execute([$nombre, $correo, $telefono, password_hash($pass, PASSWORD_DEFAULT), $rol]);

        registrarMovimiento('Empleados', 'Alta', "Se creó al empleado $nombre ($correo) con rol $rol.");
        redirigirConMensaje("Empleado creado correctamente.", true);
    }

    $obj = null;
    if ($id > 0) {
        $q = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
        $q->execute([$id]);
        $obj = $q->fetch();
    }

    if (!$obj) {
        redirigirConMensaje("El empleado seleccionado no existe.");
    }

    // 2. Permisos de administrador
    if ($accion === 'rol') {
        $rol = $_POST['rol'] ?? '';

        if (!in_array($rol, ['Empleado', 'Administrador'], true)) {
            redirigirConMensaje("Rol no válido.");
        } elseif ($id === $miId && $rol !== 'Administrador') {
            redirigirConMensaje("No puedes quitar tu propio rol de administrador.");
        } elseif ($obj['rol'] === 'Administrador' && (int)$obj['activo'] === 1 && $rol === 'Empleado' && contarAdminsActivos($pdo) <= 1) {
            redirigirConMensaje("Debe existir al menos un administrador activo.");
        }

        $pdo->prepare("UPDATE usuarios SET rol = ? WHERE id = ?")->execute([$rol, $id]);
        registrarMovimiento('Empleados', 'Cambio de rol', "{$obj['nombre']} ahora es $rol.");
        redirigirConMensaje("Rol de {$obj['nombre']} actualizado a $rol.", true);
    }

    // 3. Activar o desactivar la cuenta de un empleado
    elseif ($accion === 'activo') {
        $nuevo = (int)$obj['activo'] === 1 ? 0 : 1;
        if ($id === $miId) {
            redirigirConMensaje("No puedes desactivar tu propia cuenta.");
        } elseif ($nuevo === 0 && $obj['rol'] === 'Administrador' && contarAdminsActivos($pdo) <= 1) {
            redirigirConMensaje("Debe existir al menos un administrador activo.");
        }

        $pdo->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$nuevo, $id]);
        $estadoTexto = $nuevo ? "activada" : "desactivada";
        registrarMovimiento('Empleados', $nuevo ? 'Activación' : 'Desactivación', "Cuenta de {$obj['nombre']} $estadoTexto.");
        redirigirConMensaje("Cuenta de {$obj['nombre']} $estadoTexto.", true);
    }

    // 4. Cambio de contraseña
    elseif ($accion === 'reset') {
        $nueva = $_POST['nueva'] ?? '';
        if (strlen($nueva) < 6) {
            redirigirConMensaje("La contraseña debe tener al menos 6 caracteres.");
        }

        $pdo->prepare("UPDATE usuarios SET password = ? WHERE id = ?")->execute([password_hash($nueva, PASSWORD_DEFAULT), $id]);
        registrarMovimiento('Empleados', 'Restablecer contraseña', "Se restableció la contraseña de {$obj['nombre']}.");
        redirigirConMensaje("Contraseña de {$obj['nombre']} restablecida.", true);
    }

    // 5. Eliminar empleado
    elseif ($accion === 'eliminar') {
        if ($id === $miId) {
            redirigirConMensaje("No puedes eliminar tu propia cuenta.");
        } elseif ($obj['rol'] === 'Administrador' && (int)$obj['activo'] === 1 && contarAdminsActivos($pdo) <= 1) {
            redirigirConMensaje("Debe existir al menos un administrador activo.");
        }

        $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);
        registrarMovimiento('Empleados', 'Eliminación', "Se eliminó al empleado {$obj['nombre']} ({$obj['correo']}).");
        redirigirConMensaje("Empleado {$obj['nombre']} eliminado.", true);
    }
}

$empleados = $pdo->query("SELECT id, nombre, correo, telefono, rol, activo, creado_en FROM usuarios ORDER BY nombre ASC")->fetchAll();

fcHead("Empleados");
?>
<body class="bg-light es-empleado">
<?php fcNavbar($usr, 'empleados.php'); ?>
<?php fcHero("Administración de Empleados", "Altas, roles, permisos de administrador, activación y contraseñas"); ?>

<div class="container py-5">
    <?= fcAlerta($mensaje, $esExito) ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3">Nuevo empleado</h5>
                <form method="POST" action="empleados.php" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
                    <input type="hidden" name="accion" value="crear">
                    <div class="mb-3">
                        <label class="form-label-minimal">Nombre completo</label>
                        <input type="text" name="nombre" class="form-control underline-input" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Correo</label>
                        <input type="email" name="correo" class="form-control underline-input" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Teléfono (10 dígitos)</label>
                        <input type="text" name="telefono" class="form-control underline-input" maxlength="10" pattern="\d{10}" title="Ingresa 10 dígitos numéricos" inputmode="numeric" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Rol</label>
                        <select name="rol" id="nuevoRol" class="form-select border-0 bg-light">
                            <option value="Empleado">Empleado</option>
                            <option value="Administrador">Administrador</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Contraseña inicial</label>
                        <div class="position-relative">
                            <input type="password" name="password" id="passNuevoEmp" class="form-control underline-input pe-5" minlength="6" autocomplete="new-password" required>
                            <button type="button" class="btn btn-sm position-absolute top-50 end-0 translate-middle-y text-secondary" onclick="verPassword('passNuevoEmp', this)" title="Mostrar u ocultar contraseña" aria-label="Mostrar u ocultar contraseña"><i class="fa-solid fa-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-brand-primary w-100"><i class="fa-solid fa-user-plus me-1"></i> Crear empleado</button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3">Empleados registrados (<?= count($empleados) ?>)</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Empleado</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($empleados as $e): $esYo = ((int)$e['id'] === $miId); ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= esc($e['nombre']) ?> <?= $esYo ? '<span class="badge bg-light text-dark border">tú</span>' : '' ?></div>
                                    <div class="small text-muted"><?= esc($e['correo']) ?> · <?= esc($e['telefono']) ?></div>
                                </td>
                                <td>
                                    <form method="POST" action="empleados.php" class="d-flex gap-1" id="formRol<?= (int)$e['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
                                        <input type="hidden" name="accion" value="rol">
                                        <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                                        <select name="rol" class="form-select form-select-sm bg-light border-0" onchange="this.form.submit()" <?= $esYo ? 'disabled' : '' ?>>
                                            <option value="Empleado" <?= $e['rol'] === 'Empleado' ? 'selected' : '' ?>>Empleado</option>
                                            <option value="Administrador" <?= $e['rol'] === 'Administrador' ? 'selected' : '' ?>>Administrador</option>
                                        </select>
                                    </form>
                                </td>
                                <td><?= (int)$e['activo'] === 1 ? '<span class="badge bg-success-subtle text-success border border-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
                                <td class="text-nowrap">
                                    <form method="POST" action="empleados.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
                                        <input type="hidden" name="accion" value="activo">
                                        <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" <?= $esYo ? 'disabled' : '' ?>><?= (int)$e['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-brand-outline" onclick='abrirReset(<?= (int)$e['id'] ?>, <?= json_encode($e['nombre'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>Contraseña</button>
                                    <form method="POST" action="empleados.php" class="d-inline" onsubmit="return confirm('¿Eliminar a este empleado?');">
                                        <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" <?= $esYo ? 'disabled' : '' ?>>Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalReset" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <form method="POST" action="empleados.php" class="modal-content" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= esc($csrfToken) ?>">
            <input type="hidden" name="accion" value="reset">
            <input type="hidden" name="id" id="resetId">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Restablecer contraseña</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted" id="resetNombre"></p>
                <label class="form-label-minimal">Nueva contraseña</label>
                <div class="position-relative">
                    <input type="password" name="nueva" id="resetNuevaPass" class="form-control underline-input pe-5" minlength="6" autocomplete="new-password" required>
                    <button type="button" class="btn btn-sm position-absolute top-50 end-0 translate-middle-y text-secondary" onclick="verPassword('resetNuevaPass', this)" title="Mostrar u ocultar contraseña" aria-label="Mostrar u ocultar contraseña"><i class="fa-solid fa-eye"></i></button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand-primary btn-sm">Guardar</button>
            </div>
        </form>
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

    function abrirReset(id, nombre) {
        document.getElementById('resetId').value = id;
        document.getElementById('resetNombre').textContent = 'Empleado: ' + nombre;
        document.getElementById('resetNuevaPass').value = '';
        document.getElementById('resetNuevaPass').type = 'password';
        new bootstrap.Modal(document.getElementById('modalReset')).show();
        setTimeout(() => document.getElementById('resetNuevaPass').focus(), 200);
    }

</script>
</body>
</html>