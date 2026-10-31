<?php
require_once "conexion.php";
$usr = protegerPagina();

$mensaje = "";
$esExito = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        $nombre   = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $correo   = strtolower(trim($_POST['correo'] ?? ''));
        $estado   = $_POST['estado'] ?? 'Disponible';

        if ($nombre === "") {
            $mensaje = "Escribe el nombre del árbitro.";
        } elseif (strlen(preg_replace('/\D/', '', $telefono)) !== 10) {
            $mensaje = "El teléfono debe tener 10 dígitos.";
        } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $mensaje = "Escribe un correo electrónico válido.";
        } else {
            $check = $pdo->prepare("SELECT id FROM arbitros WHERE correo = ? OR telefono = ?");
            $check->execute([$correo, $telefono]);
            if ($check->fetch()) {
                $mensaje = "Este correo o teléfono ya está registrado para otro árbitro.";
            } else {
                // Si subieron fotografia, la valida y la guarda en uploads/arbitros
                $rutaFoto = fcGuardarImagen('foto', 'uploads/arbitros', 'arb_', '', $errorFoto);
                if ($errorFoto !== '') {
                    $mensaje = $errorFoto;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO arbitros (nombre, telefono, correo, estado, foto, registrado_por) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$nombre, $telefono, $correo, $estado, $rutaFoto, $usr['nombre']]);
                    $mensaje = "Árbitro registrado correctamente.";
                    $esExito = true;
                }
            }
        }
    }

    if ($accion === 'editar') {
        $id       = intval($_POST['id'] ?? 0);
        $nombre   = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $correo   = strtolower(trim($_POST['correo'] ?? ''));
        $estado   = trim($_POST['estado'] ?? '');

        if ($nombre === "" or strlen(preg_replace('/\D/', '', $telefono)) !== 10 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $mensaje = "Verifica que nombre, teléfono (10 dígitos) y correo sean válidos.";
        } else {
            $check = $pdo->prepare("SELECT id FROM arbitros WHERE id != ? AND (correo = ? OR telefono = ?)");
            $check->execute([$id, $correo, $telefono]);
            if ($check->fetch()) {
                $mensaje = "Ese correo o teléfono ya pertenece a otro árbitro.";
            } else {
                // Si subieron una foto nueva, reemplaza la anterior
                $qFoto = $pdo->prepare("SELECT foto FROM arbitros WHERE id = ?");
                $qFoto->execute([$id]);
                $fotoActual = (string)$qFoto->fetchColumn();
                $rutaFoto = fcGuardarImagen('foto', 'uploads/arbitros', 'arb_', $fotoActual, $errorFoto);
                if ($errorFoto !== '') {
                    $mensaje = $errorFoto;
                } else {
                    $stmt = $pdo->prepare("UPDATE arbitros SET nombre = ?, telefono = ?, correo = ?, estado = ?, foto = ? WHERE id = ?");
                    $stmt->execute([$nombre, $telefono, $correo, $estado, $rutaFoto, $id]);
                    $mensaje = "Árbitro actualizado correctamente.";
                    $esExito = true;
                }
            }
        }
    }

    if ($accion === 'eliminar') {
        $id = intval($_POST['id'] ?? 0);
        try {
            $qFoto = $pdo->prepare("SELECT foto FROM arbitros WHERE id = ?");
            $qFoto->execute([$id]);
            $fotoBorrar = (string)$qFoto->fetchColumn();
            $stmt = $pdo->prepare("DELETE FROM arbitros WHERE id = ?");
            $stmt->execute([$id]);
            fcBorrarImagen($fotoBorrar);
            $mensaje = "Árbitro eliminado correctamente.";
            $esExito = true;
        } catch (PDOException $e) {
            $mensaje = "No puedes eliminar un árbitro que tiene partidos registrados.";
        }
    }
}

