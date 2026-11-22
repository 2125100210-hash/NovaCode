<?php
require_once "conexion.php";
$usr = protegerPagina();

$mensaje = "";
$esExito = false;
$resultadosBusqueda = null;
$resumenClima = [];

$hoy = date('Y-m-d');
$horaActual = date('H:i');

$sqlPartido = "SELECT p.*, el.nombre AS local_nombre, ev.nombre AS visitante_nombre
FROM partidos p
INNER JOIN equipos el ON p.local_id = el.id
INNER JOIN equipos ev ON p.visitante_id = ev.id";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // 1. Busqueda automatica de horarios libres segun cancha, arbitro o equipos elegidos
    if ($accion === 'buscar') {
        $desde    = $_POST['desde'] ?? $hoy;
        $cancha   = intval($_POST['cancha'] ?? 0);
        $arbitro  = intval($_POST['arbitro_id'] ?? 0);
        $local    = intval($_POST['local_id'] ?? 0);
        $visit    = intval($_POST['visitante_id'] ?? 0);
        $cantidad = max(1, min(30, intval($_POST['cantidad'] ?? 10)));

        if ($desde < $hoy) {
            $mensaje = "La fecha inicial no puede ser anterior a hoy.";
        } elseif ($cancha && !isset(FC_CANCHAS[$cancha])) {
            $mensaje = "Cancha no válida.";
        } elseif ($local && $local === $visit) {
            $mensaje = "Los equipos deben ser diferentes.";
        } else {
            // Carga los espacios ocupados desde la fecha elegida y busca bloques libres
            $disp = Disponibilidad::cargar($pdo, $desde);
            $brutos = fcBuscarHorariosLibres($disp, $desde, 60, [
                'cancha' => $cancha, 'arbitro' => $arbitro, 'local' => $local, 'visitante' => $visit
            ], $cantidad + 15);

            // Descarta los horarios de hoy que ya pasaron para mostrar solo opciones reales
            $resultadosBusqueda = [];
            foreach ($brutos as $r) {
                if ($r['fecha'] === $hoy && substr($r['hora'], 0, 5) <= $horaActual) {
                    continue;
                }
                $resultadosBusqueda[] = $r;
                if (count($resultadosBusqueda) >= $cantidad) {
                    break;
                }
            }

            $mensaje = count($resultadosBusqueda) . " horario(s) libre(s) encontrado(s).";
            $esExito = count($resultadosBusqueda) > 0;
        }
    }

    // 2. Reprogramacion de partidos (modo manual)
    if ($accion === 'reprogramar') {
        $id     = intval($_POST['partido_id'] ?? 0);
        $modo   = $_POST['modo'] ?? 'manual';
        $motivo = trim($_POST['motivo'] ?? '');

        $q = $pdo->prepare($sqlPartido . " WHERE p.id = ?");
        $q->execute([$id]);
        $p = $q->fetch();

        if (!$p) {
            $mensaje = "Selecciona un partido.";
        } elseif ($p['estado'] !== 'Programado') {
            $mensaje = "Solo se pueden reprogramar partidos en estado Programado.";
        } elseif ($motivo === '') {
            $mensaje = "Escribe el motivo de la reprogramación.";
        } else {
            $nuevo = null;
            // Carga la disponibilidad quitando temporalmente este mismo partido para poder moverlo
            $disp  = Disponibilidad::cargar($pdo, min($hoy, $p['fecha']), [(int)$p['id']]);

            if ($modo === 'auto') {
                $desde = $_POST['desde'] ?? $hoy;
                if ($desde < $hoy) $desde = $hoy;

                // Marca como ocupadas las horas de hoy que ya pasaron para no agendar en el pasado
                if ($desde === $hoy) {
                    foreach (FC_HORARIOS as $h) {
                        if (substr($h, 0, 5) <= $horaActual) {
                            for ($c = 1; $c <= count(FC_CANCHAS); $c++) {
                                $disp->ocupar($hoy, $h, $c, 0, 0, 0);
                            }
                        }
                    }
                }

                // Evita que lo vuelva a dejar exactamente en el mismo horario que ya tenia
                $disp->ocupar($p['fecha'], $p['hora'], $p['cancha'], $p['arbitro_id'], $p['local_id'], $p['visitante_id']);
                $criterios = [
                    'cancha'    => !empty($_POST['misma_cancha']) ? (int)$p['cancha'] : 0,
                    'arbitro'   => (int)$p['arbitro_id'],
                    'local'     => (int)$p['local_id'],
                    'visitante' => (int)$p['visitante_id']
                ];
                $nuevo = fcPrimerHorarioLibre($disp, $desde, $criterios);
                if (!$nuevo) {
                    $mensaje = "No se encontró un horario libre en los próximos 60 días.";
                }
            } else {
                //revisa que la fecha, hora y cancha elegidas por el empleado esten libres
                $fecha      = $_POST['fecha'] ?? '';
                $hora       = $_POST['hora'] ?? '';
                $cancha     = intval($_POST['cancha'] ?? 0);
                $horaInicio = substr($hora, 0, 5);

                if (!$fecha || $fecha < $hoy) {
                    $mensaje = "Selecciona una fecha válida (hoy o posterior).";
                } elseif (!in_array($hora, FC_HORARIOS, true)) {
                    $mensaje = "Selecciona un horario válido.";
                } elseif ($fecha === $hoy && $horaInicio <= $horaActual) {
                    $mensaje = "Ese horario ($hora) ya pasó para el día de hoy. Elige uno posterior a las $horaActual.";
                } elseif (!isset(FC_CANCHAS[$cancha])) {
                    $mensaje = "Selecciona una cancha válida.";
                } elseif ($fecha === $p['fecha'] && $hora === $p['hora'] && $cancha === (int)$p['cancha']) {
                    $mensaje = "El partido ya está programado en ese horario.";
                } elseif (!$disp->canchaLibre($fecha, $hora, $cancha)) {
                    $mensaje = "La cancha ya está reservada en ese horario.";
                } elseif (!$disp->arbitroLibre($fecha, $hora, $p['arbitro_id'])) {
                    $mensaje = "El árbitro ya tiene otro partido en ese horario.";
                } elseif (!$disp->equipoLibre($fecha, $hora, $p['local_id']) || !$disp->equipoLibre($fecha, $hora, $p['visitante_id'])) {
                    $mensaje = "Uno de los equipos ya tiene un partido en ese horario.";
                } else {
                    $nuevo = ['fecha' => $fecha, 'hora' => $hora, 'cancha' => $cancha];
                }
            }

            if ($nuevo) {
                try {
                    // Inicia transaccion y bloquea filas para evitar que otro usuario gane el mismo horario
                    $pdo->beginTransaction();

                    $c1 = $pdo->prepare("SELECT id FROM partidos WHERE cancha = ? AND fecha = ? AND hora = ? AND estado != 'Cancelado' AND id != ? FOR UPDATE");
                    $c1->execute([$nuevo['cancha'], $nuevo['fecha'], $nuevo['hora'], $p['id']]);

                    $c2 = $pdo->prepare("SELECT id FROM partidos WHERE arbitro_id = ? AND fecha = ? AND hora = ? AND estado != 'Cancelado' AND id != ? FOR UPDATE");
                    $c2->execute([$p['arbitro_id'], $nuevo['fecha'], $nuevo['hora'], $p['id']]);

                    $c3 = $pdo->prepare("SELECT id FROM partidos WHERE (local_id IN (?, ?) OR visitante_id IN (?, ?)) AND fecha = ? AND hora = ? AND estado != 'Cancelado' AND id != ? FOR UPDATE");
                    $c3->execute([$p['local_id'], $p['visitante_id'], $p['local_id'], $p['visitante_id'], $nuevo['fecha'], $nuevo['hora'], $p['id']]);

                    if ($c1->fetch()) {
                        $pdo->rollBack();
                        $mensaje = "La cancha acaba de ser reservada en ese horario por otro usuario.";
                    } elseif ($c2->fetch()) {
                        $pdo->rollBack();
                        $mensaje = "El árbitro acaba de ser asignado a otro partido en ese horario.";
                    } elseif ($c3->fetch()) {
                        $pdo->rollBack();
                        $mensaje = "Uno de los equipos ya tiene un partido en ese horario.";
                    } else {
                        // Guarda la nueva fecha, hora, cancha y el motivo de la reprogramacion
                        fcAplicarReprogramacion($pdo, $p, $nuevo['fecha'], $nuevo['hora'], $nuevo['cancha'], $motivo);
                        $pdo->commit();
                        $mensaje = "Partido reprogramado para el {$nuevo['fecha']} a las {$nuevo['hora']} en " . FC_CANCHAS[$nuevo['cancha']] . ".";
                        $esExito = true;

                        // Historial de movimientos: guarda el cambio de horario y el motivo
                        registrarMovimiento('Partidos', 'Reprogramación',
                            "Partido #{$p['id']} ({$p['local_nombre']} vs {$p['visitante_nombre']}): de {$p['fecha']} {$p['hora']} a {$nuevo['fecha']} {$nuevo['hora']}. Motivo: $motivo");
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $mensaje = ((string)$e->getCode() === '23000')
                        ? "El horario seleccionado acaba de ser ocupado por otro empleado."
                        : "Error al reprogramar el partido: " . $e->getMessage();
                }
            }
        }
    }

    // 3. Reprogramacion masiva por mal clima
    if ($accion === 'clima') {
        $fechaAfectada = $_POST['fecha_clima'] ?? '';
        $tipoClima     = trim($_POST['tipo_clima'] ?? 'Lluvia');
        $canchasSel    = array_map('intval', $_POST['canchas'] ?? []);
        $tiposValidos  = ['Lluvia', 'Tormenta eléctrica', 'Granizo', 'Calor extremo', 'Otro'];

        if (!$fechaAfectada || $fechaAfectada < $hoy) {
            $mensaje = "Selecciona la fecha afectada (hoy o posterior).";
        } elseif (!in_array($tipoClima, $tiposValidos, true)) {
            $mensaje = "Selecciona el tipo de clima.";
        } elseif (empty($canchasSel)) {
            $mensaje = "Selecciona al menos una cancha afectada.";
        } else {
            $q = $pdo->prepare($sqlPartido . " WHERE p.fecha = ? AND p.estado = 'Programado'");
            $q->execute([$fechaAfectada]);

            // Busqueda secuencial: filtra todos los partidos programados ese dia en las canchas afectadas
            $afectados = Busqueda::secuencialTodos($q->fetchAll(), function ($p) use ($canchasSel) {
                return in_array((int)$p['cancha'], $canchasSel, true);
            });

            if (empty($afectados)) {
                $mensaje = "No hay partidos programados en esa fecha para las canchas elegidas.";
            } else {
                // Ordenamiento burbuja: los acomoda de mas temprano a mas tarde para reubicarlos en orden justo
                $afectados = Ordenamiento::burbuja($afectados, function ($a, $b) {
                    $c = strcmp($a['hora'], $b['hora']);
                    return $c !== 0 ? $c : ((int)$a['cancha'] - (int)$b['cancha']);
                });
                $ids    = array_map(function ($p) { return (int)$p['id']; }, $afectados);
                $inicio = date('Y-m-d', strtotime($fechaAfectada . ' +1 day'));
                $disp   = Disponibilidad::cargar($pdo, $inicio, $ids);
                $motivo = "Clima: $tipoClima (" . $fechaAfectada . ")";
                $movidos = 0;

                try {
                    $pdo->beginTransaction();
                    foreach ($afectados as $p) {
                        $crit = ['cancha' => (int)$p['cancha'], 'arbitro' => (int)$p['arbitro_id'],
                                 'local' => (int)$p['local_id'], 'visitante' => (int)$p['visitante_id']];

                        // Primero busca espacio en la misma cancha en los proximos 7 dias; si no hay, busca en cualquier cancha
                        $nuevo = fcPrimerHorarioLibre($disp, $inicio, $crit, 7);
                        if (!$nuevo) {
                            $crit['cancha'] = 0;
                            $nuevo = fcPrimerHorarioLibre($disp, $inicio, $crit, 60);
                        }
                        if ($nuevo) {
                            $disp->ocupar($nuevo['fecha'], $nuevo['hora'], $nuevo['cancha'], $p['arbitro_id'], $p['local_id'], $p['visitante_id']);
                            fcAplicarReprogramacion($pdo, $p, $nuevo['fecha'], $nuevo['hora'], $nuevo['cancha'], $motivo);
                            $movidos++;
                            $resumenClima[] = ['p' => $p, 'nuevo' => $nuevo];

                            // Historial de movimientos: guarda cada partido movido por clima
                            registrarMovimiento('Partidos', 'Reprogramación por clima',
                                "Partido #{$p['id']} ({$p['local_nombre']} vs {$p['visitante_nombre']}): de {$p['fecha']} {$p['hora']} a {$nuevo['fecha']} {$nuevo['hora']}. $motivo");
                        } else {
                            $resumenClima[] = ['p' => $p, 'nuevo' => null];
                        }
                    }
                    $pdo->commit();
                    $mensaje = "$movidos de " . count($afectados) . " partido(s) reprogramado(s) por clima.";
                    $esExito = $movidos > 0;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $mensaje = "Error durante la reprogramación por clima: " . $e->getMessage();
                }
            }
        }
    }
}

