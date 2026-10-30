<?php
// Panel general de administracion
require_once "conexion.php";

// Permisos de administrador: si entra un empleado normal, esta funcion le bloquea el paso
$usr = protegerAdmin();

// Funcion de ayuda para contar cuantos registros hay en cada tabla
function contar(PDO $pdo, $sql)
{
    return (int)$pdo->query($sql)->fetchColumn();
}

// Tarjetas con los totales generales del sistema
$stats = [
    ['Empleados activos',      contar($pdo, "SELECT COUNT(*) FROM usuarios WHERE activo = 1"), 'fa-users-gear',        'empleados.php'],
    ['Equipos',                contar($pdo, "SELECT COUNT(*) FROM equipos"),                   'fa-shield-halved',     'equipos.php'],
    ['Jugadores',              contar($pdo, "SELECT COUNT(*) FROM jugadores"),                 'fa-person-running',    'jugadores.php'],
    ['Árbitros',               contar($pdo, "SELECT COUNT(*) FROM arbitros"),                  'fa-flag',              'arbitros.php'],
    ['Partidos programados',   contar($pdo, "SELECT COUNT(*) FROM partidos WHERE estado = 'Programado'"), 'fa-calendar-check', 'partidos.php'],
    ['Partidos finalizados',   contar($pdo, "SELECT COUNT(*) FROM partidos WHERE estado = 'Finalizado'"), 'fa-flag-checkered', 'posiciones.php'],
    ['Goles registrados',      contar($pdo, "SELECT COUNT(*) FROM goles"),                     'fa-futbol',            'partido_detalle.php'],
    ['Tarjetas',               contar($pdo, "SELECT COUNT(*) FROM tarjetas"),                  'fa-square',            'partido_detalle.php'],
    ['Reprogramados',          contar($pdo, "SELECT COUNT(*) FROM partidos WHERE reprogramaciones > 0"), 'fa-calendar-plus', 'reprogramar.php'],
    ['Movimientos (24 h)',     contar($pdo, "SELECT COUNT(*) FROM historial WHERE creado_en >= (NOW() - INTERVAL 1 DAY)"), 'fa-clock-rotate-left', 'historial.php'],
];

