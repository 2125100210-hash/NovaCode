<?php
require_once "conexion.php";
$usr = protegerPagina();

$temporadas = $pdo->query("SELECT DISTINCT temporada FROM equipos ORDER BY temporada DESC")->fetchAll(PDO::FETCH_COLUMN);
$temporada  = $_GET['temporada'] ?? ($temporadas[0] ?? '');

$q = $pdo->prepare("SELECT id, nombre, foto FROM equipos WHERE temporada = ? ORDER BY nombre ASC");
$q->execute([$temporada]);
$tabla = [];
foreach ($q->fetchAll() as $e) {
    $tabla[] = [
        'id' => (int)$e['id'], 'nombre' => $e['nombre'], 'foto' => $e['foto'] ?? '',
        'pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0,
        'gf' => 0, 'gc' => 0, 'dg' => 0, 'pts' => 0, 'ta' => 0, 'tr' => 0
    ];
}

$partidos = $pdo->query("SELECT local_id, visitante_id, goles_local, goles_visitante
FROM partidos WHERE estado = 'Finalizado'")->fetchAll();

function indiceEquipo(array $tabla, $id)
{
    return Busqueda::secuencial($tabla, function ($fila) use ($id) {
        return $fila['id'] === (int)$id;
    });
}

// Suma los partidos jugados, goles a favor, goles en contra y puntos
function aplicarResultado(array &$tabla, $idx, $gf, $gc)
{
    if ($idx < 0) return;
    $tabla[$idx]['pj']++;
    $tabla[$idx]['gf'] += $gf;
    $tabla[$idx]['gc'] += $gc;
    if ($gf > $gc)      { $tabla[$idx]['pg']++; $tabla[$idx]['pts'] += 3; }
    elseif ($gf === $gc){ $tabla[$idx]['pe']++; $tabla[$idx]['pts'] += 1; }
    else                { $tabla[$idx]['pp']++; }
    $tabla[$idx]['dg'] = $tabla[$idx]['gf'] - $tabla[$idx]['gc'];
}

// Recorre cada partido finalizado y actualiza los numeros tanto del equipo local como del visitante
foreach ($partidos as $p) {
    $gl = (int)($p['goles_local'] ?? 0);
    $gv = (int)($p['goles_visitante'] ?? 0);
    aplicarResultado($tabla, indiceEquipo($tabla, $p['local_id']), $gl, $gv);
    aplicarResultado($tabla, indiceEquipo($tabla, $p['visitante_id']), $gv, $gl);
}

// Cuenta cuantas tarjetas amarillas y rojas acumulo cada equipo en partidos finalizados
$qt = $pdo->query("SELECT t.equipo_id, t.tipo, COUNT(*) AS total
                   FROM tarjetas t INNER JOIN partidos p ON t.partido_id = p.id
                   WHERE p.estado = 'Finalizado' GROUP BY t.equipo_id, t.tipo");
foreach ($qt->fetchAll() as $t) {
    $i = indiceEquipo($tabla, $t['equipo_id']);
    if ($i >= 0) {
        if ($t['tipo'] === 'Amarilla') $tabla[$i]['ta'] = (int)$t['total'];
        else                           $tabla[$i]['tr'] = (int)$t['total'];
    }
}

// Ordenamiento burbuja: acomoda la tabla de mayor a menor puntaje
// Si empatan en puntos, desempata por diferencia de goles, luego goles a favor y al final por orden alfabetico
$tabla = Ordenamiento::burbuja($tabla, function ($a, $b) {
    if ($a['pts'] !== $b['pts']) return $b['pts'] - $a['pts'];
    if ($a['dg']  !== $b['dg'])  return $b['dg']  - $a['dg'];
    if ($a['gf']  !== $b['gf'])  return $b['gf']  - $a['gf'];
    return strcasecmp($a['nombre'], $b['nombre']);
});

fcHead("Tabla de posiciones");
?>
<body class="bg-light es-empleado">
<?php fcNavbar($usr, 'posiciones.php'); ?>
<?php fcHero("Tabla de Posiciones", "Clasificación de los partidos finalizados"); ?>

<div class="container py-5">
    <div class="card feature-card p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0">Temporada <?= esc($temporada) ?></h5>
            <form method="GET" class="d-flex gap-2 no-print">
                <select name="temporada" class="form-select form-select-sm border-0 bg-light" onchange="this.form.submit()">
                    <?php if (empty($temporadas)): ?><option value="">Sin temporadas</option><?php endif; ?>
                    <?php foreach ($temporadas as $t): ?>
                        <option value="<?= esc($t) ?>" <?= $t === $temporada ? 'selected' : '' ?>><?= esc($t) ?></option>
                    <?php endforeach; ?>
                </select>
                <a href="reporte.php?tipo=posiciones&temporada=<?= urlencode($temporada) ?>" target="_blank" onclick="return imprimirReporte(this.href, this)" class="btn btn-sm btn-brand-outline">
                    <i class="fa-solid fa-print me-1"></i> Imprimir
                </a>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table align-middle text-center">
                <thead>
                    <tr>
                        <th>#</th><th class="text-start">Equipo</th><th>PJ</th><th>G</th><th>E</th><th>P</th>
                        <th>GF</th><th>GC</th><th>DG</th><th>Pts</th>
                        <th title="Tarjetas amarillas">TA</th><th title="Tarjetas rojas">TR</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($tabla)): ?>
                    <tr><td colspan="12"><div class="empty-state-box"><h6 class="fw-bold text-dark mb-1">No hay equipos en esta temporada</h6></div></td></tr>
                <?php else: foreach ($tabla as $pos => $f): ?>
                    <tr>
                        <td><span class="badge <?= $pos === 0 ? 'bg-success' : ($pos < 3 ? 'bg-success-subtle text-success border border-success' : 'bg-light text-dark border') ?>"><?= $pos + 1 ?></span></td>
                        <td class="text-start fw-bold"><span class="equipo-mini"><?= fcFoto($f['foto'], 'club', 32) ?><?= esc($f['nombre']) ?></span></td>
                        <td><?= $f['pj'] ?></td><td><?= $f['pg'] ?></td><td><?= $f['pe'] ?></td><td><?= $f['pp'] ?></td>
                        <td><?= $f['gf'] ?></td><td><?= $f['gc'] ?></td>
                        <td class="<?= $f['dg'] > 0 ? 'text-success' : ($f['dg'] < 0 ? 'text-danger' : '') ?>"><?= $f['dg'] > 0 ? '+' . $f['dg'] : $f['dg'] ?></td>
                        <td class="fw-bold fs-6"><?= $f['pts'] ?></td>
                        <td><?= $f['ta'] ?></td><td><?= $f['tr'] ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="small text-muted">Victoria = 3 pts · Empate = 1 pt · Derrota = 0 pts. Desempate: diferencia de goles, goles a favor y nombre. Ordenado con el método de burbuja.</div>
    </div>
</div>
<?php fcPie(); ?>