$partidosProg  = $pdo->query($sqlPartido . " WHERE p.estado = 'Programado' ORDER BY p.fecha ASC, p.hora ASC")->fetchAll();
$equiposLista  = $pdo->query("SELECT id, nombre, temporada FROM equipos ORDER BY nombre ASC")->fetchAll();
$arbitrosLista = $pdo->query("SELECT id, nombre FROM arbitros WHERE estado != 'No Disponible' ORDER BY nombre ASC")->fetchAll();
$reprogramados = $pdo->query($sqlPartido . " WHERE p.reprogramaciones > 0 ORDER BY p.id DESC LIMIT 15")->fetchAll();

fcHead("Reprogramación");
?>
<body class="bg-light es-empleado">
<?php fcNavbar($usr, 'reprogramar.php'); ?>
<?php fcHero("Reprogramación y Horarios Libres", "Reprograma partidos, resuelve imprevistos de clima y encuentra horarios disponibles automáticamente"); ?>

<div class="container py-5">
    <?= fcAlerta($mensaje, $esExito) ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card feature-card p-4 h-100">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-calendar-plus text-success me-2"></i>Reprogramar partido</h5>
                <form method="POST" action="reprogramar.php">
                    <input type="hidden" name="accion" value="reprogramar">
                    <div class="mb-3">
                        <label class="form-label-minimal">Partido</label>
                        <select name="partido_id" class="form-select border-0 bg-light" required>
                            <option value="">Seleccionar partido programado...</option>
                            <?php foreach ($partidosProg as $pp): ?>
                                <option value="<?= $pp['id'] ?>"><?= esc($pp['fecha']) ?> <?= esc($pp['hora']) ?> · <?= esc($pp['local_nombre']) ?> vs <?= esc($pp['visitante_nombre']) ?> (C<?= intval($pp['cancha']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-minimal">Motivo</label>
                        <input type="text" name="motivo" class="form-control underline-input" maxlength="200" placeholder="Ej. Solicitud del equipo, falta de árbitro..." autocomplete="off" required>
                    </div>
                    <div class="btn-group btn-group-sm w-100 mb-3" role="group">
                        <input type="radio" class="btn-check" name="modo" id="modoManual" value="manual" checked onchange="cambiarModo()">
                        <label class="btn btn-outline-success" for="modoManual">Elegir horario</label>
                        <input type="radio" class="btn-check" name="modo" id="modoAuto" value="auto" onchange="cambiarModo()">
                        <label class="btn btn-outline-success" for="modoAuto">Buscar automáticamente</label>
                    </div>

                    <div id="bloqueManual">
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label-minimal">Nueva fecha</label>
                                <input type="date" name="fecha" min="<?= $hoy ?>" value="<?= $hoy ?>" class="form-control underline-input">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label-minimal">Horario</label>
                                <select name="hora" class="form-select border-0 bg-light">
                                    <?php foreach (FC_HORARIOS as $h): ?><option value="<?= $h ?>"><?= $h ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-minimal">Cancha</label>
                            <select name="cancha" class="form-select border-0 bg-light">
                                <?php foreach (FC_CANCHAS as $n => $nom): ?><option value="<?= $n ?>"><?= esc($nom) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="bloqueAuto" class="d-none">
                        <div class="mb-3">
                            <label class="form-label-minimal">Buscar a partir de</label>
                            <input type="date" name="desde" min="<?= $hoy ?>" value="<?= $hoy ?>" class="form-control underline-input">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="misma_cancha" value="1" id="mismaCancha">
                            <label class="form-check-label small" for="mismaCancha">Mantener la misma cancha</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-brand-primary w-100">Reprogramar</button>
                </form>
            </div>
        </div>

       <div class="col-lg-6">
            <div class="card feature-card p-4 h-100">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-cloud-showers-heavy text-primary me-2"></i>Reprogramación por clima</h5>
                <p class="small text-muted">Mueve todos los partidos programados del día afectado al siguiente horario libre (misma cancha si hay lugar en 7 días; si no, cualquier cancha).</p>
                <form method="POST" action="reprogramar.php" onsubmit="return confirm('¿Reprogramar todos los partidos afectados por el clima?');">
                    <input type="hidden" name="accion" value="clima">
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label-minimal">Fecha afectada</label>
                            <input type="date" name="fecha_clima" min="<?= $hoy ?>" value="<?= $hoy ?>" class="form-control underline-input" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label-minimal">Condición</label>
                            <select name="tipo_clima" class="form-select border-0 bg-light">
                                <option>Lluvia</option><option>Tormenta eléctrica</option><option>Granizo</option><option>Calor extremo</option><option>Otro</option>
                            </select>
                        </div>
                    </div>
                    <label class="form-label-minimal">Canchas afectadas</label>
                    <div class="mb-3">
                        <?php foreach (FC_CANCHAS as $n => $nom): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="canchas[]" value="<?= $n ?>" id="cl<?= $n ?>" checked>
                                <label class="form-check-label small" for="cl<?= $n ?>"><?= esc($nom) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-brand-primary w-100"><i class="fa-solid fa-cloud-rain me-1"></i> Reprogramar partidos del día</button>
                </form>
            </div>
        </div>

        <?php if (!empty($resumenClima)): ?>
        <div class="col-12">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3">Resultado de la reprogramación por clima</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Encuentro</th><th>Horario original</th><th>Nuevo horario</th></tr></thead>
                        <tbody>
                        <?php foreach ($resumenClima as $r): ?>
                            <tr>
                                <td><strong><?= esc($r['p']['local_nombre']) ?></strong> vs <strong><?= esc($r['p']['visitante_nombre']) ?></strong></td>
                                <td><?= esc($r['p']['fecha']) ?> · <?= esc($r['p']['hora']) ?> · C<?= intval($r['p']['cancha']) ?></td>
                                <td>
                                    <?php if ($r['nuevo']): ?>
                                        <span class="text-success fw-bold"><?= esc($r['nuevo']['fecha']) ?> · <?= esc($r['nuevo']['hora']) ?> · C<?= intval($r['nuevo']['cancha']) ?></span>
                                    <?php else: ?>
                                        <span class="text-danger">Sin horario libre</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-magnifying-glass-location text-success me-2"></i>Búsqueda automática de horarios libres</h5>
                <form method="POST" action="reprogramar.php" class="row g-3 align-items-end">
                    <input type="hidden" name="accion" value="buscar">
                    <div class="col-md-3">
                        <label class="form-label-minimal">Desde</label>
                        <input type="date" name="desde" min="<?= $hoy ?>" value="<?= esc($_POST['desde'] ?? $hoy) ?>" class="form-control underline-input">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-minimal">Cancha</label>
                        <select name="cancha" class="form-select border-0 bg-light">
                            <option value="0">Cualquiera</option>
                            <?php foreach (FC_CANCHAS as $n => $nom): ?><option value="<?= $n ?>"><?= esc($nom) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-minimal">Árbitro (opcional)</label>
                        <select name="arbitro_id" class="form-select border-0 bg-light">
                            <option value="0">Cualquiera</option>
                            <?php foreach ($arbitrosLista as $a): ?><option value="<?= $a['id'] ?>"><?= esc($a['nombre']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label-minimal">Cantidad</label>
                        <input type="number" name="cantidad" min="1" max="30" value="10" class="form-control underline-input">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-minimal">Equipo local (opcional)</label>
                        <select name="local_id" class="form-select border-0 bg-light">
                            <option value="0">—</option>
                            <?php foreach ($equiposLista as $e): ?><option value="<?= $e['id'] ?>"><?= esc($e['nombre']) ?> (<?= esc($e['temporada']) ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-minimal">Equipo visitante (opcional)</label>
                        <select name="visitante_id" class="form-select border-0 bg-light">
                            <option value="0">—</option>
                            <?php foreach ($equiposLista as $e): ?><option value="<?= $e['id'] ?>"><?= esc($e['nombre']) ?> (<?= esc($e['temporada']) ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4"><button class="btn btn-brand-primary w-100"><i class="fa-solid fa-magnifying-glass me-1"></i> Buscar horarios</button></div>
                </form>

                <?php if ($resultadosBusqueda !== null): ?>
                    <div class="table-responsive mt-4">
                        <table class="table align-middle">
                            <thead><tr><th>#</th><th>Fecha</th><th>Horario</th><th>Cancha</th></tr></thead>
                            <tbody>
                            <?php if (empty($resultadosBusqueda)): ?>
                                <tr><td colspan="4"><div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Sin horarios libres con esos criterios</h6></div></td></tr>
                            <?php else: foreach ($resultadosBusqueda as $i => $r): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td class="fw-bold"><?= esc($r['fecha']) ?></td>
                                    <td><?= esc($r['hora']) ?></td>
                                    <td><span class="badge bg-success-subtle text-success border border-success"><?= esc(FC_CANCHAS[$r['cancha']]) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3">Partidos reprogramados recientemente</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Encuentro</th><th>Original</th><th>Actual</th><th>Veces</th><th>Motivo</th></tr></thead>
                        <tbody>
                        <?php if (empty($reprogramados)): ?>
                            <tr><td colspan="5"><div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Aún no hay partidos reprogramados</h6></div></td></tr>
                        <?php else: foreach ($reprogramados as $r): ?>
                            <tr>
                                <td><strong><?= esc($r['local_nombre']) ?></strong> vs <strong><?= esc($r['visitante_nombre']) ?></strong></td>
                                <td class="small text-muted"><?= esc($r['fecha_original']) ?> · <?= esc($r['hora_original']) ?></td>
                                <td class="small"><?= esc($r['fecha']) ?> · <?= esc($r['hora']) ?> · C<?= intval($r['cancha']) ?></td>
                                <td><span class="badge bg-warning-subtle text-warning border border-warning"><?= intval($r['reprogramaciones']) ?></span></td>
                                <td class="small"><?= esc($r['motivo_reprogramacion']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function cambiarModo() {
        var auto = document.getElementById('modoAuto').checked;
        document.getElementById('bloqueManual').classList.toggle('d-none', auto);
        document.getElementById('bloqueAuto').classList.toggle('d-none', !auto);
    }
</script>
<?php fcPie(); ?>