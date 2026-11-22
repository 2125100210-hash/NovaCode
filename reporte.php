<?php
// Reportes imprimibles: arma un documento aparte (encabezado, filtros, tabla, resumen y firma)
// para imprimir o guardar como PDF, en lugar de imprimir la pantalla tal cual
require_once "conexion.php";
date_default_timezone_set('America/Mexico_City');

$tipo = $_GET['tipo'] ?? '';
$usr  = ($tipo === 'historial') ? protegerAdmin() : protegerPagina();

$titulo   = "";
$filtros  = [];      // texto de los filtros que se aplicaron
$columnas = [];      // encabezados de la tabla
$filas    = [];      // cada fila es un arreglo de celdas (ya escapadas)
$resumen  = [];      // totales que se muestran al final
$alinear  = [];      // columnas centradas (indices)

switch ($tipo) {

    // ---------- Equipos ----------
    case 'equipos':
        $titulo = "Reporte de Equipos";
        $temp = trim($_GET['temporada'] ?? '');
        $sql = "SELECT e.*, (SELECT COUNT(*) FROM jugadores j WHERE j.equipo_id = e.id) AS total_jugadores FROM equipos e";
        $par = [];
        if ($temp !== '') {
            $sql .= " WHERE e.temporada = ?";
            $par[] = $temp;
            $filtros[] = "Temporada: $temp";
        } else {
            $filtros[] = "Temporada: todas";
        }
        $sql .= " ORDER BY e.temporada DESC, e.nombre ASC";
        $q = $pdo->prepare($sql);
        $q->execute($par);
        $datos = $q->fetchAll();

        $columnas = ["Club", "Equipo", "Temporada", "Jugadores", "Registrado por"];
        $alinear  = [3];
        $totJug = 0;
        foreach ($datos as $e) {
            $totJug += (int)$e['total_jugadores'];
            $filas[] = [fcFoto($e['foto'] ?? '', 'club', 34), '<strong>' . esc($e['nombre']) . '</strong>', esc($e['temporada']), (int)$e['total_jugadores'], esc($e['registrado_por'] ?: '—')];
        }
        $resumen = ["Equipos registrados" => count($datos), "Jugadores en total" => $totJug];
        break;

    // ---------- Árbitros ----------
    case 'arbitros':
        $titulo = "Reporte de Árbitros";
        $filtros[] = "Todos los árbitros registrados";
        $datos = $pdo->query("SELECT a.*, (SELECT COUNT(*) FROM partidos p WHERE p.arbitro_id = a.id AND p.estado != 'Cancelado') AS total_partidos
                              FROM arbitros a ORDER BY a.nombre ASC")->fetchAll();
        $columnas = ["Foto", "Nombre", "Teléfono", "Correo", "Estatus", "Partidos asignados", "Registrado por"];
        $alinear  = [5];
        $cDisp = $cConf = $cNo = 0;
        foreach ($datos as $a) {
            if ($a['estado'] === 'Disponible') $cDisp++;
            elseif ($a['estado'] === 'Confirmado') $cConf++;
            else $cNo++;
            $filas[] = [fcFoto($a['foto'] ?? '', 'persona', 34), '<strong>' . esc($a['nombre']) . '</strong>', esc($a['telefono']), esc($a['correo']), esc($a['estado']), (int)$a['total_partidos'], esc($a['registrado_por'] ?: '—')];
        }
        $resumen = ["Árbitros registrados" => count($datos), "Disponibles" => $cDisp, "Confirmados" => $cConf, "No disponibles" => $cNo];
        break;

    // ---------- Jugadores y goleo ----------
    case 'jugadores':
        $titulo = "Reporte de Jugadores y Goleo";
        $equipoId = intval($_GET['equipo_id'] ?? 0);
        $sql = "SELECT j.id, j.nombre, j.numero, j.posicion, j.foto, COUNT(p.id) AS goles, e.nombre AS equipo
                FROM jugadores j
                INNER JOIN equipos e ON j.equipo_id = e.id
                LEFT JOIN goles g ON g.jugador_id = j.id
                LEFT JOIN partidos p ON p.id = g.partido_id AND p.estado != 'Cancelado'";
        $par = [];
        if ($equipoId > 0) {
            $sql .= " WHERE j.equipo_id = ?";
            $par[] = $equipoId;
        }
        $sql .= " GROUP BY j.id, j.nombre, j.numero, j.posicion, j.foto, e.nombre";
        $q = $pdo->prepare($sql);
        $q->execute($par);
        $datos = $q->fetchAll();
        $filtros[] = $equipoId > 0 ? "Equipo: " . ($datos[0]['equipo'] ?? '—') : "Todos los equipos";

        // Ordena de mas goles a menos goles (y por nombre si empatan)
        $datos = Ordenamiento::burbuja($datos, function ($a, $b) {
            $diff = (int)$b['goles'] - (int)$a['goles'];
            return $diff !== 0 ? $diff : strcmp($a['nombre'], $b['nombre']);
        });

        $columnas = ["#", "Foto", "Jugador", "Equipo", "No.", "Posición", "Goles"];
        $alinear  = [0, 4, 6];
        $totGoles = 0;
        foreach ($datos as $i => $j) {
            $totGoles += (int)$j['goles'];
            $filas[] = [$i + 1, fcFoto($j['foto'] ?? '', 'persona', 34), '<strong>' . esc($j['nombre']) . '</strong>', esc($j['equipo']), (int)$j['numero'], esc($j['posicion']), '<strong>' . (int)$j['goles'] . '</strong>'];
        }
        $resumen = ["Jugadores" => count($datos), "Goles en total" => $totGoles];
        break;

    // ---------- Partidos ----------
    case 'partidos':
        $titulo = "Reporte de Partidos";
        $fecha = trim($_GET['fecha'] ?? '');
        $sql = "SELECT p.*, el.nombre AS local_nombre, el.foto AS local_foto, ev.nombre AS visitante_nombre, ev.foto AS visitante_foto,
                       a.nombre AS arbitro_nombre
                FROM partidos p
                INNER JOIN equipos el ON p.local_id = el.id
                INNER JOIN equipos ev ON p.visitante_id = ev.id
                INNER JOIN arbitros a ON p.arbitro_id = a.id";
        $par = [];
        if ($fecha !== '') {
            $sql .= " WHERE p.fecha = ? ORDER BY p.hora ASC";
            $par[] = $fecha;
            $filtros[] = "Fecha: " . date('d/m/Y', strtotime($fecha));
        } else {
            $sql .= " ORDER BY p.fecha DESC, p.hora ASC";
            $filtros[] = "Todas las fechas";
        }
        $q = $pdo->prepare($sql);
        $q->execute($par);
        $datos = $q->fetchAll();

        $columnas = ["Fecha", "Horario", "Encuentro", "Cancha", "Árbitro", "Resultado", "Estado"];
        $alinear  = [5];
        $cProg = $cFin = $cCan = 0;
        foreach ($datos as $p) {
            if ($p['estado'] === 'Finalizado') $cFin++;
            elseif ($p['estado'] === 'Cancelado') $cCan++;
            else $cProg++;
            $res = ($p['goles_local'] !== null && $p['goles_visitante'] !== null)
                ? '<strong>' . (int)$p['goles_local'] . ' - ' . (int)$p['goles_visitante'] . '</strong>' : 'Pendiente';
            $encuentro = '<span class="eq">' . fcFoto($p['local_foto'] ?? '', 'club', 24) . esc($p['local_nombre']) . '</span> vs '
                       . '<span class="eq">' . fcFoto($p['visitante_foto'] ?? '', 'club', 24) . esc($p['visitante_nombre']) . '</span>';
            $filas[] = [date('d/m/Y', strtotime($p['fecha'])), esc($p['hora']), $encuentro, esc(FC_CANCHAS[$p['cancha']] ?? 'Cancha ' . $p['cancha']), esc($p['arbitro_nombre']), $res, esc($p['estado'])];
        }
        $resumen = ["Partidos" => count($datos), "Programados" => $cProg, "Finalizados" => $cFin, "Cancelados" => $cCan];
        break;

    // ---------- Tabla de posiciones ----------
    case 'posiciones':
        $titulo = "Reporte de Tabla de Posiciones";
        $temporadas = $pdo->query("SELECT DISTINCT temporada FROM equipos ORDER BY temporada DESC")->fetchAll(PDO::FETCH_COLUMN);
        $temporada  = trim($_GET['temporada'] ?? '') !== '' ? trim($_GET['temporada']) : ($temporadas[0] ?? '');
        $filtros[]  = "Temporada: " . ($temporada !== '' ? $temporada : '—');

        $q = $pdo->prepare("SELECT id, nombre, foto FROM equipos WHERE temporada = ? ORDER BY nombre ASC");
        $q->execute([$temporada]);
        $tabla = [];
        foreach ($q->fetchAll() as $e) {
            $tabla[] = ['id' => (int)$e['id'], 'nombre' => $e['nombre'], 'foto' => $e['foto'] ?? '',
                        'pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0, 'gf' => 0, 'gc' => 0, 'dg' => 0, 'pts' => 0, 'ta' => 0, 'tr' => 0];
        }
        $idx = function ($id) use (&$tabla) {
            return Busqueda::secuencial($tabla, function ($f) use ($id) { return $f['id'] === (int)$id; });
        };
        $aplicar = function ($i, $gf, $gc) use (&$tabla) {
            if ($i < 0) return;
            $tabla[$i]['pj']++;
            $tabla[$i]['gf'] += $gf;
            $tabla[$i]['gc'] += $gc;
            if ($gf > $gc)       { $tabla[$i]['pg']++; $tabla[$i]['pts'] += 3; }
            elseif ($gf === $gc) { $tabla[$i]['pe']++; $tabla[$i]['pts'] += 1; }
            else                 { $tabla[$i]['pp']++; }
            $tabla[$i]['dg'] = $tabla[$i]['gf'] - $tabla[$i]['gc'];
        };
        $fin = $pdo->query("SELECT local_id, visitante_id, goles_local, goles_visitante FROM partidos WHERE estado = 'Finalizado'")->fetchAll();
        foreach ($fin as $p) {
            $gl = (int)($p['goles_local'] ?? 0);
            $gv = (int)($p['goles_visitante'] ?? 0);
            $aplicar($idx($p['local_id']), $gl, $gv);
            $aplicar($idx($p['visitante_id']), $gv, $gl);
        }
        $qt = $pdo->query("SELECT t.equipo_id, t.tipo, COUNT(*) AS total FROM tarjetas t
                           INNER JOIN partidos p ON t.partido_id = p.id WHERE p.estado = 'Finalizado' GROUP BY t.equipo_id, t.tipo");
        foreach ($qt->fetchAll() as $t) {
            $i = $idx($t['equipo_id']);
            if ($i >= 0) {
                if ($t['tipo'] === 'Amarilla') $tabla[$i]['ta'] = (int)$t['total'];
                else                           $tabla[$i]['tr'] = (int)$t['total'];
            }
        }
        $tabla = Ordenamiento::burbuja($tabla, function ($a, $b) {
            if ($a['pts'] !== $b['pts']) return $b['pts'] - $a['pts'];
            if ($a['dg']  !== $b['dg'])  return $b['dg']  - $a['dg'];
            if ($a['gf']  !== $b['gf'])  return $b['gf']  - $a['gf'];
            return strcasecmp($a['nombre'], $b['nombre']);
        });

        $columnas = ["#", "Equipo", "PJ", "G", "E", "P", "GF", "GC", "DG", "Pts", "TA", "TR"];
        $alinear  = [0, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
        $totPJ = 0;
        foreach ($tabla as $pos => $f) {
            $totPJ += $f['pj'];
            $filas[] = [$pos + 1, '<span class="eq">' . fcFoto($f['foto'], 'club', 24) . '<strong>' . esc($f['nombre']) . '</strong></span>',
                        $f['pj'], $f['pg'], $f['pe'], $f['pp'], $f['gf'], $f['gc'], ($f['dg'] > 0 ? '+' : '') . $f['dg'], '<strong>' . $f['pts'] . '</strong>', $f['ta'], $f['tr']];
        }
        $resumen = ["Equipos" => count($tabla), "Partidos jugados" => intdiv($totPJ, 2)];
        $nota = "Victoria = 3 pts, empate = 1 pt, derrota = 0 pts. Desempate: diferencia de goles, goles a favor y nombre.";
        break;

    // ---------- Historial de movimientos (solo administrador) ----------
    case 'historial':
        $titulo = "Reporte de Historial de Movimientos";
        $filtroModulo  = trim($_GET['modulo'] ?? '');
        $filtroUsuario = intval($_GET['usuario_id'] ?? 0);
        $busqueda      = trim($_GET['q'] ?? '');
        $where = [];
        $par   = [];
        if ($filtroModulo !== '') {
            $where[] = "h.modulo = ?";
            $par[]   = $filtroModulo;
            $filtros[] = "Módulo: $filtroModulo";
        }
        if ($filtroUsuario > 0) {
            $where[] = "h.usuario_id = ?";
            $par[]   = $filtroUsuario;
            $filtros[] = "Empleado ID: #$filtroUsuario";
        }
        if ($busqueda !== '') {
            $where[] = "(h.detalle LIKE ? OR h.accion LIKE ? OR h.usuario LIKE ? OR u.correo LIKE ?)";
            $like = "%" . $busqueda . "%";
            array_push($par, $like, $like, $like, $like);
            $filtros[] = "Búsqueda: $busqueda";
        }
        if (!$filtros) $filtros[] = "Sin filtros (últimos 200 movimientos)";
        $sql = "SELECT h.id, h.usuario_id, h.usuario, h.modulo, h.accion, h.detalle, h.creado_en, u.nombre AS nombre_actual
                FROM historial h LEFT JOIN usuarios u ON h.usuario_id = u.id"
             . ($where ? " WHERE " . implode(" AND ", $where) : "") . " ORDER BY h.id DESC LIMIT 200";
        $q = $pdo->prepare($sql);
        $q->execute($par);
        $datos = $q->fetchAll();

        $columnas = ["Fecha y hora", "Empleado", "Módulo", "Acción", "Detalle"];
        foreach ($datos as $m) {
            $emp = !empty($m['usuario_id']) ? 'ID #' . (int)$m['usuario_id'] . ' · ' . esc($m['nombre_actual'] ?: $m['usuario']) : esc($m['usuario']);
            $filas[] = [esc($m['creado_en']), $emp, esc($m['modulo']), esc($m['accion']), esc($m['detalle'])];
        }
        $resumen = ["Movimientos en el reporte" => count($datos)];
        break;

    default:
        http_response_code(404);
        die("Tipo de reporte no válido.");
}

$folio = "RPT-" . strtoupper(substr($tipo, 0, 3)) . "-" . date('Ymd-His');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($titulo) ?> - FutCancha</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #0f172a; background: #e5e7eb; margin: 0; font-size: 12px; }
        .barra { position: sticky; top: 0; background: #073820; color: #fff; padding: 10px 20px; display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .barra button, .barra a { background: #fff; color: #073820; border: 0; border-radius: 6px; padding: 8px 14px; font-weight: 600; cursor: pointer; text-decoration: none; font-size: 13px; }
        .hoja { background: #fff; max-width: 1000px; margin: 18px auto; padding: 28px 32px; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
        .encabezado { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #0f5a36; padding-bottom: 10px; margin-bottom: 14px; gap: 16px; }
        .marca { display: flex; align-items: center; gap: 10px; font-size: 22px; font-weight: 800; color: #073820; }
        .marca i { color: #15803d; font-size: 28px; }
        .meta { text-align: right; font-size: 11px; color: #475569; line-height: 1.5; }
        h1 { font-size: 18px; margin: 0 0 4px; color: #0f5a36; }
        .filtros { background: #f1f5f9; border-left: 4px solid #15803d; padding: 6px 10px; margin-bottom: 14px; font-size: 11.5px; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        th { background: #0f5a36; color: #fff; text-align: left; padding: 6px 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
        td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        tr { page-break-inside: avoid; }
        tbody tr:nth-child(even) td { background: #f8fafc; }
        .c { text-align: center; }
        .eq { display: inline-flex; align-items: center; gap: 5px; }
        .foto-persona, .foto-club { display: inline-flex; align-items: center; justify-content: center; object-fit: cover; border: 1px solid #cbd5e1; background: #fff; vertical-align: middle; }
        .foto-persona { border-radius: 50%; }
        .foto-club { border-radius: 6px; object-fit: contain; }
        .foto-vacia { background: #e6f4ea; color: #0f5a36; font-size: 11px; }
        .resumen { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
        .resumen div { border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 12px; min-width: 120px; }
        .resumen b { display: block; font-size: 17px; color: #0f5a36; }
        .vacio { text-align: center; padding: 20px; color: #64748b; }
        .nota { margin-top: 10px; font-size: 10.5px; color: #64748b; }
        .firmas { display: flex; justify-content: space-around; margin-top: 50px; gap: 30px; }
        .firmas div { flex: 1; text-align: center; border-top: 1px solid #0f172a; padding-top: 4px; font-size: 11px; }
        .pie { margin-top: 22px; padding-top: 6px; border-top: 1px solid #cbd5e1; font-size: 10px; color: #64748b; display: flex; justify-content: space-between; }
        @media print {
            body { background: #fff; font-size: 11px; }
            .barra { display: none !important; }
            .hoja { box-shadow: none; margin: 0; padding: 0; max-width: none; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="barra">
    <span><i class="fa-solid fa-file-lines"></i> Vista previa del reporte</span>
    <span>
        <button type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimir / Guardar como PDF</button>
        <button type="button" onclick="window.close()">Cerrar</button>
    </span>
</div>

<div class="hoja">
    <div class="encabezado">
        <div>
            <div class="marca"><i class="fa-solid fa-futbol"></i> FutCancha</div>
            <div style="color:#475569;margin-top:2px;">Administración de canchas de fútbol</div>
        </div>
        <div class="meta">
            <div><strong>Folio:</strong> <?= esc($folio) ?></div>
            <div><strong>Fecha de emisión:</strong> <?= date('d/m/Y H:i') ?></div>
            <div><strong>Generado por:</strong> <?= esc($usr['nombre']) ?></div>
        </div>
    </div>

    <h1><?= esc($titulo) ?></h1>
    <div class="filtros"><strong>Criterios del reporte:</strong> <?= esc(implode(' · ', $filtros)) ?></div>

    <table>
        <thead>
            <tr>
                <?php foreach ($columnas as $i => $col): ?>
                    <th class="<?= in_array($i, $alinear, true) ? 'c' : '' ?>"><?= esc($col) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($filas)): ?>
                <tr><td colspan="<?= count($columnas) ?>" class="vacio">No hay registros para mostrar con estos criterios.</td></tr>
            <?php else: foreach ($filas as $fila): ?>
                <tr>
                    <?php foreach ($fila as $i => $celda): ?>
                        <td class="<?= in_array($i, $alinear, true) ? 'c' : '' ?>"><?= $celda ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="resumen">
        <?php foreach ($resumen as $etq => $valor): ?>
            <div><b><?= esc($valor) ?></b><?= esc($etq) ?></div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($nota)): ?><div class="nota"><?= esc($nota) ?></div><?php endif; ?>

    <div class="firmas">
        <div>Elaboró: <?= esc($usr['nombre']) ?></div>
        <div>Revisó / Autorizó</div>
    </div>

    <div class="pie">
        <span>FutCancha · <?= esc($titulo) ?></span>
        <span><?= esc($folio) ?></span>
    </div>
</div>
</body>
</html>
