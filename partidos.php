<?php
require_once "conexion.php";
$usr = protegerPagina();

$mensaje = "";
$esExito = false;
$CANCHAS  = FC_CANCHAS;
$HORARIOS = FC_HORARIOS;

// Revisa si enviaron algun formulario (programar, confirmar asistencia, cancelar o eliminar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // 1. Programar un partido nuevo
    if ($accion === 'crear') {
        $localId     = intval($_POST['local_id'] ?? 0);
        $visitanteId = intval($_POST['visitante_id'] ?? 0);
        $cancha      = intval($_POST['cancha'] ?? 0);
        $fecha       = $_POST['fecha'] ?? '';
        $hora        = $_POST['hora'] ?? '';
        $arbitroId   = intval($_POST['arbitro_id'] ?? 0);
        $horaInicio  = substr($hora, 0, 5);

        if (!$localId || !$visitanteId) {
            $mensaje = "Selecciona dos equipos registrados.";
        } elseif ($localId === $visitanteId) {
            $mensaje = "Los equipos deben ser diferentes.";
        } elseif (!isset($CANCHAS[$cancha])) {
            $mensaje = "Selecciona una cancha válida.";
        } elseif (!$fecha || $fecha < date("Y-m-d")) {
            $mensaje = "Selecciona una fecha válida (hoy o posterior).";
        } elseif (!in_array($hora, $HORARIOS, true)) {
            $mensaje = "Selecciona un horario válido.";
        } elseif ($fecha === date("Y-m-d") && $horaInicio <= date("H:i")) {
            $mensaje = "Ese horario ($hora) ya pasó para el día de hoy. Elige un horario posterior a las " . date("H:i") . ".";
        } else {
            $qEq = $pdo->prepare("SELECT id, temporada FROM equipos WHERE id IN (?, ?)");
            $qEq->execute([$localId, $visitanteId]);
            $eqMap = $qEq->fetchAll(PDO::FETCH_KEY_PAIR);

            if (count($eqMap) < 2) {
                $mensaje = "Uno o ambos equipos seleccionados no existen.";
            } elseif (mb_strtolower(trim($eqMap[$localId]), 'UTF-8') !== mb_strtolower(trim($eqMap[$visitanteId]), 'UTF-8')) {
                $mensaje = "No se puede programar el partido: los equipos pertenecen a temporadas diferentes ({$eqMap[$localId]} vs {$eqMap[$visitanteId]}).";
            } else {
                try {
                    // Inicia transaccion y bloquea filas para evitar que dos empleados reserven el mismo lugar al mismo tiempo
                    $pdo->beginTransaction();

                    $qArb = $pdo->prepare("SELECT estado FROM arbitros WHERE id = ? FOR UPDATE");
                    $qArb->execute([$arbitroId]);
                    $arb = $qArb->fetch();

                    if (!$arb) {
                        $pdo->rollBack();
                        $mensaje = "Selecciona un árbitro registrado.";
                    } elseif ($arb['estado'] === "No Disponible") {
                        $pdo->rollBack();
                        $mensaje = "El árbitro seleccionado no está disponible.";
                    } else {
                        // Revisa que no este ocupada la cancha, ni el arbitro, ni alguno de los dos equipos a esa hora
                        $c1 = $pdo->prepare("SELECT id FROM partidos WHERE cancha = ? AND fecha = ? AND hora = ? AND estado != 'Cancelado' FOR UPDATE");
                        $c1->execute([$cancha, $fecha, $hora]);

                        $c2 = $pdo->prepare("SELECT id FROM partidos WHERE arbitro_id = ? AND fecha = ? AND hora = ? AND estado != 'Cancelado' FOR UPDATE");
                        $c2->execute([$arbitroId, $fecha, $hora]);

                        $c3 = $pdo->prepare("SELECT id FROM partidos WHERE (local_id IN (?, ?) OR visitante_id IN (?, ?)) AND fecha = ? AND hora = ? AND estado != 'Cancelado' FOR UPDATE");
                        $c3->execute([$localId, $visitanteId, $localId, $visitanteId, $fecha, $hora]);

                        if ($c1->fetch()) {
                            $pdo->rollBack();
                            $mensaje = "La cancha ya está reservada en ese horario.";
                        } elseif ($c2->fetch()) {
                            $pdo->rollBack();
                            $mensaje = "El árbitro ya tiene otro partido en ese horario.";
                        } elseif ($c3->fetch()) {
                            $pdo->rollBack();
                            $mensaje = "Uno de los equipos ya tiene un partido programado en ese horario.";
                        } else {
                            // Guarda el partido nuevo en la base de datos
                            $stmt = $pdo->prepare("INSERT INTO partidos (local_id, visitante_id, cancha, fecha, hora, arbitro_id, registrado_por) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$localId, $visitanteId, $cancha, $fecha, $hora, $arbitroId, $usr['nombre']]);
                            $pdo->commit();
                            $mensaje = "Partido programado correctamente.";
                            $esExito = true;
                        }
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ((string)$e->getCode() === '23000') {
                        $mensaje = "El horario acaba de ser reservado por otro usuario. Elige otro espacio.";
                    } else {
                        $mensaje = "Error al programar el partido: " . $e->getMessage();
                    }
                }
            }
        }
    }

    // 2. Cambia la asistencia del arbitro entre Confirmada y Pendiente
    if ($accion === 'asistencia') {
        $id = intval($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE partidos SET asistencia_arbitro = IF(asistencia_arbitro = 'Confirmada', 'Pendiente', 'Confirmada') WHERE id = ? AND estado = 'Programado'");
        $stmt->execute([$id]);
        $mensaje = "Asistencia de árbitro actualizada.";
        $esExito = true;
    }

    // 3. Cancelar partido
    if ($accion === 'cancelar') {
        $id = intval($_POST['id'] ?? 0);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE partidos SET estado = 'Cancelado', goles_local = NULL, goles_visitante = NULL WHERE id = ? AND estado = 'Programado'");
        $stmt->execute([$id]);
        if ($stmt->rowCount() > 0) {
            fcSincronizarGoles($pdo);
            $pdo->commit();
            $mensaje = "Partido cancelado. La cancha, el árbitro y los equipos han quedado liberados en ese horario.";
            $esExito = true;
        } else {
            $pdo->rollBack();
            $mensaje = "Solo se pueden cancelar partidos en estado Programado.";
        }
    }

    // 4. Eliminar partido
    if ($accion === 'eliminar') {
        $id = intval($_POST['id'] ?? 0);
        try {
            $pdo->beginTransaction();

            // Borra goles, tarjetas y el partido, y recalcula los totales desde la fuente unica de verdad
            $pdo->prepare("DELETE FROM goles WHERE partido_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM tarjetas WHERE partido_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM partidos WHERE id = ?")->execute([$id]);
            fcSincronizarGoles($pdo);

            $pdo->commit();
            $mensaje = "Partido eliminado correctamente y goles descontados de los jugadores.";
            $esExito = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $mensaje = "Error al eliminar el partido: " . $e->getMessage();
        }
    }
}

// Horarios que todavia se pueden agendar hoy (los que ya empezaron o pasaron no cuentan)
$horariosHoy = array_filter($HORARIOS, function ($h) {
    return substr($h, 0, 5) > date('H:i');
});
$fechaDefault = !empty($horariosHoy) ? date('Y-m-d') : date('Y-m-d', strtotime('+1 day'));

$equiposLista  = $pdo->query("SELECT id, nombre, temporada FROM equipos ORDER BY nombre ASC")->fetchAll();
$arbitrosLista = $pdo->query("SELECT id, nombre FROM arbitros WHERE estado != 'No Disponible' ORDER BY nombre ASC")->fetchAll();

$fechaFiltro = $_GET['fecha'] ?? '';
$fechaCal    = $fechaFiltro !== '' ? $fechaFiltro : date("Y-m-d");

$sqlBase = "SELECT p.*, el.nombre AS local_nombre, el.foto AS local_foto,
                   ev.nombre AS visitante_nombre, ev.foto AS visitante_foto,
                   a.nombre AS arbitro_nombre, a.foto AS arbitro_foto
            FROM partidos p
            INNER JOIN equipos el ON p.local_id = el.id
            INNER JOIN equipos ev ON p.visitante_id = ev.id
            INNER JOIN arbitros a ON p.arbitro_id = a.id";

if ($fechaFiltro !== '') {
    $stmt = $pdo->prepare($sqlBase . " WHERE p.fecha = ? ORDER BY p.hora ASC");
    $stmt->execute([$fechaFiltro]);
    $partidos = $stmt->fetchAll();
} else {
    $partidos = $pdo->query($sqlBase . " ORDER BY p.fecha DESC, p.hora ASC")->fetchAll();
}

$stmtCal = $pdo->prepare($sqlBase . " WHERE p.fecha = ? AND p.estado != 'Cancelado'");
$stmtCal->execute([$fechaCal]);
$partidosDia = $stmtCal->fetchAll();
$mapaCal = [];
foreach ($partidosDia as $pd) {
    $mapaCal[$pd['hora']][$pd['cancha']] = $pd;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partidos - FutCancha</title>
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
                <li class="nav-item"><a class="nav-link" href="arbitros.php">Árbitros</a></li>
                <li class="nav-item"><a class="nav-link active" href="partidos.php">Partidos</a></li>
                <?= fcMenuGestion() ?>
                <li class="nav-item ms-lg-2"><?= renderNavAuth($usr) ?></li>
            </ul>
        </div>
    </div>
</nav>

<section class="py-5 bg-white border-bottom">
    <div class="container text-center py-2">
        <h1 class="display-5 fw-bold text-dark mt-2">Administración de Partidos</h1>
        <p class="text-muted fs-5 max-w-700 mx-auto">Programar encuentros y consultar disponibilidad de las canchas</p>
    </div>
</section>

<div class="container py-5">
    <div class="row g-4">
        <div class="col-12 no-print">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-calendar-plus text-success me-2"></i>Programar Partido</h5>
                <?php if ($mensaje): ?>
                    <div class="alert <?= $esExito ? 'alert-success' : 'alert-danger' ?> py-2 mb-3"><?= esc($mensaje) ?></div>
                <?php endif; ?>
                <form method="POST" action="partidos.php">
                    <input type="hidden" name="accion" value="crear">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label-minimal">Equipo Local</label>
                            <select name="local_id" class="form-select border-0 bg-light" required>
                                <option value="">Seleccionar equipo...</option>
                                <?php foreach ($equiposLista as $eq): ?>
                                    <option value="<?= $eq['id'] ?>"><?= esc($eq['nombre']) ?> (<?= esc($eq['temporada']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label-minimal">Equipo Visitante</label>
                            <select name="visitante_id" class="form-select border-0 bg-light" required>
                                <option value="">Seleccionar equipo...</option>
                                <?php foreach ($equiposLista as $eq): ?>
                                    <option value="<?= $eq['id'] ?>"><?= esc($eq['nombre']) ?> (<?= esc($eq['temporada']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label-minimal">Árbitro</label>
                            <select name="arbitro_id" class="form-select border-0 bg-light" required>
                                <?php if (empty($arbitrosLista)): ?>
                                    <option value="">Sin árbitros disponibles</option>
                                <?php else: ?>
                                    <option value="">Seleccionar árbitro...</option>
                                    <?php foreach ($arbitrosLista as $a): ?>
                                        <option value="<?= $a['id'] ?>"><?= esc($a['nombre']) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label-minimal">Cancha</label>
                            <select name="cancha" class="form-select border-0 bg-light" required>
                                <?php foreach ($CANCHAS as $num => $nom): ?>
                                    <option value="<?= $num ?>"><?= esc($nom) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label-minimal">Fecha</label>
                            <input type="date" name="fecha" id="pFecha" min="<?= date('Y-m-d') ?>" value="<?= $fechaDefault ?>" onchange="actualizarHorarios()" class="form-control underline-input" required>
                        </div>
                        <div class="col-md-6 col-lg-3">
                            <label class="form-label-minimal">Horario</label>
                            <select name="hora" id="pHora" class="form-select border-0 bg-light" required>
                                <?php foreach ($HORARIOS as $h): ?>
                                    <option value="<?= $h ?>" data-inicio="<?= substr($h, 0, 5) ?>"><?= $h ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-lg-6 d-grid">
                            <button type="submit" class="btn btn-brand-primary">
                                <i class="fa-solid fa-calendar-check me-1"></i> Programar Partido
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-12">
            <div class="card feature-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0"><i class="fa-solid fa-futbol text-success me-2"></i>Partidos y Calendario</h5>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <form method="GET" action="partidos.php" class="d-flex gap-2 align-items-center no-print">
                            <input type="date" name="fecha" value="<?= esc($fechaFiltro) ?>" onchange="this.form.submit()" class="form-control form-control-sm bg-light border-0" style="width: auto;" title="Filtrar por fecha">
                            <a href="partidos.php" class="btn btn-sm btn-outline-secondary">Todos</a>
                        </form>
                        <a href="reporte.php?tipo=partidos&fecha=<?= urlencode($fechaFiltro) ?>" target="_blank" onclick="return imprimirReporte(this.href, this)" class="btn btn-sm btn-brand-outline">
                            <i class="fa-solid fa-print me-1"></i> Imprimir Reporte
                        </a>
                    </div>
                </div>

                <div id="contenedorCalendario" class="mb-4">
                    <h6 class="fw-bold mb-2">Calendario por cancha</h6>
                    <div class="alert alert-light border small py-2 mb-2">
                        <i class="fa-solid fa-circle-info text-success me-1"></i>
                        Disponibilidad de las 5 canchas para el día: <strong><?= esc($fechaCal) ?></strong>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm text-center align-middle tabla-calendario">
                            <thead class="table-light">
                                <tr>
                                    <th>Horario</th>
                                    <th>Cancha 1 (F7)</th>
                                    <th>Cancha 2 (F11)</th>
                                    <th>Cancha 3 (F5)</th>
                                    <th>Cancha 4 (F5)</th>
                                    <th>Cancha 5 (F11)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($HORARIOS as $h): ?>
                                    <tr>
                                        <td class="fw-bold bg-light"><?= $h ?></td>
                                        <?php for ($c = 1; $c <= 5; $c++): ?>
                                            <?php $ocupado = $mapaCal[$h][$c] ?? null; ?>
                                            <?php if ($ocupado): ?>
                                                <td class="bg-danger-subtle text-danger fw-medium">
                                                    Ocupada<br>
                                                    <small class="text-dark d-inline-flex align-items-center gap-1 flex-wrap justify-content-center">
                                                        <?= fcFoto($ocupado['local_foto'] ?? '', 'club', 22) ?><?= esc($ocupado['local_nombre']) ?> vs <?= fcFoto($ocupado['visitante_foto'] ?? '', 'club', 22) ?><?= esc($ocupado['visitante_nombre']) ?>
                                                    </small>
                                                </td>
                                            <?php else: ?>
                                                <td class="bg-success-subtle text-success">Disponible</td>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <h6 class="fw-bold mb-2">Lista de partidos</h6>
                <div class="table-responsive" id="contenedorTablaPartidos">
                    <table class="table align-middle tabla-partidos">
                        <thead>
                            <tr>
                                <th>Fecha y hora</th>
                                <th>Encuentro</th>
                                <th>Cancha</th>
                                <th>Árbitro</th>
                                <th>Resultado</th>
                                <th>Estado / Auditoría</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($partidos)): ?>
                                <tr><td colspan="7"><div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Sin partidos registrados</h6></div></td></tr>
                            <?php else: ?>
                                <?php foreach ($partidos as $p): ?>
                                    <?php
                                        $res = ($p['goles_local'] !== null && $p['goles_visitante'] !== null)
                                            ? '<span class="fw-bold">' . intval($p['goles_local']) . ' - ' . intval($p['goles_visitante']) . '</span>'
                                            : '<span class="text-muted">Pendiente</span>';
                                        $asistBadge = ($p['asistencia_arbitro'] === "Confirmada")
                                            ? '<span class="badge bg-success-subtle text-success border border-success">Asistencia OK</span>'
                                            : '<span class="badge bg-warning-subtle text-warning border border-warning">Por confirmar</span>';
                                        if ($p['estado'] === "Finalizado") {
                                            $estadoBadge = '<span class="badge bg-dark">Finalizado</span>';
                                        } elseif ($p['estado'] === "Cancelado") {
                                            $estadoBadge = '<span class="badge bg-danger-subtle text-danger border border-danger">Cancelado</span>';
                                        } else {
                                            $estadoBadge = '<span class="badge bg-primary-subtle text-primary border border-primary">' . esc($p['estado']) . '</span>';
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= esc($p['fecha']) ?></div>
                                            <div class="small text-muted"><?= esc($p['hora']) ?></div>
                                        </td>
                                        <td class="encuentro-celda">
                                            <div class="d-flex align-items-center flex-wrap gap-2">
                                                <span class="equipo-mini"><?= fcFoto($p['local_foto'] ?? '', 'club', 34) ?><strong><?= esc($p['local_nombre']) ?></strong></span>
                                                <span class="text-muted">vs</span>
                                                <span class="equipo-mini"><?= fcFoto($p['visitante_foto'] ?? '', 'club', 34) ?><strong><?= esc($p['visitante_nombre']) ?></strong></span>
                                            </div>
                                        </td>
                                        <td><?= esc($CANCHAS[$p['cancha']] ?? "Cancha " . $p['cancha']) ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <?= fcFoto($p['arbitro_foto'] ?? '', 'persona', 36) ?>
                                                <div>
                                                    <div><?= esc($p['arbitro_nombre']) ?></div>
                                                    <?= $asistBadge ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= $res ?></td>
                                        <td>
                                            <?= $estadoBadge ?>
                                            <div class="small text-muted mt-1">Por: <?= esc($p['registrado_por'] ?: '—') ?></div>
                                        </td>
                                        <td class="acciones">
                                            <a href="partido_detalle.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-brand-outline me-1 mb-1" title="Registrar goles, tarjetas y finalizar">Goles / Detalle</a>
                                            <?php if ($p['estado'] === 'Programado'): ?>
                                                <form method="POST" action="partidos.php" class="d-inline">
                                                    <input type="hidden" name="accion" value="asistencia">
                                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary me-1" title="Confirmar asistencia árbitro">Asist.</button>
                                                </form>
                                                <form method="POST" action="partidos.php" class="d-inline" onsubmit="return confirm('¿Cancelar este partido? Se liberará la cancha y el árbitro.');">
                                                    <input type="hidden" name="accion" value="cancelar">
                                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning me-1" title="Cancelar partido">Cancelar</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" action="partidos.php" class="d-inline" onsubmit="return confirm('¿Eliminar este partido definitivamente?');">
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/imprimir.js"></script>
<script>
    // Hora y fecha reales del servidor (Guadalajara)
    const HOY = '<?= date('Y-m-d') ?>';
    const AHORA = '<?= date('H:i') ?>';

    // Si la fecha elegida es hoy, bloquea los horarios que ya empezaron o pasaron
    function actualizarHorarios() {
        const fecha = document.getElementById('pFecha').value;
        const sel = document.getElementById('pHora');
        let primero = null;
        Array.from(sel.options).forEach(function (o) {
            const pasada = (fecha === HOY && o.dataset.inicio <= AHORA);
            o.disabled = pasada;
            o.textContent = o.value + (pasada ? ' (ya pasó)' : '');
            if (!pasada && !primero) primero = o;
        });
        if (sel.selectedOptions.length && sel.selectedOptions[0].disabled && primero) {
            sel.value = primero.value;
        }
    }
    actualizarHorarios();
</script>
</body>
</html>