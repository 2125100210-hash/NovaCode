<?php
// Pantalla de colas de arbitros
// Aqui se acomodan los arbitros en una fila por orden de llegada
require_once "conexion.php";
$usr = protegerPagina();

$mensaje = "";
$esExito = false;

// Revisa que exista la columna turno_cola para guardar el orden de la fila en la base de datos
if (!fcColumnaExiste($pdo, 'arbitros', 'turno_cola')) {
    $pdo->exec("ALTER TABLE arbitros ADD COLUMN turno_cola INT NOT NULL DEFAULT 0");
    $pdo->exec("UPDATE arbitros SET turno_cola = id WHERE turno_cola = 0");
} else {
    // Si se registro un arbitro nuevo con turno en 0, lo forma hasta el final de la fila
    $pendientes = $pdo->query("SELECT id FROM arbitros WHERE turno_cola = 0 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($pendientes)) {
        $maxTurno = (int)$pdo->query("SELECT COALESCE(MAX(turno_cola), 0) FROM arbitros")->fetchColumn();
        $updTurno = $pdo->prepare("UPDATE arbitros SET turno_cola = ? WHERE id = ?");
        foreach ($pendientes as $aid) {
            $maxTurno++;
            $updTurno->execute([$maxTurno, (int)$aid]);
        }
    }
}