$proximos = $pdo->query("SELECT p.id, p.fecha, p.hora, p.cancha, el.nombre AS local_nombre, ev.nombre AS visitante_nombre, a.nombre AS arbitro
FROM partidos p
INNER JOIN equipos el ON p.local_id = el.id
INNER JOIN equipos ev ON p.visitante_id = ev.id
INNER JOIN arbitros a ON p.arbitro_id = a.id
WHERE p.estado = 'Programado' AND p.fecha >= CURDATE()
ORDER BY p.fecha ASC, p.hora ASC LIMIT 6")->fetchAll();

$movimientos = $pdo->query("SELECT usuario, modulo, accion, detalle, creado_en FROM historial ORDER BY id DESC LIMIT 8")->fetchAll();

$todosGoleadores = $pdo->query("SELECT j.nombre, COUNT(g.id) AS goles, e.nombre AS equipo
FROM jugadores j
INNER JOIN equipos e ON j.equipo_id = e.id
INNER JOIN goles g ON g.jugador_id = j.id
INNER JOIN partidos p ON g.partido_id = p.id
WHERE p.estado != 'Cancelado'
GROUP BY j.id, j.nombre, e.nombre")->fetchAll();

// Ordenamiento burbuja: acomoda la lista de goleadores de mayor a menor antes de sacar el Top 5
$todosGoleadores = Ordenamiento::burbuja($todosGoleadores, function ($a, $b) {
    $diff = (int)$b['goles'] - (int)$a['goles'];
    return $diff !== 0 ? $diff : strcmp($a['nombre'], $b['nombre']);
});
$goleadores = array_slice($todosGoleadores, 0, 5);

fcHead("Panel de administración");
?>
<body class="bg-light es-empleado">
<?php fcNavbar($usr, 'admin.php'); ?>
<?php fcHero("Panel General de Administración", "Resumen del sistema y accesos rápidos para administradores"); ?>

<div class="container py-5">
    <?= fcAlerta('', false) ?>

    <div class="row g-3 mb-4">
        <?php foreach ($stats as $s): ?>
            <div class="col-6 col-md-4 col-lg">
                <a href="<?= $s[3] ?>" class="text-decoration-none">
                    <div class="card feature-card p-3 text-center h-100">
                        <i class="fa-solid <?= $s[2] ?> text-success fs-4 mb-2"></i>
                        <div class="fs-3 fw-bold text-dark"><?= $s[1] ?></div>
                        <div class="small text-muted"><?= esc($s[0]) ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card feature-card p-4 mb-4">
                <h5 class="fw-bold mb-3">Próximos partidos</h5>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead><tr><th>Fecha y hora</th><th>Encuentro</th><th>Cancha</th><th>Árbitro</th></tr></thead>
                        <tbody>
                        <?php if (empty($proximos)): ?>
                            <tr><td colspan="4"><div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Sin partidos próximos</h6></div></td></tr>
                        <?php else: foreach ($proximos as $p): ?>
                            <tr>
                                <td><div class="fw-bold"><?= esc($p['fecha']) ?></div><div class="small text-muted"><?= esc($p['hora']) ?></div></td>
                                <td><strong><?= esc($p['local_nombre']) ?></strong> vs <strong><?= esc($p['visitante_nombre']) ?></strong></td>
                                <td>C<?= intval($p['cancha']) ?></td>
                                <td><?= esc($p['arbitro']) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card feature-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0">Últimos movimientos</h5>
                    <a href="historial.php" class="btn btn-sm btn-brand-outline">Ver historial completo</a>
                </div>
                <?php if (empty($movimientos)): ?>
                    <div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Aún no hay movimientos</h6></div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($movimientos as $m): ?>
                            <li class="list-group-item px-0">
                                <div class="d-flex justify-content-between">
                                    <span><span class="badge bg-success-subtle text-success border border-success me-1"><?= esc($m['modulo']) ?></span> <strong><?= esc($m['accion']) ?></strong></span>
                                    <span class="small text-muted"><?= esc($m['creado_en']) ?></span>
                                </div>
                                <div class="small text-muted"><?= esc($m['usuario']) ?> — <?= esc($m['detalle']) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card feature-card p-4 mb-4">
                <h5 class="fw-bold mb-3">Accesos rápidos</h5>
                <div class="d-grid gap-2">
                    <a class="btn btn-brand-outline text-start" href="empleados.php"><i class="fa-solid fa-users-gear me-2"></i>Administrar empleados</a>
                    <a class="btn btn-brand-outline text-start" href="historial.php"><i class="fa-solid fa-clock-rotate-left me-2"></i>Historial de movimientos</a>
                    <a class="btn btn-brand-outline text-start" href="reprogramar.php"><i class="fa-solid fa-cloud-showers-heavy me-2"></i>Reprogramar por clima</a>
                    <a class="btn btn-brand-outline text-start" href="arbitros_cola.php"><i class="fa-solid fa-people-arrows me-2"></i>Cola de árbitros</a>
                    <a class="btn btn-brand-outline text-start" href="posiciones.php"><i class="fa-solid fa-ranking-star me-2"></i>Tabla de posiciones</a>
                    <a class="btn btn-brand-outline text-start" href="cambiar_password.php"><i class="fa-solid fa-key me-2"></i>Cambiar mi contraseña</a>
                </div>
            </div>

           <div class="card feature-card p-4">
                <h5 class="fw-bold mb-3">Top goleadores</h5>
                <?php if (empty($goleadores)): ?>
                    <div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">Sin goles registrados</h6></div>
                <?php else: ?>
                    <ol class="list-group list-group-numbered">
                        <?php foreach ($goleadores as $g): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div class="ms-2 me-auto"><div class="fw-bold"><?= esc($g['nombre']) ?></div><small class="text-muted"><?= esc($g['equipo']) ?></small></div>
                                <span class="badge bg-dark rounded-pill"><?= intval($g['goles']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php fcPie(); ?>