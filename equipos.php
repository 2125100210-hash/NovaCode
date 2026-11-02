<?php
require_once "conexion.php";
$usr = protegerPagina();

$mensaje = "";
$esExito = false;

// Revisa si enviaron algun formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // 1. Alta de un equipo nuevo
    if ($accion === 'crear') {
        $nombre    = trim($_POST['nombre'] ?? '');
        $temporada = trim($_POST['temporada'] ?? '');

        if ($nombre === "" or $temporada === "") {
            $mensaje = "Completa el nombre del equipo y la temporada.";
        } else {
            // Revisa que no exista ya ese mismo equipo inscrito en la misma temporada
            $check = $pdo->prepare("SELECT id FROM equipos WHERE LOWER(nombre) = LOWER(?) AND LOWER(temporada) = LOWER(?)");
            $check->execute([$nombre, $temporada]);
            if ($check->fetch()) {
                $mensaje = "Este equipo ya está registrado en esa temporada.";
            } else {
                // Si subieron la foto del club, la valida y la guarda en uploads/equipos
                $rutaFoto = fcGuardarImagen('foto', 'uploads/equipos', 'eq_', '', $errorFoto);
                if ($errorFoto !== '') {
                    $mensaje = $errorFoto;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO equipos (nombre, temporada, foto, registrado_por) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nombre, $temporada, $rutaFoto, $usr['nombre']]);
                    $mensaje = "Equipo registrado correctamente.";
                    $esExito = true;
                }
            }
        }
    }

    // 2. Edicion del nombre o temporada de un equipo existente
    if ($accion === 'editar') {
        $id        = intval($_POST['id'] ?? 0);
        $nombre    = trim($_POST['nombre'] ?? '');
        $temporada = trim($_POST['temporada'] ?? '');

        if (!$id or $nombre === "" or $temporada === "") {
            $mensaje = "Completa todos los datos para editar.";
        } else {
            $check = $pdo->prepare("SELECT id FROM equipos WHERE id != ? AND LOWER(nombre) = LOWER(?) AND LOWER(temporada) = LOWER(?)");
            $check->execute([$id, $nombre, $temporada]);
            if ($check->fetch()) {
                $mensaje = "Ya existe ese equipo en esa temporada.";
            } else {
                // Si subieron una foto nueva del club, reemplaza la anterior
                $qFoto = $pdo->prepare("SELECT foto FROM equipos WHERE id = ?");
                $qFoto->execute([$id]);
                $fotoActual = (string)$qFoto->fetchColumn();
                $rutaFoto = fcGuardarImagen('foto', 'uploads/equipos', 'eq_', $fotoActual, $errorFoto);
                if ($errorFoto !== '') {
                    $mensaje = $errorFoto;
                } else {
                    $stmt = $pdo->prepare("UPDATE equipos SET nombre = ?, temporada = ?, foto = ? WHERE id = ?");
                    $stmt->execute([$nombre, $temporada, $rutaFoto, $id]);
                    $mensaje = "Equipo actualizado correctamente.";
                    $esExito = true;
                }
            }
        }
    }

    // 3. Eliminacion de equipo
    if ($accion === 'eliminar') {
        $id = intval($_POST['id'] ?? 0);
        try {
            $qFoto = $pdo->prepare("SELECT foto FROM equipos WHERE id = ?");
            $qFoto->execute([$id]);
            $fotoBorrar = (string)$qFoto->fetchColumn();
            $stmt = $pdo->prepare("DELETE FROM equipos WHERE id = ?");
            $stmt->execute([$id]);
            fcBorrarImagen($fotoBorrar);
            $mensaje = "Equipo eliminado correctamente.";
            $esExito = true;
        } catch (PDOException $e) {
            $mensaje = "No puedes eliminar un equipo que tiene jugadores o partidos registrados.";
        }
    }

    // 4. Terminar una temporada: borra sus equipos, jugadores, partidos, goles y tarjetas para empezar de cero (solo administrador)
    if ($accion === 'eliminar_temporada') {
        $temp      = trim($_POST['temporada'] ?? '');
        $confirmar = trim($_POST['confirmar'] ?? '');

        if (!esAdmin()) {
            $mensaje = "Solo un administrador puede terminar una temporada.";
        } elseif ($temp === '') {
            $mensaje = "Selecciona la temporada que terminó.";
        } elseif (mb_strtolower($confirmar, 'UTF-8') !== mb_strtolower($temp, 'UTF-8')) {
            $mensaje = "El nombre escrito no coincide con la temporada. No se borró nada.";
        } else {
            try {
                $pdo->beginTransaction();

                $qEq = $pdo->prepare("SELECT id, foto FROM equipos WHERE temporada = ?");
                $qEq->execute([$temp]);
                $eqs = $qEq->fetchAll();

                if (!$eqs) {
                    $pdo->rollBack();
                    $mensaje = "Esa temporada no tiene equipos registrados.";
                } else {
                    $ids   = array_map('intval', array_column($eqs, 'id'));
                    $in    = implode(',', array_fill(0, count($ids), '?'));
                    $fotos = array_column($eqs, 'foto');

                    // Guarda las rutas de las fotos de los jugadores para borrarlas despues
                    $qFj = $pdo->prepare("SELECT foto FROM jugadores WHERE equipo_id IN ($in)");
                    $qFj->execute($ids);
                    $fotos = array_merge($fotos, $qFj->fetchAll(PDO::FETCH_COLUMN));

                    // Partidos de la temporada
                    $qP = $pdo->prepare("SELECT id FROM partidos WHERE local_id IN ($in) OR visitante_id IN ($in)");
                    $qP->execute(array_merge($ids, $ids));
                    $pids = array_map('intval', $qP->fetchAll(PDO::FETCH_COLUMN));

                    if ($pids) {
                        $inP = implode(',', array_fill(0, count($pids), '?'));
                        $pdo->prepare("DELETE FROM goles WHERE partido_id IN ($inP)")->execute($pids);
                        $pdo->prepare("DELETE FROM tarjetas WHERE partido_id IN ($inP)")->execute($pids);
                        $pdo->prepare("DELETE FROM partidos WHERE id IN ($inP)")->execute($pids);
                    }
                    $pdo->prepare("DELETE FROM goles WHERE equipo_id IN ($in)")->execute($ids);
                    $pdo->prepare("DELETE FROM tarjetas WHERE equipo_id IN ($in)")->execute($ids);
                    $qNJ = $pdo->prepare("DELETE FROM jugadores WHERE equipo_id IN ($in)");
                    $qNJ->execute($ids);
                    $numJug = $qNJ->rowCount();
                    $pdo->prepare("DELETE FROM equipos WHERE temporada = ?")->execute([$temp]);
                    fcSincronizarGoles($pdo);

                    $pdo->commit();
                    foreach ($fotos as $f) {
                        fcBorrarImagen($f);
                    }

                    $mensaje = "Temporada \"$temp\" terminada: se borraron " . count($ids) . " equipo(s), $numJug jugador(es) y " . count($pids) . " partido(s). Ya puedes registrar los nuevos equipos.";
                    $esExito = true;
                    registrarMovimiento('Equipos', 'Fin de temporada', $mensaje);
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensaje = "Error al terminar la temporada: " . $e->getMessage();
            }
        }
    }
}

