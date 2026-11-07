<?php
require_once "conexion.php";
$usr = protegerPagina();

$mensaje = "";
$esExito = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // 1. Alta de un jugador nuevo
    if ($accion === 'crear') {
        $equipoId = intval($_POST['equipo_id'] ?? 0);
        $nombre   = trim($_POST['nombre'] ?? '');
        $numero   = intval($_POST['numero'] ?? 0);
        $posicion = $_POST['posicion'] ?? 'Delantero';

        if (!$equipoId) {
            $mensaje = "Selecciona un equipo registrado.";
        } elseif ($nombre === "") {
            $mensaje = "Escribe el nombre del jugador.";
        } elseif ($numero < 1 || $numero > 99) {
            $mensaje = "Ingresa un número de camiseta entre 1 y 99.";
        } else {
            // Revisa que no haya otro jugador en el mismo equipo con ese numero de camiseta
            $check = $pdo->prepare("SELECT id FROM jugadores WHERE equipo_id = ? AND numero = ?");
            $check->execute([$equipoId, $numero]);
            if ($check->fetch()) {
                $mensaje = "Ese número de camiseta ya está registrado en el equipo.";
            } else {
                $rutaFoto = "";
                $errorArchivo = false;

                // Si subieron fotografia, revisa que sea imagen valida y la guarda en la carpeta uploads/jugadores
                if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $mensaje = "Formato de imagen no válido (usa JPG, PNG o WEBP).";
                        $errorArchivo = true;
                    } else {
                        $dir = "uploads/jugadores/";
                        if (!is_dir($dir)) mkdir($dir, 0775, true);
                        $nombreArchivo = uniqid("jug_") . "." . $ext;
                        if (move_uploaded_file($_FILES['foto']['tmp_name'], $dir . $nombreArchivo)) {
                            $rutaFoto = $dir . $nombreArchivo;
                        }
                    }
                }

                if (!$errorArchivo) {
                    $stmt = $pdo->prepare("INSERT INTO jugadores (equipo_id, nombre, numero, posicion, foto, goles, registrado_por) VALUES (?, ?, ?, ?, ?, 0, ?)");
                    $stmt->execute([$equipoId, $nombre, $numero, $posicion, $rutaFoto, $usr['nombre']]);
                    $mensaje = "Jugador registrado correctamente.";
                    $esExito = true;
                }
            }
        }
    }

    // 2. Edicion de datos de un jugador 
    if ($accion === 'editar') {
        $id       = intval($_POST['id'] ?? 0);
        $equipoId = intval($_POST['equipo_id'] ?? 0);
        $nombre   = trim($_POST['nombre'] ?? '');
        $numero   = intval($_POST['numero'] ?? 0);
        $posicion = $_POST['posicion'] ?? 'Delantero';

        $qActual = $pdo->prepare("SELECT id, foto FROM jugadores WHERE id = ?");
        $qActual->execute([$id]);
        $actual = $qActual->fetch();

        if (!$actual) {
            $mensaje = "El jugador seleccionado no existe.";
        } elseif (!$equipoId) {
            $mensaje = "Selecciona un equipo registrado.";
        } elseif ($nombre === "") {
            $mensaje = "Escribe el nombre del jugador.";
        } elseif ($numero < 1 || $numero > 99) {
            $mensaje = "Ingresa un número de camiseta entre 1 y 99.";
        } else {
            $check = $pdo->prepare("SELECT id FROM jugadores WHERE equipo_id = ? AND numero = ? AND id != ?");
            $check->execute([$equipoId, $numero, $id]);
            if ($check->fetch()) {
                $mensaje = "Ese número de camiseta ya está ocupado por otro jugador en ese equipo.";
            } else {
                $rutaFoto = $actual['foto'];
                $errorArchivo = false;

                // Si subieron una foto nueva, borra la anterior y guarda la nueva
                if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $mensaje = "Formato de imagen no válido (usa JPG, PNG o WEBP).";
                        $errorArchivo = true;
                    } else {
                        $dir = "uploads/jugadores/";
                        if (!is_dir($dir)) mkdir($dir, 0775, true);
                        $nombreArchivo = uniqid("jug_") . "." . $ext;
                        if (move_uploaded_file($_FILES['foto']['tmp_name'], $dir . $nombreArchivo)) {
                            if (!empty($rutaFoto) && file_exists($rutaFoto)) {
                                unlink($rutaFoto);
                            }
                            $rutaFoto = $dir . $nombreArchivo;
                        }
                    }
                }

                if (!$errorArchivo) {
                    $upd = $pdo->prepare("UPDATE jugadores SET equipo_id = ?, nombre = ?, numero = ?, posicion = ?, foto = ? WHERE id = ?");
                    $upd->execute([$equipoId, $nombre, $numero, $posicion, $rutaFoto, $id]);
                    fcSincronizarGoles($pdo, null, $id);
                    $mensaje = "Datos del jugador actualizados correctamente.";
                    $esExito = true;
                }
            }
        }
    }

    // 3. Eliminacion de jugador
    if ($accion === 'eliminar') {
        $id = intval($_POST['id'] ?? 0);
        try {
            $pdo->beginTransaction();

            $q = $pdo->prepare("SELECT foto, nombre FROM jugadores WHERE id = ?");
            $q->execute([$id]);
            $row = $q->fetch();

            $qPartidos = $pdo->prepare("SELECT DISTINCT partido_id FROM goles WHERE jugador_id = ?");
            $qPartidos->execute([$id]);
            $partidosAfectados = $qPartidos->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare("DELETE FROM goles WHERE jugador_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tarjetas WHERE jugador_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM jugadores WHERE id = ?")->execute([$id]);

            // Actualiza el marcador de cada partido donde metio gol para que no queden goles fantasma
            foreach ($partidosAfectados as $pid) {
                fcSincronizarGoles($pdo, (int)$pid);
            }
            fcSincronizarGoles($pdo);

            $pdo->commit();

            if ($row && !empty($row['foto']) && file_exists($row['foto'])) {
                unlink($row['foto']);
            }

            $mensaje = "Jugador eliminado y marcadores de sus partidos actualizados.";
            $esExito = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje = "Error al eliminar el jugador: " . $e->getMessage();
        }
    }
}