$arbitros = $pdo->query("SELECT * FROM arbitros ORDER BY id DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Árbitros - FutCancha</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="bg-light es-empleado">

<nav class="navbar navbar-expand-lg navbar-custom sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="equipos.php">
            <i class="fa-solid fa-futbol text-success fs-3"></i>
            <span>FutCancha</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <li class="nav-item"><a class="nav-link" href="equipos.php">Equipos</a></li>
                <li class="nav-item"><a class="nav-link" href="jugadores.php">Jugadores</a></li>
                <li class="nav-item"><a class="nav-link active" href="arbitros.php">Árbitros</a></li>
                <li class="nav-item"><a class="nav-link" href="partidos.php">Partidos</a></li>
                <?= fcMenuGestion() ?>
                <li class="nav-item ms-lg-2"><?= renderNavAuth($usr) ?></li>
            </ul>
        </div>
    </div>
</nav>

<section class="py-5 bg-white border-bottom">
    <div class="container text-center py-2">
        <h1 class="display-5 fw-bold text-dark mt-2">Registro y Disponibilidad de Árbitros</h1>
        <p class="text-muted fs-5 max-w-700 mx-auto">Control de asistencia y asignación para partidos</p>
    </div>
</section>
<div class="container py-5">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3">Registrar Árbitro</h5>
                <?php if ($mensaje): ?>
                    <div class="alert <?= $esExito ? 'alert-success' : 'alert-danger' ?> py-2 mb-3"><?= esc($mensaje) ?></div>
                <?php endif; ?>
                <form method="POST" action="arbitros.php" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="crear">
                    <div class="mb-3">
                        <label class="form-label-minimal">Nombre Completo</label>
                        <input type="text" name="nombre" class="form-control underline-input" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Teléfono (Contacto rápido)</label>
                        <input type="text" name="telefono" class="form-control underline-input" placeholder="10 dígitos" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Correo Electrónico</label>
                        <input type="email" name="correo" class="form-control underline-input" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Fotografía del Árbitro</label>
                        <input type="file" name="foto" class="form-control form-control-sm bg-light border-0" accept="image/png,image/jpeg,image/webp">
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Confirmación / Estado</label>
                        <select name="estado" class="form-select border-0 bg-light">
                            <option value="Disponible">Disponible</option>
                            <option value="Confirmado">Asistencia Confirmada</option>
                            <option value="No Disponible">No Disponible</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-brand-primary w-100 mt-2">Guardar Árbitro</button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card feature-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0">Árbitros Disponibles</h5>
                    <a href="reporte.php?tipo=arbitros" target="_blank" onclick="return imprimirReporte(this.href, this)" class="btn btn-sm btn-brand-outline">
                        <i class="fa-solid fa-print me-1"></i> Imprimir Reporte
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Foto</th>
                                <th>Nombre</th>
                                <th>Teléfono</th>
                                <th>Estatus</th>
                                <th>Registrado por</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($arbitros)): ?>
                                <tr><td colspan="6"><div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Sin árbitros registrados</h6></div></td></tr>
                            <?php else: ?>
                                <?php foreach ($arbitros as $a): ?>
                                    <?php
                                        $badgeClass = "bg-secondary-subtle text-secondary border";
                                        if ($a['estado'] === "Disponible") $badgeClass = "bg-success-subtle text-success border border-success";
                                        elseif ($a['estado'] === "Confirmado") $badgeClass = "bg-primary-subtle text-primary border border-primary";
                                        elseif ($a['estado'] === "No Disponible") $badgeClass = "bg-danger-subtle text-danger border border-danger";
                                    ?>
                                    <tr>
                                        <td><?= fcFoto($a['foto'] ?? '', 'persona', 50) ?></td>
                                        <td>
                                            <div class="fw-bold text-dark"><?= esc($a['nombre']) ?></div>
                                            <div class="small text-muted"><?= esc($a['correo']) ?></div>
                                        </td>
                                        <td><?= esc($a['telefono']) ?></td>
                                        <td><span class="badge <?= $badgeClass ?>"><?= esc($a['estado']) ?></span></td>
                                        <td class="small text-muted"><?= esc($a['registrado_por'] ?: '—') ?></td>
                                        <td class="text-nowrap">
                                            <button type="button" class="btn btn-sm btn-brand-outline me-1"
                                                onclick="abrirModalArbitro(<?= $a['id'] ?>, '<?= esc($a['nombre']) ?>', '<?= esc($a['telefono']) ?>', '<?= esc($a['correo']) ?>', '<?= esc($a['estado']) ?>', '<?= esc($a['foto'] ?? '') ?>')">Editar</button>
                                            <form method="POST" action="arbitros.php" class="d-inline" onsubmit="return confirm('¿Eliminar este árbitro?');">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditarArbitro" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="arbitros.php" class="modal-content" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="id" id="editArbId">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Editar Árbitro</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label-minimal">Nombre Completo</label>
                    <input type="text" name="nombre" id="editArbNombre" class="form-control underline-input" required>
                </div>
                <div class="mb-3">
                    <label class="form-label-minimal">Teléfono (10 dígitos)</label>
                    <input type="text" name="telefono" id="editArbTel" class="form-control underline-input" required>
                </div>
                <div class="mb-3">
                    <label class="form-label-minimal">Correo Electrónico</label>
                    <input type="email" name="correo" id="editArbCorreo" class="form-control underline-input" required>
                </div>
                <div class="mb-3">
                    <label class="form-label-minimal">Nueva Fotografía (opcional)</label>
                    <div class="d-flex align-items-center gap-3">
                        <img id="editArbFotoActual" src="" alt="" class="foto-persona d-none" style="width:60px;height:60px;">
                        <input type="file" name="foto" class="form-control form-control-sm bg-light border-0" accept="image/png,image/jpeg,image/webp">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label-minimal">Estado</label>
                    <select name="estado" id="editArbEstado" class="form-select border-0 bg-light">
                        <option value="Disponible">Disponible</option>
                        <option value="Confirmado">Confirmado</option>
                        <option value="No Disponible">No Disponible</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand-primary btn-sm">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/imprimir.js"></script>
<script>
    function abrirModalArbitro(id, nombre, tel, correo, estado, foto) {
        document.getElementById('editArbId').value = id;
        document.getElementById('editArbNombre').value = nombre;
        document.getElementById('editArbTel').value = tel;
        document.getElementById('editArbCorreo').value = correo;
        document.getElementById('editArbEstado').value = estado;
        const img = document.getElementById('editArbFotoActual');
        if (foto) { img.src = foto; img.classList.remove('d-none'); } else { img.removeAttribute('src'); img.classList.add('d-none'); }
        new bootstrap.Modal(document.getElementById('modalEditarArbitro')).show();
    }
</script>
</body>
</html>