// Carga las temporadas distintas para llenar el filtro desplegable
$temporadas = $pdo->query("SELECT DISTINCT temporada FROM equipos ORDER BY temporada DESC")->fetchAll(PDO::FETCH_COLUMN);
$tempSel = $_GET['temporada'] ?? '';

// Solo para administradores: cuenta cuantos equipos, jugadores y partidos tiene cada temporada
$resumenTemporadas = [];
if (esAdmin()) {
    foreach ($temporadas as $t) {
        $qc = $pdo->prepare("SELECT COUNT(*) FROM equipos WHERE temporada = ?");
        $qc->execute([$t]);
        $qj = $pdo->prepare("SELECT COUNT(*) FROM jugadores j INNER JOIN equipos e ON e.id = j.equipo_id WHERE e.temporada = ?");
        $qj->execute([$t]);
        $qp = $pdo->prepare("SELECT COUNT(*) FROM partidos p INNER JOIN equipos e ON e.id = p.local_id WHERE e.temporada = ?");
        $qp->execute([$t]);
        $resumenTemporadas[$t] = ['equipos' => (int)$qc->fetchColumn(), 'jugadores' => (int)$qj->fetchColumn(), 'partidos' => (int)$qp->fetchColumn()];
    }
}

// Si eligieron una temporada en el filtro trae solo esos equipos, si no, trae todos
if ($tempSel !== '') {
    $stmt = $pdo->prepare("SELECT * FROM equipos WHERE temporada = ? ORDER BY id DESC");
    $stmt->execute([$tempSel]);
    $equipos = $stmt->fetchAll();
} else {
    $equipos = $pdo->query("SELECT * FROM equipos ORDER BY id DESC")->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipos - FutCancha</title>
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
                <li class="nav-item"><a class="nav-link active" href="equipos.php">Equipos</a></li>
                <li class="nav-item"><a class="nav-link" href="jugadores.php">Jugadores</a></li>
                <li class="nav-item"><a class="nav-link" href="arbitros.php">Árbitros</a></li>
                <li class="nav-item"><a class="nav-link" href="partidos.php">Partidos</a></li>
                <?= fcMenuGestion() ?>
                <li class="nav-item ms-lg-2"><?= renderNavAuth($usr) ?></li>
            </ul>
        </div>
    </div>
</nav>

<section class="py-5 bg-white border-bottom">
    <div class="container text-center py-2">
        <h1 class="display-5 fw-bold text-dark mt-2">Registro de Equipos</h1>
        <p class="text-muted fs-5 max-w-700 mx-auto">Equipos inscritos y sus temporadas</p>
    </div>
</section>

<div class="container py-5">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3">Registrar Equipo</h5>
                <?php if ($mensaje): ?>
                    <div class="alert <?= $esExito ? 'alert-success' : 'alert-danger' ?> py-2 mb-3"><?= esc($mensaje) ?></div>
                <?php endif; ?>
                <form method="POST" action="equipos.php" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="crear">
                    <div class="mb-3">
                        <label class="form-label-minimal">Nombre del Equipo</label>
                        <input type="text" name="nombre" class="form-control underline-input" placeholder="Nombre del equipo" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Temporada</label>
                        <input type="text" name="temporada" class="form-control underline-input" placeholder="Ej. Temporada 2026" autocomplete="off" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Foto del Club (escudo)</label>
                        <input type="file" name="foto" class="form-control form-control-sm bg-light border-0" accept="image/png,image/jpeg,image/webp">
                    </div>
                    <button type="submit" class="btn btn-brand-primary w-100 mt-2">Registrar Equipo</button>
                </form>
            </div>
        </div>

       <div class="col-lg-8">
            <div class="card feature-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0">Equipos Registrados</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <form method="GET" action="equipos.php" class="no-print">
                            <select name="temporada" onchange="this.form.submit()" class="form-select form-select-sm bg-light border-0" style="width: auto;">
                                <option value="">Todas las temporadas</option>
                                <?php foreach ($temporadas as $t): ?>
                                    <option value="<?= esc($t) ?>" <?= $tempSel === $t ? 'selected' : '' ?>><?= esc($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <a href="reporte.php?tipo=equipos&temporada=<?= urlencode($tempSel) ?>" target="_blank" onclick="return imprimirReporte(this.href, this)" class="btn btn-sm btn-brand-outline">
                            <i class="fa-solid fa-print me-1"></i> Imprimir Reporte
                        </a>
                        <?php if (esAdmin() && !empty($temporadas)): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger no-print" data-bs-toggle="modal" data-bs-target="#modalTemporada">
                                <i class="fa-solid fa-flag-checkered me-1"></i> Terminar temporada
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Club</th>
                                <th>Equipo</th>
                                <th>Temporada</th>
                                <th>Registrado por</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($equipos)): ?>
                                <tr><td colspan="5"><div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Sin equipos registrados</h6></div></td></tr>
                            <?php else: ?>
                                <?php foreach ($equipos as $e): ?>
                                    <tr>
                                        <td><?= fcFoto($e['foto'] ?? '', 'club', 50) ?></td>
                                        <td><span class="fw-bold text-dark"><?= esc($e['nombre']) ?></span></td>
                                        <td><span class="badge bg-light text-dark border"><?= esc($e['temporada']) ?></span></td>
                                        <td class="small text-muted"><?= esc($e['registrado_por'] ?: '—') ?></td>
                                        <td class="text-nowrap">
                                            <button type="button" class="btn btn-sm btn-brand-outline me-1"
                                                onclick="abrirModalEquipo(<?= $e['id'] ?>, '<?= esc($e['nombre']) ?>', '<?= esc($e['temporada']) ?>', '<?= esc($e['foto'] ?? '') ?>')">Editar</button>
                                            <form method="POST" action="equipos.php" class="d-inline" onsubmit="return confirm('¿Eliminar este equipo?');">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id" value="<?= $e['id'] ?>">
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

<div class="modal fade" id="modalEditarEquipo" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="equipos.php" class="modal-content" enctype="multipart/form-data">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="id" id="editEqId">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Editar Equipo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label-minimal">Nombre del Equipo</label>
                    <input type="text" name="nombre" id="editEqNombre" class="form-control underline-input" required>
                </div>
                <div class="mb-3">
                    <label class="form-label-minimal">Temporada</label>
                    <input type="text" name="temporada" id="editEqTemporada" class="form-control underline-input" required>
                </div>
                <div class="mb-3">
                    <label class="form-label-minimal">Nueva Foto del Club (opcional)</label>
                    <div class="d-flex align-items-center gap-3">
                        <img id="editEqFotoActual" src="" alt="" class="foto-club d-none" style="width:60px;height:60px;">
                        <input type="file" name="foto" class="form-control form-control-sm bg-light border-0" accept="image/png,image/jpeg,image/webp">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-brand-primary btn-sm">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<?php if (esAdmin() && !empty($temporadas)): ?>
<div class="modal fade" id="modalTemporada" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="equipos.php" class="modal-content" autocomplete="off">
            <input type="hidden" name="accion" value="eliminar_temporada">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-flag-checkered text-danger me-2"></i>Terminar temporada</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning small py-2">
                    Se borrarán <strong>todos los equipos, jugadores, partidos, goles y tarjetas</strong> de la temporada elegida, junto con sus fotos. Esta acción no se puede deshacer. Los árbitros no se borran.
                    Antes de continuar, imprime el reporte de posiciones de esa temporada si quieres conservarlo.
                </div>
                <div class="mb-3">
                    <label class="form-label-minimal">Temporada que terminó</label>
                    <select name="temporada" id="selTemporadaFin" class="form-select border-0 bg-light" onchange="revisarConfirmacionTemporada()" required>
                        <?php foreach ($temporadas as $t): $r = $resumenTemporadas[$t] ?? ['equipos' => 0, 'jugadores' => 0, 'partidos' => 0]; ?>
                            <option value="<?= esc($t) ?>" <?= $tempSel === $t ? 'selected' : '' ?>><?= esc($t) ?> (<?= $r['equipos'] ?> equipos, <?= $r['jugadores'] ?> jugadores, <?= $r['partidos'] ?> partidos)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label-minimal">Para confirmar, escribe el nombre exacto de la temporada</label>
                    <input type="text" name="confirmar" id="inputConfirmarTemporada" class="form-control underline-input" oninput="revisarConfirmacionTemporada()" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" id="btnBorrarTemporada" class="btn btn-danger btn-sm" disabled>Borrar temporada</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/imprimir.js"></script>
<script>
    // El boton de borrar temporada solo se activa cuando el nombre escrito coincide con la temporada elegida
    function revisarConfirmacionTemporada() {
        const sel = document.getElementById('selTemporadaFin');
        const inp = document.getElementById('inputConfirmarTemporada');
        const btn = document.getElementById('btnBorrarTemporada');
        if (!sel || !inp || !btn) return;
        btn.disabled = inp.value.trim().toLowerCase() !== sel.value.trim().toLowerCase();
    }

    // Llena los datos del equipo en la ventana emergente y la muestra en pantalla
    function abrirModalEquipo(id, nombre, temporada, foto) {
        document.getElementById('editEqId').value = id;
        document.getElementById('editEqNombre').value = nombre;
        document.getElementById('editEqTemporada').value = temporada;
        const img = document.getElementById('editEqFotoActual');
        if (foto) { img.src = foto; img.classList.remove('d-none'); } else { img.removeAttribute('src'); img.classList.add('d-none'); }
        new bootstrap.Modal(document.getElementById('modalEditarEquipo')).show();
    }
</script>
</body>
</html>