<?php
require_once "conexion.php";

$usr = protegerAdmin();

$filtroModulo  = trim($_GET['modulo'] ?? '');
$filtroUsuario = intval($_GET['usuario_id'] ?? 0);
$busqueda      = trim($_GET['q'] ?? '');

$where  = [];
$params = [];

// Si eligio un modulo, agrega esa condicion a la busqueda
if ($filtroModulo !== '') {
    $where[]  = "h.modulo = ?";
    $params[] = $filtroModulo;
}
// Si eligio un empleado especifico, filtra por su ID
if ($filtroUsuario > 0) {
    $where[]  = "h.usuario_id = ?";
    $params[] = $filtroUsuario;
}
// Si escribio algo en el buscador, lo busca en el detalle, accion, nombre o correo
if ($busqueda !== '') {
    $where[]  = "(h.detalle LIKE ? OR h.accion LIKE ? OR h.usuario LIKE ? OR u.correo LIKE ?)";
    $like     = "%" . $busqueda . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// Arma la consulta trayendo los ultimos 200 movimientos del historial junto con los datos actuales del usuario
$sql = "SELECT h.id, h.usuario_id, h.usuario, h.modulo, h.accion, h.detalle, h.creado_en,
               u.nombre AS nombre_actual, u.correo AS correo_actual
        FROM historial h
        LEFT JOIN usuarios u ON h.usuario_id = u.id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY h.id DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movimientos = $stmt->fetchAll();

// Trae las listas de modulos y usuarios para llenar los filtros desplegables
$modulosLista = $pdo->query("SELECT DISTINCT modulo FROM historial ORDER BY modulo ASC")->fetchAll(PDO::FETCH_COLUMN);
$usuariosLista = $pdo->query("SELECT id, nombre, correo FROM usuarios ORDER BY nombre ASC")->fetchAll();

fcHead("Historial de movimientos");
?>
<body class="bg-light es-empleado">
<?php fcNavbar($usr, 'historial.php'); ?>
<?php fcHero("Historial de Movimientos", "Bitácora de auditoría con identificación única por empleado (ID)"); ?>

<div class="container py-5">
    <?= fcAlerta('', false) ?>

    <div class="card feature-card p-4 mb-4">
        <form method="GET" action="historial.php" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label-minimal">Módulo</label>
                <select name="modulo" class="form-select border-0 bg-light">
                    <option value="">Todos los módulos</option>
                    <?php foreach ($modulosLista as $m): ?>
                        <option value="<?= esc($m) ?>" <?= $filtroModulo === $m ? 'selected' : '' ?>><?= esc($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label-minimal">Empleado (ID y correo)</label>
                <select name="usuario_id" class="form-select border-0 bg-light">
                    <option value="0">Todos los empleados</option>
                    <?php foreach ($usuariosLista as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $filtroUsuario === (int)$u['id'] ? 'selected' : '' ?>>
                            #<?= (int)$u['id'] ?> · <?= esc($u['nombre']) ?> (<?= esc($u['correo']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label-minimal">Buscar detalle</label>
                <input type="text" name="q" value="<?= esc($busqueda) ?>" class="form-control underline-input" placeholder="Acción, partido, equipo...">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-brand-primary w-100">Filtrar</button>
                <a href="historial.php" class="btn btn-outline-secondary">Limpiar</a>
            </div>
        </form>
    </div>

   <div class="card feature-card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="fw-bold mb-0">Registros encontrados (<?= count($movimientos) ?>)</h5>
            <a href="reporte.php?tipo=historial&modulo=<?= urlencode($filtroModulo) ?>&usuario_id=<?= (int)$filtroUsuario ?>&q=<?= urlencode($busqueda) ?>" target="_blank" onclick="return imprimirReporte(this.href, this)" class="btn btn-sm btn-brand-outline">
                <i class="fa-solid fa-print me-1"></i> Imprimir Bitácora
            </a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Fecha y hora</th>
                        <th>Empleado (ID)</th>
                        <th>Módulo</th>
                        <th>Acción</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movimientos)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state-box">
                                    <h6 class="fw-bold text-dark mb-1">Sin movimientos registrados con esos filtros</h6>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($movimientos as $m): ?>
                            <tr>
                                <td class="small text-muted text-nowrap"><?= esc($m['creado_en']) ?></td>
                                <td>
                                    <?php if (!empty($m['usuario_id'])): ?>
                                        <div class="fw-bold text-dark">
                                            <span class="badge bg-light text-dark border me-1">ID #<?= (int)$m['usuario_id'] ?></span>
                                            <?= esc($m['nombre_actual'] ?: $m['usuario']) ?>
                                        </div>
                                        <?php if (!empty($m['correo_actual'])): ?>
                                            <div class="small text-muted"><?= esc($m['correo_actual']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="fw-medium text-secondary"><?= esc($m['usuario']) ?></span>
                                        <div class="small text-muted">Registro histórico sin ID</div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-success-subtle text-success border border-success"><?= esc($m['modulo']) ?></span></td>
                                <td class="fw-semibold"><?= esc($m['accion']) ?></td>
                                <td class="small"><?= esc($m['detalle']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php fcPie(); ?>