// Carga los equipos disponibles para llenar los selectores
$equiposLista = $pdo->query("SELECT id, nombre, temporada FROM equipos ORDER BY nombre ASC")->fetchAll();

// Trae los jugadores contando sus goles reales en partidos no cancelados
$jugadores = $pdo->query("SELECT j.id, j.equipo_id, j.nombre, j.numero, j.posicion, j.foto, j.registrado_por,
                                 COUNT(p.id) AS goles, e.nombre AS equipo
                          FROM jugadores j
                          INNER JOIN equipos e ON j.equipo_id = e.id
                          LEFT JOIN goles g ON g.jugador_id = j.id
                          LEFT JOIN partidos p ON p.id = g.partido_id AND p.estado != 'Cancelado'
                          GROUP BY j.id, j.equipo_id, j.nombre, j.numero, j.posicion, j.foto, j.registrado_por, e.nombre")->fetchAll();

// Ordenamiento burbuja: acomoda la tabla general de goleo de mas goles a menos goles (y por nombre si empatan)
$jugadores = Ordenamiento::burbuja($jugadores, function ($a, $b) {
    $diff = (int)$b['goles'] - (int)$a['goles'];
    return $diff !== 0 ? $diff : strcmp($a['nombre'], $b['nombre']);
});
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jugadores y Goleo - FutCancha</title>
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
                <li class="nav-item"><a class="nav-link active" href="jugadores.php">Jugadores</a></li>
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
        <h1 class="display-5 fw-bold text-dark mt-2">Registro de Jugadores y Goleo</h1>
        <p class="text-muted fs-5 max-w-700 mx-auto">Alta, jugadores por equipo y tabla de goleo</p>
    </div>
</section>

<div class="container py-5">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3">Dar de alta Jugador</h5>
                <?php if ($mensaje): ?>
                    <div class="alert <?= $esExito ? 'alert-success' : 'alert-danger' ?> py-2 mb-3"><?= esc($mensaje) ?></div>
                <?php endif; ?>
                <form method="POST" action="jugadores.php" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="crear">
                    <div class="mb-3">
                        <label class="form-label-minimal">Equipo</label>
                        <select name="equipo_id" class="form-select border-0 bg-light" required>
                            <?php if (empty($equiposLista)): ?>
                                <option value="">Primero registra un equipo...</option>
                            <?php else: ?>
                                <option value="">Seleccionar equipo...</option>
                                <?php foreach ($equiposLista as $eq): ?>
                                    <option value="<?= $eq['id'] ?>"><?= esc($eq['nombre']) ?> - <?= esc($eq['temporada']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Nombre del Jugador</label>
                        <input type="text" name="nombre" class="form-control underline-input" placeholder="Nombre completo" autocomplete="off" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label-minimal">Número</label>
                            <input type="number" name="numero" class="form-control underline-input" min="1" max="99" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label-minimal">Posición</label>
                            <select name="posicion" class="form-select border-0 bg-light" required>
                                <option value="Portero">Portero</option>
                                <option value="Defensa">Defensa</option>
                                <option value="Medio">Medio</option>
                                <option value="Delantero">Delantero</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Fotografía del Jugador</label>
                        <input type="file" name="foto" class="form-control form-control-sm bg-light border-0" accept="image/*">
                    </div>

                    <button type="submit" class="btn btn-brand-primary w-100 mt-2">Registrar Jugador</button>
                </form>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card feature-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0">Tabla General de Goleo</h5>
                    <a href="reporte.php?tipo=jugadores" target="_blank" onclick="return imprimirReporte(this.href, this)" class="btn btn-sm btn-brand-outline">
                        <i class="fa-solid fa-print me-1"></i> Imprimir Reporte
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Foto</th>
                                <th>Jugador</th>
                                <th>Equipo</th>
                                <th>Posición</th>
                                <th>Goles</th>
                                <th>Registrado por</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($jugadores)): ?>
                                <tr><td colspan="7"><div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Sin jugadores registrados</h6></div></td></tr>
                            <?php else: ?>
                                <?php foreach ($jugadores as $j): ?>
                                    <?php $foto = !empty($j['foto']) ? esc($j['foto']) : "https://placehold.co/100x100/e6f4ea/0f5a36?text=" . intval($j['numero']); ?>
                                    <tr>
                                        <td>
                                            <img src="<?= $foto ?>" alt="<?= esc($j['nombre']) ?>" class="rounded-circle border player-avatar-img" style="width: 45px; height: 45px; object-fit: cover;">
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="jersey-badge">#<?= intval($j['numero']) ?></span>
                                                <span class="fw-bold text-dark"><?= esc($j['nombre']) ?></span>
                                            </div>
                                        </td>
                                        <td><span class="fw-medium"><?= esc($j['equipo']) ?></span></td>
                                        <td><span class="badge bg-light text-secondary border"><?= esc($j['posicion']) ?></span></td>
                                        <td><span class="goal-counter"><?= intval($j['goles']) ?></span></td>
                                        <td class="small text-muted"><?= esc($j['registrado_por'] ?: '—') ?></td>
                                        <td class="text-nowrap">
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-secondary me-1"
                                                    onclick='abrirModalEditarJugador(<?= json_encode([
                                                        "id" => (int)$j["id"],
                                                        "equipo_id" => (int)$j["equipo_id"],
                                                        "nombre" => $j["nombre"],
                                                        "numero" => (int)$j["numero"],
                                                        "posicion" => $j["posicion"]
                                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                                Editar
                                            </button>
                                            <form method="POST" action="jugadores.php" class="d-inline" onsubmit="return confirm('¿Eliminar este jugador?');">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id" value="<?= $j['id'] ?>">
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

<div class="modal fade" id="modalEditarJugador" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="jugadores.php" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="id" id="editJugadorId">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Editar Jugador</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label-minimal">Equipo</label>
                        <select name="equipo_id" id="editJugadorEquipo" class="form-select border-0 bg-light" required>
                            <?php foreach ($equiposLista as $eq): ?>
                                <option value="<?= $eq['id'] ?>"><?= esc($eq['nombre']) ?> - <?= esc($eq['temporada']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Nombre del Jugador</label>
                        <input type="text" name="nombre" id="editJugadorNombre" class="form-control underline-input" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label-minimal">Número</label>
                            <input type="number" name="numero" id="editJugadorNumero" class="form-control underline-input" min="1" max="99" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label-minimal">Posición</label>
                            <select name="posicion" id="editJugadorPosicion" class="form-select border-0 bg-light" required>
                                <option value="Portero">Portero</option>
                                <option value="Defensa">Defensa</option>
                                <option value="Medio">Medio</option>
                                <option value="Delantero">Delantero</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label-minimal">Nueva Fotografía (opcional)</label>
                        <input type="file" name="foto" class="form-control form-control-sm bg-light border-0" accept="image/*">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-brand-primary btn-sm">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/imprimir.js"></script>
<script>
    function abrirModalEditarJugador(j) {
        document.getElementById('editJugadorId').value = j.id;
        document.getElementById('editJugadorEquipo').value = j.equipo_id;
        document.getElementById('editJugadorNombre').value = j.nombre;
        document.getElementById('editJugadorNumero').value = j.numero;
        document.getElementById('editJugadorPosicion').value = j.posicion;
        new bootstrap.Modal(document.getElementById('modalEditarJugador')).show();
    }
</script>
</body>
</html>