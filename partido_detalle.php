<?php
// Aqui se anotan los goles usando una pila
// se registran tarjetas amarillas/rojas y se finaliza, cancela o reabre el partido
require_once "conexion.php";
$usr = protegerPagina();

$mensaje = "";
$esExito = false;
$partidoId = intval($_GET['id'] ?? $_POST['partido_id'] ?? 0);

// Recupera los mensajes de aviso guardados despues de recargar la pagina
if (isset($_SESSION['flash'])) {
    $mensaje = $_SESSION['flash']['mensaje'] ?? "";
    $esExito = $_SESSION['flash']['esExito'] ?? false;
    unset($_SESSION['flash']);
}

// Busca los datos completos del partido seleccionado
$partido = null;
if ($partidoId) {
    $q = $pdo->prepare("SELECT p.*, el.nombre AS local_nombre, el.foto AS local_foto, ev.nombre AS visitante_nombre, ev.foto AS visitante_foto,
                               a.nombre AS arbitro_nombre, a.foto AS arbitro_foto
                        FROM partidos p
                        INNER JOIN equipos el ON p.local_id = el.id
                        INNER JOIN equipos ev ON p.visitante_id = ev.id
                        INNER JOIN arbitros a ON p.arbitro_id = a.id
                        WHERE p.id = ?");
    $q->execute([$partidoId]);
    $partido = $q->fetch();
}

// Procesa las acciones sobre el partido 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $partido) {
    $accion  = $_POST['accion'] ?? '';
    $cerrado = ($partido['estado'] === 'Finalizado');

    // Revisa si ya llego la fecha y hora de inicio del encuentro
    $horaInicio    = substr($partido['hora'], 0, 5);
    $inicioPartido = $partido['fecha'] . ' ' . $horaInicio;
    $yaInicio      = (date('Y-m-d H:i') >= $inicioPartido);

    $qj = $pdo->prepare("SELECT id, nombre, numero, equipo_id FROM jugadores WHERE equipo_id IN (?, ?)");
    $qj->execute([$partido['local_id'], $partido['visitante_id']]);
    $jugadoresPartido = $qj->fetchAll();

    // 1. Registro de goles (apila el gol nuevo y actualiza el marcador del partido y del jugador)
    if ($accion === 'gol') {
        $jugadorId = intval($_POST['jugador_id'] ?? 0);
        $minuto    = intval($_POST['minuto'] ?? -1);

        // Busqueda secuencial: revisa uno por uno los jugadores del encuentro hasta hallar el elegido
        $idx = Busqueda::secuencial($jugadoresPartido, function ($j) use ($jugadorId) {
            return (int)$j['id'] === $jugadorId;
        });

        if ($partido['estado'] === 'Cancelado') {
            $mensaje = "El partido está cancelado.";
        } elseif ($cerrado) {
            $mensaje = "El partido está finalizado. Reábrelo para modificar los goles.";
        } elseif (!$yaInicio) {
            $mensaje = "No se pueden registrar goles antes de que inicie el partido ({$partido['fecha']} a las $horaInicio).";
        } elseif ($idx < 0) {
            $mensaje = "Selecciona un jugador de alguno de los dos equipos.";
        } elseif ($minuto < 0 || $minuto > 120) {
            $mensaje = "El minuto debe estar entre 0 y 120.";
        } else {
            $j = $jugadoresPartido[$idx];
            $pdo->beginTransaction();
            $ins = $pdo->prepare("INSERT INTO goles (partido_id, jugador_id, equipo_id, minuto, registrado_por) VALUES (?, ?, ?, ?, ?)");
            $ins->execute([$partidoId, $j['id'], $j['equipo_id'], $minuto, $usr['nombre']]);

            // Recalcula los goles del partido y los goles totales del jugador
            fcSincronizarGoles($pdo, $partidoId, (int)$j['id']);
            $pdo->commit();

            $mensaje = "Gol registrado: " . $j['nombre'] . " (min " . $minuto . ").";
            $esExito = true;

            registrarMovimiento('Partidos', 'Gol registrado', "Partido #$partidoId: gol de {$j['nombre']} (#{$j['numero']}) al minuto $minuto.");
        }
    }

    // 2. Pilas (LIFO - ultimo en entrar, primero en salir): deshace el ultimo gol anotado en el partido
    if ($accion === 'deshacer_gol') {
        if ($cerrado) {
            $mensaje = "El partido está finalizado. Reábrelo para modificar los goles.";
        } elseif ($partido['estado'] === 'Cancelado') {
            $mensaje = "El partido está cancelado.";
        } else {
            // Usa la clase generica Pila para meter todos los goles en orden y sacar el del tope con pop()
            $pila = new Pila('array');
            $qg = $pdo->prepare("SELECT g.*, j.nombre AS jugador FROM goles g INNER JOIN jugadores j ON g.jugador_id = j.id WHERE g.partido_id = ? ORDER BY g.id ASC");
            $qg->execute([$partidoId]);
            foreach ($qg->fetchAll() as $g) {
                $pila->push($g);
            }
            $ultimo = $pila->pop();

            if ($ultimo === null) {
                $mensaje = "No hay goles para deshacer.";
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM goles WHERE id = ?")->execute([$ultimo['id']]);

                // Recalcula el marcador del partido y los goles del jugador despues de quitar el gol del tope
                fcSincronizarGoles($pdo, $partidoId, (int)$ultimo['jugador_id']);
                $pdo->commit();

                $mensaje = "Se deshizo el gol de " . $ultimo['jugador'] . " (min " . $ultimo['minuto'] . ").";
                $esExito = true;

                // Historial de movimientos: guarda que se deshizo el ultimo gol
                registrarMovimiento('Partidos', 'Gol deshecho', "Partido #$partidoId: se deshizo el gol de {$ultimo['jugador']} (min {$ultimo['minuto']}).");
            }
        }
    }

    // 3. Registro de tarjetas
    if ($accion === 'tarjeta') {
        $jugadorId = intval($_POST['jugador_id'] ?? 0);
        $minuto    = intval($_POST['minuto'] ?? -1);
        $tipo      = $_POST['tipo'] ?? '';

        // Busqueda secuencial para encontrar al jugador sancionado
        $idx = Busqueda::secuencial($jugadoresPartido, function ($j) use ($jugadorId) {
            return (int)$j['id'] === $jugadorId;
        });

        if ($partido['estado'] === 'Cancelado') {
            $mensaje = "El partido está cancelado.";
        } elseif ($cerrado) {
            $mensaje = "El partido está finalizado. Reábrelo para registrar tarjetas.";
        } elseif (!$yaInicio) {
            $mensaje = "No se pueden registrar tarjetas antes de que inicie el partido ({$partido['fecha']} a las $horaInicio).";
        } elseif ($idx < 0) {
            $mensaje = "Selecciona un jugador de alguno de los dos equipos.";
        } elseif (!in_array($tipo, ['Amarilla', 'Roja'], true)) {
            $mensaje = "Selecciona el tipo de tarjeta.";
        } elseif ($minuto < 0 || $minuto > 120) {
            $mensaje = "El minuto debe estar entre 0 y 120.";
        } else {
            $j = $jugadoresPartido[$idx];
            $qt = $pdo->prepare("SELECT tipo FROM tarjetas WHERE partido_id = ? AND jugador_id = ?");
            $qt->execute([$partidoId, $j['id']]);
            $previas   = $qt->fetchAll();

            // Revisa con busqueda secuencial si el jugador ya tenia amarillas o roja previa
            $amarillas = count(Busqueda::secuencialTodos($previas, function ($t) { return $t['tipo'] === 'Amarilla'; }));
            $expulsado = Busqueda::secuencial($previas, function ($t) { return $t['tipo'] === 'Roja'; }) >= 0;

            if ($expulsado) {
                $mensaje = $j['nombre'] . " ya fue expulsado en este partido.";
            } else {
                $ins = $pdo->prepare("INSERT INTO tarjetas (partido_id, jugador_id, equipo_id, tipo, minuto, registrado_por) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$partidoId, $j['id'], $j['equipo_id'], $tipo, $minuto, $usr['nombre']]);
                $mensaje = "Tarjeta $tipo registrada a " . $j['nombre'] . ".";

                // Si ya tenia una amarilla y recibe otra, le saca roja en automatico
                if ($tipo === 'Amarilla' && $amarillas >= 1) {
                    $ins->execute([$partidoId, $j['id'], $j['equipo_id'], 'Roja', $minuto, $usr['nombre']]);
                    $mensaje .= " Segunda amarilla: expulsado (roja automática).";
                }
                $esExito = true;

                // Historial de movimientos: guarda el registro de la tarjeta
                registrarMovimiento('Partidos', 'Tarjeta registrada', "Partido #$partidoId: tarjeta $tipo a {$j['nombre']} (#{$j['numero']}) al minuto $minuto.");
            }
        }
    }

    // 4. Eliminar una tarjeta registrada por error
    if ($accion === 'eliminar_tarjeta') {
        $tid = intval($_POST['tarjeta_id'] ?? 0);
        if ($cerrado) {
            $mensaje = "El partido está finalizado. Reábrelo para modificar tarjetas.";
        } elseif ($partido['estado'] === 'Cancelado') {
            $mensaje = "El partido está cancelado.";
        } else {
            $pdo->prepare("DELETE FROM tarjetas WHERE id = ? AND partido_id = ?")->execute([$tid, $partidoId]);
            $mensaje = "Tarjeta eliminada.";
            $esExito = true;
            registrarMovimiento('Partidos', 'Tarjeta eliminada', "Partido #$partidoId: se eliminó la tarjeta #$tid.");
        }
    }

    // 5. Finalizar partido
    if ($accion === 'finalizar') {
        if ($partido['estado'] === 'Cancelado') {
            $mensaje = "El partido está cancelado.";
        } elseif (!$yaInicio) {
            $mensaje = "No se puede finalizar el partido antes de su horario programado ({$partido['fecha']} a las $horaInicio).";
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE partidos SET estado = 'Finalizado' WHERE id = ?")->execute([$partidoId]);
            fcSincronizarGoles($pdo, $partidoId);
            $pdo->commit();
            $mensaje = "Partido finalizado.";
            $esExito = true;
            registrarMovimiento('Partidos', 'Partido finalizado', "Partido #$partidoId finalizado.");
        }
    }

    // 6. Cancelacion de partido
    if ($accion === 'cancelar') {
        if ($partido['estado'] !== 'Programado') {
            $mensaje = "Solo se pueden cancelar partidos en estado Programado.";
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE partidos SET estado = 'Cancelado', goles_local = NULL, goles_visitante = NULL WHERE id = ? AND estado = 'Programado'")->execute([$partidoId]);
            fcSincronizarGoles($pdo);
            $pdo->commit();
            $mensaje = "Partido cancelado. La cancha, el árbitro y los equipos han quedado liberados.";
            $esExito = true;
            registrarMovimiento('Partidos', 'Cancelación', "Partido #$partidoId ({$partido['local_nombre']} vs {$partido['visitante_nombre']}) cancelado.");
        }
    }

    // 7. Reabrir un partido finalizado por si falto corregir algun gol o tarjeta
    if ($accion === 'reabrir') {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE partidos SET estado = 'Programado' WHERE id = ? AND estado = 'Finalizado'")->execute([$partidoId]);
        fcSincronizarGoles($pdo, $partidoId);
        $pdo->commit();
        $mensaje = "Partido reabierto.";
        $esExito = true;
        registrarMovimiento('Partidos', 'Partido reabierto', "Partido #$partidoId reabierto.");
    }

    $_SESSION['flash'] = [
        'mensaje' => $mensaje,
        'esExito' => $esExito
    ];
    header("Location: partido_detalle.php?id=" . $partidoId);
    exit;
}

$listaPartidos = [];
$goles = $tarjetas = $jugadores = [];
$pila = new Pila('array');

// Si hay un partido seleccionado, carga su pila de goles, sus tarjetas y los jugadores disponibles
if ($partido) {
    $qg = $pdo->prepare("SELECT g.*, j.nombre AS jugador, j.numero, e.nombre AS equipo
                         FROM goles g INNER JOIN jugadores j ON g.jugador_id = j.id
                         INNER JOIN equipos e ON g.equipo_id = e.id
                         WHERE g.partido_id = ? ORDER BY g.id ASC");
    $qg->execute([$partidoId]);
    foreach ($qg->fetchAll() as $g) {
        $pila->push($g);
    }
    // aArray() devuelve los elementos desde el tope hacia abajo (el ultimo gol aparece primero)
    $golesTope = $pila->aArray();

    $qt = $pdo->prepare("SELECT t.*, j.nombre AS jugador, j.numero, e.nombre AS equipo
                         FROM tarjetas t INNER JOIN jugadores j ON t.jugador_id = j.id
                         INNER JOIN equipos e ON t.equipo_id = e.id
                         WHERE t.partido_id = ? ORDER BY t.id ASC");
    $qt->execute([$partidoId]);
    $tarjetas = $qt->fetchAll();

    $qj = $pdo->prepare("SELECT j.id, j.nombre, j.numero, j.equipo_id, e.nombre AS equipo
                         FROM jugadores j INNER JOIN equipos e ON j.equipo_id = e.id
                         WHERE j.equipo_id IN (?, ?) ORDER BY e.nombre, j.numero");
    $qj->execute([$partido['local_id'], $partido['visitante_id']]);
    $jugadores = $qj->fetchAll();
} else {
    // Si entraron sin elegir partido, muestra la lista de los ultimos 100 partidos para escoger uno
    $listaPartidos = $pdo->query("SELECT p.id, p.fecha, p.hora, p.estado, p.goles_local, p.goles_visitante,
                                  el.nombre AS local_nombre, ev.nombre AS visitante_nombre
                                  FROM partidos p
                                  INNER JOIN equipos el ON p.local_id = el.id
                                  INNER JOIN equipos ev ON p.visitante_id = ev.id
                                  ORDER BY p.fecha DESC, p.hora ASC LIMIT 100")->fetchAll();
}

fcHead("Goles y tarjetas");
?>
<body class="bg-light es-empleado">
<?php fcNavbar($usr, 'partido_detalle.php'); ?>
<?php fcHero("Goles y Tarjetas por Partido", "Registro de goles y control de tarjetas"); ?>

<div class="container py-5">
<?php if (!$partido): ?>
   <div class="card feature-card p-4">
        <h5 class="fw-bold mb-3">Selecciona un partido</h5>
        <?php if ($partidoId): ?><div class="alert alert-danger py-2">El partido no existe.</div><?php endif; ?>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Fecha y hora</th><th>Encuentro</th><th>Marcador</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($listaPartidos)): ?>
                    <tr><td colspan="5"><div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Sin partidos registrados</h6></div></td></tr>
                <?php else: foreach ($listaPartidos as $lp): ?>
                    <?php
                        if ($lp['estado'] === 'Finalizado') {
                            $badgeLista = '<span class="badge bg-dark">Finalizado</span>';
                        } elseif ($lp['estado'] === 'Cancelado') {
                            $badgeLista = '<span class="badge bg-danger-subtle text-danger border border-danger">Cancelado</span>';
                        } else {
                            $badgeLista = '<span class="badge bg-primary-subtle text-primary border border-primary">' . esc($lp['estado']) . '</span>';
                        }
                    ?>
                    <tr>
                        <td><div class="fw-bold"><?= esc($lp['fecha']) ?></div><div class="small text-muted"><?= esc($lp['hora']) ?></div></td>
                        <td><strong><?= esc($lp['local_nombre']) ?></strong> <span class="text-muted">vs</span> <strong><?= esc($lp['visitante_nombre']) ?></strong></td>
                        <td><?= $lp['goles_local'] !== null ? intval($lp['goles_local']) . ' - ' . intval($lp['goles_visitante']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $badgeLista ?></td>
                        <td><a class="btn btn-sm btn-brand-outline" href="partido_detalle.php?id=<?= $lp['id'] ?>">Goles / Tarjetas</a></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <?= fcAlerta($mensaje, $esExito) ?>

    <?php
        if ($partido['estado'] === 'Finalizado') {
            $badgeDetalle = 'bg-dark';
        } elseif ($partido['estado'] === 'Cancelado') {
            $badgeDetalle = 'bg-danger-subtle text-danger border border-danger';
        } else {
            $badgeDetalle = 'bg-primary-subtle text-primary border border-primary';
        }
    ?>

    <div class="card feature-card p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="text-muted small d-flex align-items-center flex-wrap gap-2">
                    <span><?= esc($partido['fecha']) ?> · <?= esc($partido['hora']) ?> · <?= esc(FC_CANCHAS[$partido['cancha']] ?? 'Cancha ' . $partido['cancha']) ?></span>
                    <span class="equipo-mini">· Árbitro: <?= fcFoto($partido['arbitro_foto'] ?? '', 'persona', 26) ?><?= esc($partido['arbitro_nombre']) ?></span>
                </div>
                <h3 class="fw-bold mb-0 mt-2 d-flex align-items-center flex-wrap gap-2">
                    <span class="equipo-mini"><?= fcFoto($partido['local_foto'] ?? '', 'club', 48) ?><?= esc($partido['local_nombre']) ?></span>
                    <span class="mx-2 text-success"><?= intval($partido['goles_local'] ?? 0) ?> - <?= intval($partido['goles_visitante'] ?? 0) ?></span>
                    <span class="equipo-mini"><?= fcFoto($partido['visitante_foto'] ?? '', 'club', 48) ?><?= esc($partido['visitante_nombre']) ?></span>
                </h3>
                <?php if (!empty($partido['reprogramaciones'])): ?>
                    <div class="small text-warning mt-1"><i class="fa-solid fa-calendar-plus me-1"></i>Reprogramado <?= intval($partido['reprogramaciones']) ?> vez/veces — <?= esc($partido['motivo_reprogramacion']) ?></div>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <span class="badge <?= $badgeDetalle ?> py-2 px-3"><?= esc($partido['estado']) ?></span>
                <?php if ($partido['estado'] === 'Finalizado'): ?>
                    <form method="POST" action="partido_detalle.php?id=<?= $partidoId ?>" class="d-inline">
                        <input type="hidden" name="partido_id" value="<?= $partidoId ?>">
                        <input type="hidden" name="accion" value="reabrir">
                        <button class="btn btn-sm btn-outline-secondary">Reabrir partido</button>
                    </form>
                <?php elseif ($partido['estado'] === 'Programado'): ?>
                    <form method="POST" action="partido_detalle.php?id=<?= $partidoId ?>" class="d-inline">
                        <input type="hidden" name="partido_id" value="<?= $partidoId ?>">
                        <input type="hidden" name="accion" value="finalizar">
                        <button class="btn btn-sm btn-brand-primary" onclick="return confirm('¿Finalizar el partido con el marcador actual?');">Finalizar partido</button>
                    </form>
                    <form method="POST" action="partido_detalle.php?id=<?= $partidoId ?>" class="d-inline">
                        <input type="hidden" name="partido_id" value="<?= $partidoId ?>">
                        <input type="hidden" name="accion" value="cancelar">
                        <button class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Cancelar este partido? Se liberará la cancha, el árbitro y los equipos.');">Cancelar partido</button>
                    </form>
                <?php endif; ?>
                <a href="partido_detalle.php" class="btn btn-sm btn-outline-secondary">Otro partido</a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card feature-card p-4 h-100">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-futbol text-success me-2"></i>Goles (pila)</h5>
                <form method="POST" action="partido_detalle.php?id=<?= $partidoId ?>" class="row g-2 mb-3">
                    <input type="hidden" name="accion" value="gol">
                    <input type="hidden" name="partido_id" value="<?= $partidoId ?>">
                    <div class="col-8">
                        <select name="jugador_id" class="form-select border-0 bg-light" required>
                            <option value="">Jugador que anotó...</option>
                            <?php foreach ($jugadores as $j): ?>
                                <option value="<?= $j['id'] ?>"><?= esc($j['equipo']) ?> · #<?= intval($j['numero']) ?> <?= esc($j['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4"><input type="number" name="minuto" min="0" max="120" placeholder="Minuto" class="form-control underline-input" required></div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-brand-primary btn-sm flex-fill">Registrar gol (push)</button>
                    </div>
                </form>
                <form method="POST" action="partido_detalle.php?id=<?= $partidoId ?>" class="mb-3">
                    <input type="hidden" name="accion" value="deshacer_gol">
                    <input type="hidden" name="partido_id" value="<?= $partidoId ?>">
                    <button class="btn btn-outline-danger btn-sm w-100" <?= $pila->estaVacia() ? 'disabled' : '' ?>>
                        <i class="fa-solid fa-rotate-left me-1"></i> Deshacer último gol (pop)
                    </button>
                </form>

                <div class="small text-muted mb-2">Tamaño de la pila: <strong><?= $pila->tamano() ?></strong></div>
                <?php if ($pila->estaVacia()): ?>
                    <div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Sin goles registrados</h6></div>
                <?php else: ?>
                    <ul class="list-group">
                        <?php foreach ($golesTope as $i => $g): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center <?= $i === 0 ? 'list-group-item-success' : '' ?>">
                                <span>
                                    <?= $i === 0 ? '<span class="badge bg-success me-2">TOPE</span>' : '' ?>
                                    <strong><?= esc($g['jugador']) ?></strong> <span class="text-muted">(<?= esc($g['equipo']) ?>)</span>
                                </span>
                                <span class="badge bg-dark">min <?= intval($g['minuto']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

       <div class="col-lg-6">
            <div class="card feature-card p-4 h-100">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-square text-warning me-2"></i>Tarjetas</h5>
                <form method="POST" action="partido_detalle.php?id=<?= $partidoId ?>" class="row g-2 mb-3">
                    <input type="hidden" name="accion" value="tarjeta">
                    <input type="hidden" name="partido_id" value="<?= $partidoId ?>">
                    <div class="col-12">
                        <select name="jugador_id" class="form-select border-0 bg-light" required>
                            <option value="">Jugador sancionado...</option>
                            <?php foreach ($jugadores as $j): ?>
                                <option value="<?= $j['id'] ?>"><?= esc($j['equipo']) ?> · #<?= intval($j['numero']) ?> <?= esc($j['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <select name="tipo" class="form-select border-0 bg-light" required>
                            <option value="Amarilla">Amarilla</option>
                            <option value="Roja">Roja</option>
                        </select>
                    </div>
                    <div class="col-6"><input type="number" name="minuto" min="0" max="120" placeholder="Minuto" class="form-control underline-input" required></div>
                    <div class="col-12"><button class="btn btn-brand-primary btn-sm w-100">Registrar tarjeta</button></div>
                </form>

                <?php if (empty($tarjetas)): ?>
                    <div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Sin tarjetas registradas</h6></div>
                <?php else: ?>
                    <ul class="list-group">
                        <?php foreach ($tarjetas as $t): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>
                                    <i class="fa-solid fa-square <?= $t['tipo'] === 'Roja' ? 'text-danger' : 'text-warning' ?> me-2"></i>
                                    <strong><?= esc($t['jugador']) ?></strong> <span class="text-muted">(<?= esc($t['equipo']) ?>) · min <?= intval($t['minuto']) ?></span>
                                </span>
                                <form method="POST" action="partido_detalle.php?id=<?= $partidoId ?>" class="d-inline" onsubmit="return confirm('¿Eliminar esta tarjeta?');">
                                    <input type="hidden" name="accion" value="eliminar_tarjeta">
                                    <input type="hidden" name="partido_id" value="<?= $partidoId ?>">
                                    <input type="hidden" name="tarjeta_id" value="<?= $t['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
<?php fcPie(); ?>