// Crea una Cola que solo acepta arreglos y mete a todos los arbitros disponibles segun su turno
function construirColaArbitros(PDO $pdo)
{
    $filas = $pdo->query("SELECT a.id, a.nombre, a.estado, a.turno_cola, a.foto,
                                 COUNT(p.id) AS asignados, MAX(p.fecha) AS ultima
                          FROM arbitros a
                          LEFT JOIN partidos p ON p.arbitro_id = a.id AND p.estado != 'Cancelado'
                          WHERE a.estado != 'No Disponible'
                          GROUP BY a.id, a.nombre, a.estado, a.turno_cola, a.foto
                          ORDER BY a.turno_cola ASC, a.id ASC")->fetchAll();

    // Usamos la clase generica Cola para formarlos uno detras de otro
    $cola = new Cola('array');
    foreach ($filas as $f) {
        $cola->encolar($f);
    }
    return $cola;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'asignar_siguiente') {
    $id = intval($_POST['partido_id'] ?? 0);
    $q = $pdo->prepare("SELECT * FROM partidos WHERE id = ?");
    $q->execute([$id]);
    $p = $q->fetch();

    if (!$p) {
        $mensaje = "Selecciona un partido.";
    } elseif ($p['estado'] !== 'Programado') {
        $mensaje = "Solo se asignan árbitros a partidos Programados.";
    } else {
        $cola = construirColaArbitros($pdo);
        $disp = Disponibilidad::cargar($pdo, $p['fecha'], [(int)$p['id']]);
        $saltados = [];
        $elegido  = null;

        // Va sacando uno por uno desde el frente de la cola hasta encontrar uno libre a esa hora
        while (!$cola->estaVacia()) {
            $arb = $cola->desencolar();
            if ((int)$arb['id'] === (int)$p['arbitro_id']) {
                $saltados[] = $arb['nombre'] . " (ya es el árbitro actual)";
                continue;
            }
            if (!$disp->arbitroLibre($p['fecha'], $p['hora'], $arb['id'])) {
                $saltados[] = $arb['nombre'] . " (ocupado en ese horario)";
                continue;
            }
            $elegido = $arb;
            break;
        }

        if (!$elegido) {
            $mensaje = "Ningún árbitro está libre para ese horario.";
        } else {
            try {
                $pdo->beginTransaction();

                // Asigna el arbitro elegido al partido
                $pdo->prepare("UPDATE partidos SET arbitro_id = ?, asistencia_arbitro = 'Pendiente' WHERE id = ?")
                    ->execute([$elegido['id'], $p['id']]);

                // Como ya le toco partido, lo manda hasta el final de la cola
                $nuevoTurno = (int)$pdo->query("SELECT COALESCE(MAX(turno_cola), 0) + 1 FROM arbitros")->fetchColumn();
                $pdo->prepare("UPDATE arbitros SET turno_cola = ? WHERE id = ?")
                    ->execute([$nuevoTurno, $elegido['id']]);

                $pdo->commit();

                $mensaje = "Árbitro asignado: " . $elegido['nombre'] . " (pasó al final de la cola).";
                if ($saltados) {
                    $mensaje .= " Omitidos: " . implode(', ', $saltados) . ".";
                }
                $esExito = true;

                // Guarda el registro de quien cambio el arbitro desde la cola
                registrarMovimiento('Árbitros', 'Asignación desde la cola', "Partido #{$p['id']}: se asignó al árbitro {$elegido['nombre']} (siguiente en cola FIFO).");
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $mensaje = "Error al asignar árbitro: " . $e->getMessage();
            }
        }
    }
}

// Carga la cola actualizada para mostrarla en la lista izquierda
$cola = construirColaArbitros($pdo);
$colaLista = $cola->aArray();

// Carga los partidos programados para poder asignarles el siguiente arbitro de la fila
$partidos = $pdo->query("SELECT p.id, p.fecha, p.hora, p.cancha, a.nombre AS arbitro, a.estado AS estado_arbitro,
                         el.nombre AS local_nombre, ev.nombre AS visitante_nombre
                         FROM partidos p
                         INNER JOIN equipos el ON p.local_id = el.id
                         INNER JOIN equipos ev ON p.visitante_id = ev.id
                         INNER JOIN arbitros a ON p.arbitro_id = a.id
                         WHERE p.estado = 'Programado' ORDER BY p.fecha ASC, p.hora ASC")->fetchAll();

fcHead("Cola de árbitros");
?>
<body class="bg-light es-empleado">
<?php fcNavbar($usr, 'arbitros_cola.php'); ?>
<?php fcHero("Fila de Árbitros", "Turnos rotativos por orden de llegada"); ?>

<div class="container py-5">
    <?= fcAlerta($mensaje, $esExito) ?>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-people-arrows text-success me-2"></i>Fila de turnos</h5>
                <div class="small text-muted mb-3">Árbitros en cola: <strong><?= $cola->tamano() ?></strong> · Se excluyen los "No Disponible".</div>
                <?php if (empty($colaLista)): ?>
                    <div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">No hay árbitros disponibles</h6></div>
                <?php else: ?>
                    <ol class="list-group list-group-numbered">
                        <?php foreach ($colaLista as $i => $a): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-start <?= $i === 0 ? 'list-group-item-success' : '' ?>">
                                <div class="ms-2 me-auto d-flex align-items-center gap-2">
                                  <?= fcFoto($a['foto'] ?? '', 'persona', 40) ?>
                                  <div>
                                    <div class="fw-bold"><?= esc($a['nombre']) ?> <?= $i === 0 ? '<span class="badge bg-success ms-1">FRENTE</span>' : '' ?></div>
                                    <small class="text-muted"><?= intval($a['asignados']) ?> partido(s) · último: <?= esc($a['ultima'] ?: 'nunca') ?></small>
                                  </div>
                                </div>
                                <span class="badge bg-light text-dark border"><?= esc($a['estado']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>

       <div class="col-lg-8">
            <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3">Asignar el siguiente árbitro de la cola</h5>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead><tr><th>Fecha y hora</th><th>Encuentro</th><th>Cancha</th><th>Árbitro actual</th><th></th></tr></thead>
                        <tbody>
                        <?php if (empty($partidos)): ?>
                            <tr><td colspan="5"><div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">No hay partidos programados</h6></div></td></tr>
                        <?php else: foreach ($partidos as $p): ?>
                            <tr>
                                <td><div class="fw-bold"><?= esc($p['fecha']) ?></div><div class="small text-muted"><?= esc($p['hora']) ?></div></td>
                                <td><strong><?= esc($p['local_nombre']) ?></strong> <span class="text-muted">vs</span> <strong><?= esc($p['visitante_nombre']) ?></strong></td>
                                <td>C<?= intval($p['cancha']) ?></td>
                                <td><?= esc($p['arbitro']) ?></td>
                                <td>
                                    <form method="POST" action="arbitros_cola.php" class="d-inline" onsubmit="return confirm('¿Reasignar el árbitro con el siguiente de la cola?');">
                                        <input type="hidden" name="accion" value="asignar_siguiente">
                                        <input type="hidden" name="partido_id" value="<?= $p['id'] ?>">
                                        <button class="btn btn-sm btn-brand-outline">Siguiente de la cola</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php fcPie(); ?>