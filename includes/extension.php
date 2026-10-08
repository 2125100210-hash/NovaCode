<?php
// recursividad, reprogramacion de partidos y sincronizacion de goles

require_once __DIR__ . '/estructuras.php';

const FC_CANCHAS = [
    1 => "Cancha 1 (Fútbol 7)",
    2 => "Cancha 2 (Fútbol 11)",
    3 => "Cancha 3 (Fútbol 5)",
    4 => "Cancha 4 (Fútbol 5)",
    5 => "Cancha 5 (Fútbol 11)"
];

const FC_HORARIOS = [
    "08:00 - 09:00", "09:00 - 10:00", "10:00 - 11:00", "11:00 - 12:00",
    "12:00 - 13:00", "13:00 - 14:00", "14:00 - 15:00", "15:00 - 16:00",
    "16:00 - 17:00", "17:00 - 18:00", "18:00 - 19:00", "19:00 - 20:00",
    "20:00 - 21:00", "21:00 - 22:00"
];

const FC_ESQUEMA_VERSION = 4;

// Seguridad de formularios: crea y revisa un token secreto en cada envio POST
function fcCsrfToken()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (empty($_SESSION['fc_csrf_token'])) {
            $_SESSION['fc_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['fc_csrf_token'];
    }
    return '';
}

function fcCsrfInput()
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(fcCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

if (session_status() === PHP_SESSION_ACTIVE) {
    fcCsrfToken();

    // Revisa que el token recibido coincida con el de la sesion antes de guardar nada
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $tokenRecibido = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $tokenSesion   = $_SESSION['fc_csrf_token'] ?? '';
        if ($tokenSesion === '' || !hash_equals($tokenSesion, (string)$tokenRecibido)) {
            http_response_code(403);
            die("Error de seguridad (CSRF): token inválido o sesión expirada. Recarga la página e intenta de nuevo.");
        }
    }

    // Agrega solo el campo oculto del token a todos los formularios POST de la pagina
    ob_start(function ($html) {
        $input = fcCsrfInput();
        return preg_replace(
            '/(<form\b[^>]*method=["\']post["\'][^>]*>)/i',
            '$1' . "\n" . $input,
            $html
        );
    });
}

// Registro de goles por partido y tabla de goleo:
// Cuenta los goles reales de la tabla goles y actualiza tanto el marcador del partido como los goles totales de cada jugador para que nunca queden desfasados
function fcSincronizarGoles(PDO $pdo, $partidoId = null, $jugadorId = null)
{
    // 1. Actualiza el marcador del partido contando los goles de cada equipo
    if ($partidoId !== null) {
        $q = $pdo->prepare("SELECT id, local_id, visitante_id, estado FROM partidos WHERE id = ?");
        $q->execute([(int)$partidoId]);
        $p = $q->fetch();
        if ($p) {
            $qG = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN equipo_id = ? THEN 1 ELSE 0 END), 0) AS gl,
                COALESCE(SUM(CASE WHEN equipo_id = ? THEN 1 ELSE 0 END), 0) AS gv
                FROM goles WHERE partido_id = ?");
            $qG->execute([$p['local_id'], $p['visitante_id'], $p['id']]);
            $tot = $qG->fetch();

            $tieneGoles = ((int)$tot['gl'] + (int)$tot['gv']) > 0;
            if ($p['estado'] === 'Finalizado' || $tieneGoles) {
                $pdo->prepare("UPDATE partidos SET goles_local = ?, goles_visitante = ? WHERE id = ?")
                    ->execute([(int)$tot['gl'], (int)$tot['gv'], $p['id']]);
            } elseif ($p['estado'] === 'Programado') {
                $pdo->prepare("UPDATE partidos SET goles_local = NULL, goles_visitante = NULL WHERE id = ?")
                    ->execute([$p['id']]);
            }
        }
    } else {
        // Si no se paso un partido en especifico, recalcula todos los partidos
        $pdo->exec("UPDATE partidos p
            SET goles_local = (SELECT COUNT(*) FROM goles g WHERE g.partido_id = p.id AND g.equipo_id = p.local_id),
                goles_visitante = (SELECT COUNT(*) FROM goles g WHERE g.partido_id = p.id AND g.equipo_id = p.visitante_id)
            WHERE p.estado = 'Finalizado' OR EXISTS (SELECT 1 FROM goles g2 WHERE g2.partido_id = p.id)");
    }

    // 2. Actualiza los goles de cada jugador contando solo partidos que no esten cancelados
    if ($jugadorId !== null) {
        $qJ = $pdo->prepare("SELECT COUNT(*) FROM goles g
                             INNER JOIN partidos p ON p.id = g.partido_id
                             WHERE g.jugador_id = ? AND p.estado != 'Cancelado'");
        $qJ->execute([(int)$jugadorId]);
        $totalJug = (int)$qJ->fetchColumn();
        $pdo->prepare("UPDATE jugadores SET goles = ? WHERE id = ?")->execute([$totalJug, (int)$jugadorId]);
    } else {
        $pdo->exec("UPDATE jugadores j
            SET j.goles = (
                SELECT COUNT(*) FROM goles g
                INNER JOIN partidos p ON p.id = g.partido_id
                WHERE g.jugador_id = j.id AND p.estado != 'Cancelado'
            )");
    }
}

// Funciones de ayuda para revisar si ya existen columnas o indices en la base de datos
function fcColumnaExiste(PDO $pdo, $tabla, $columna)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute([$tabla, $columna]);
    return (int)$q->fetchColumn() > 0;
}

function fcIndiceExiste(PDO $pdo, $tabla, $indice)
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $q->execute([$tabla, $indice]);
    return (int)$q->fetchColumn() > 0;
}

// colas de arbitros, reprogramacion de partidos, registro de goles por partido,
// registro de tarjetas e historial de movimientos
function fcAsegurarEsquema(PDO $pdo)
{
    // Columnas de rol y estado para permisos de administrador y administracion de empleados
    if (!fcColumnaExiste($pdo, 'usuarios', 'rol')) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN rol VARCHAR(20) NOT NULL DEFAULT 'Empleado'");
    }
    if (!fcColumnaExiste($pdo, 'usuarios', 'activo')) {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1");
    }

    // Columnas de fotografia: foto del arbitro y foto (escudo) del club
    if (!fcColumnaExiste($pdo, 'arbitros', 'foto')) {
        $pdo->exec("ALTER TABLE arbitros ADD COLUMN foto VARCHAR(255) DEFAULT ''");
    }
    if (!fcColumnaExiste($pdo, 'equipos', 'foto')) {
        $pdo->exec("ALTER TABLE equipos ADD COLUMN foto VARCHAR(255) DEFAULT ''");
    }

    // Columna turno_cola para manejar las colas de arbitros
    if (!fcColumnaExiste($pdo, 'arbitros', 'turno_cola')) {
        $pdo->exec("ALTER TABLE arbitros ADD COLUMN turno_cola INT NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE arbitros SET turno_cola = id WHERE turno_cola = 0");
    }

    // Columnas para guardar datos cuando hay reprogramacion de partidos o reprogramacion por clima
    if (!fcColumnaExiste($pdo, 'partidos', 'reprogramaciones')) {
        $pdo->exec("ALTER TABLE partidos ADD COLUMN reprogramaciones INT NOT NULL DEFAULT 0");
    }
    if (!fcColumnaExiste($pdo, 'partidos', 'motivo_reprogramacion')) {
        $pdo->exec("ALTER TABLE partidos ADD COLUMN motivo_reprogramacion VARCHAR(255) NULL");
    }
    if (!fcColumnaExiste($pdo, 'partidos', 'fecha_original')) {
        $pdo->exec("ALTER TABLE partidos ADD COLUMN fecha_original DATE NULL");
    }
    if (!fcColumnaExiste($pdo, 'partidos', 'hora_original')) {
        $pdo->exec("ALTER TABLE partidos ADD COLUMN hora_original VARCHAR(20) NULL");
    }

    // Reglas en la base de datos para que no se empalmen dos partidos en la misma cancha o arbitro
    if (!fcColumnaExiste($pdo, 'partidos', 'activo_slot')) {
        $pdo->exec("ALTER TABLE partidos ADD COLUMN activo_slot TINYINT(1) AS (IF(estado != 'Cancelado', 1, NULL)) STORED");
    }
    if (!fcIndiceExiste($pdo, 'partidos', 'uk_cancha_horario')) {
        $pdo->exec("ALTER TABLE partidos ADD UNIQUE KEY uk_cancha_horario (cancha, fecha, hora, activo_slot)");
    }
    if (!fcIndiceExiste($pdo, 'partidos', 'uk_arbitro_horario')) {
        $pdo->exec("ALTER TABLE partidos ADD UNIQUE KEY uk_arbitro_horario (arbitro_id, fecha, hora, activo_slot)");
    }

    // Tabla para el registro de goles por partido
    $pdo->exec("CREATE TABLE IF NOT EXISTS goles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partido_id INT NOT NULL,
        jugador_id INT NOT NULL,
        equipo_id INT NOT NULL,
        minuto INT NOT NULL DEFAULT 0,
        registrado_por VARCHAR(100) NOT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (partido_id) REFERENCES partidos(id) ON DELETE CASCADE,
        FOREIGN KEY (jugador_id) REFERENCES jugadores(id) ON DELETE CASCADE
    )");

    // Tabla para el registro de tarjetas amarillas y rojas por partido
    $pdo->exec("CREATE TABLE IF NOT EXISTS tarjetas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partido_id INT NOT NULL,
        jugador_id INT NOT NULL,
        equipo_id INT NOT NULL,
        tipo ENUM('Amarilla','Roja') NOT NULL,
        minuto INT NOT NULL DEFAULT 0,
        registrado_por VARCHAR(100) NOT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (partido_id) REFERENCES partidos(id) ON DELETE CASCADE,
        FOREIGN KEY (jugador_id) REFERENCES jugadores(id) ON DELETE CASCADE
    )");

    // Tabla para el historial de movimientos de los empleados y administradores
    $pdo->exec("CREATE TABLE IF NOT EXISTS historial (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NULL,
        usuario VARCHAR(100) NOT NULL,
        modulo VARCHAR(50) NOT NULL,
        accion VARCHAR(60) NOT NULL,
        detalle TEXT,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    if (!fcColumnaExiste($pdo, 'historial', 'usuario_id')) {
        $pdo->exec("ALTER TABLE historial ADD COLUMN usuario_id INT NULL AFTER id");
    }

    // Deja sincronizados los goles al iniciar
    fcSincronizarGoles($pdo);
}

try {
    if (($_SESSION['fc_esquema'] ?? 0) !== FC_ESQUEMA_VERSION) {
        fcAsegurarEsquema($pdo);
        $_SESSION['fc_esquema'] = FC_ESQUEMA_VERSION;
        unset($_SESSION['fc_error_esquema']);
    }

    // Si desactivan o borran a un empleado mientras esta adentro, le cierra la sesion al instante
    if (isset($_SESSION['usuario']['id'])) {
        $qAct = $pdo->prepare("SELECT activo FROM usuarios WHERE id = ?");
        $qAct->execute([$_SESSION['usuario']['id']]);
        $filaAct = $qAct->fetch();
        if (!$filaAct || (int)$filaAct['activo'] === 0) {
            $_SESSION = [];
            session_destroy();
            header("Location: login.php");
            exit;
        }
    }
} catch (Throwable $e) {
    error_log("[FutCancha Esquema] Error al actualizar estructura de BD: " . $e->getMessage());
    $_SESSION['fc_error_esquema'] = $e->getMessage();
}

// Consulta en la base de datos si el usuario conectado tiene rol de Administrador
function fcRolActual()
{
    static $rol = null;
    if ($rol !== null) {
        return $rol;
    }
    if (empty($_SESSION['usuario']['id'])) {
        return $rol = '';
    }
    try {
        $pdo = $GLOBALS['pdo'];
        $q = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ? AND activo = 1");
        $q->execute([$_SESSION['usuario']['id']]);
        $r = $q->fetch();
        $rol = $r ? $r['rol'] : '';
    } catch (Throwable $e) {
        $rol = '';
    }
    return $rol;
}

// Devuelve true si el usuario es Administrador
function esAdmin()
{
    return fcRolActual() === 'Administrador';
}

// Bloquea la entrada a paginas de administracion si el usuario es un empleado normal
function protegerAdmin()
{
    $usr = protegerPagina();
    if (!esAdmin()) {
        http_response_code(403);
        fcHead("Acceso restringido");
        echo '<body class="bg-light es-empleado">';
        fcNavbar($usr, '');
        echo '<div class="container py-5"><div class="card feature-card p-5 text-center mx-auto" style="max-width:600px">
                <i class="fa-solid fa-lock text-danger display-4 mb-3"></i>
                <h4 class="fw-bold">Acceso restringido</h4>
                <p class="text-muted">Esta sección es exclusiva para administradores.</p>
                <a href="equipos.php" class="btn btn-brand-primary">Volver al inicio</a>
              </div></div>';
        fcPie();
        exit;
    }
    return $usr;
}

// Guarda en la tabla historial quien hizo cada cambio, en que modulo y a que hora
function registrarMovimiento($modulo, $accion, $detalle = '', $usuario = null)
{
    try {
        $pdo = $GLOBALS['pdo'];
        $usuarioId = isset($_SESSION['usuario']['id']) ? (int)$_SESSION['usuario']['id'] : null;
        if ($usuario === null) {
            $usuario = $_SESSION['usuario']['nombre'] ?? 'Sistema';
        }
        $q = $pdo->prepare("INSERT INTO historial (usuario_id, usuario, modulo, accion, detalle) VALUES (?, ?, ?, ?, ?)");
        $q->execute([$usuarioId, $usuario, $modulo, $accion, $detalle]);
    } catch (Throwable $e) {
        error_log("[FutCancha Historial] " . $e->getMessage());
    }
}

// Detecta cuando se guarda algo en equipos, jugadores, arbitros, partidos o login y lo anota en el historial de movimientos al terminar la peticion
register_shutdown_function(function () {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        $pagina = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $modulos = [
            'equipos.php' => 'Equipos', 'jugadores.php' => 'Jugadores',
            'arbitros.php' => 'Árbitros', 'partidos.php' => 'Partidos', 'login.php' => 'Acceso'
        ];
        if (!isset($modulos[$pagina]) || empty($_SESSION['usuario'])) {
            return;
        }
        $accion = $_POST['accion'] ?? '';
        $etiquetas = [
            'crear' => 'Alta', 'editar' => 'Edición', 'eliminar' => 'Eliminación',
            'cancelar' => 'Cancelación', 'asistencia' => 'Asistencia de árbitro',
            'login' => 'Inicio de sesión'
        ];
        if ($accion === '' || !isset($etiquetas[$accion])) {
            return;
        }

        if ($pagina === 'login.php') {
            $detalle = 'El empleado inició sesión.';
        } else {
            $ok  = $GLOBALS['esExito'] ?? false;
            $msj = $GLOBALS['mensaje'] ?? '';
            if ($ok === true) {
                $idRef = intval($_POST['id'] ?? 0);
                $detalle = $msj . ($idRef > 0 ? " (id $idRef)" : "");
            } else {
                return;
            }
        }
        registrarMovimiento($modulos[$pagina], $etiquetas[$accion], $detalle);
    } catch (Throwable $e) {
    }
});

// Clase para revisar disponibilidad de canchas, arbitros y equipos sin consultar mil veces la BD
class Disponibilidad
{
    private $ocupado = [];

    // Carga todos los partidos activos desde una fecha para saber que ya esta ocupado
    public static function cargar(PDO $pdo, $desde, array $excluir = [])
    {
        $d = new self();
        $q = $pdo->prepare("SELECT id, fecha, hora, cancha, arbitro_id, local_id, visitante_id
                            FROM partidos WHERE estado != 'Cancelado' AND fecha >= ?");
        $q->execute([$desde]);
        foreach ($q->fetchAll() as $p) {
            if (in_array((int)$p['id'], $excluir, true)) {
                continue;
            }
            $d->ocupar($p['fecha'], $p['hora'], $p['cancha'], $p['arbitro_id'], $p['local_id'], $p['visitante_id']);
        }
        return $d;
    }

    private static function k($tipo, $fecha, $hora, $id)
    {
        return $tipo . '|' . $fecha . '|' . $hora . '|' . $id;
    }

    // Marca cancha, arbitro y los dos equipos como ocupados en esa fecha y hora
    public function ocupar($fecha, $hora, $cancha, $arbitro, $local, $visitante)
    {
        $this->ocupado[self::k('c', $fecha, $hora, $cancha)]    = true;
        $this->ocupado[self::k('a', $fecha, $hora, $arbitro)]   = true;
        $this->ocupado[self::k('e', $fecha, $hora, $local)]     = true;
        $this->ocupado[self::k('e', $fecha, $hora, $visitante)] = true;
    }

    public function canchaLibre($fecha, $hora, $cancha)
    {
        return !isset($this->ocupado[self::k('c', $fecha, $hora, $cancha)]);
    }

    public function arbitroLibre($fecha, $hora, $arbitro)
    {
        return !isset($this->ocupado[self::k('a', $fecha, $hora, $arbitro)]);
    }

    public function equipoLibre($fecha, $hora, $equipo)
    {
        return !isset($this->ocupado[self::k('e', $fecha, $hora, $equipo)]);
    }

    // Revisa al mismo tiempo que cancha, arbitro y ambos equipos esten libres
    public function slotLibre($fecha, $hora, $cancha, $arbitro = 0, $local = 0, $visitante = 0)
    {
        if (!$this->canchaLibre($fecha, $hora, $cancha)) return false;
        if ($arbitro   && !$this->arbitroLibre($fecha, $hora, $arbitro)) return false;
        if ($local     && !$this->equipoLibre($fecha, $hora, $local)) return false;
        if ($visitante && !$this->equipoLibre($fecha, $hora, $visitante)) return false;
        return true;
    }
}

// Arma todas las combinaciones de hora y cancha para un dia (saltando horas que ya pasaron si es hoy)
function fcCombinacionesDia($fecha, $cancha = 0)
{
    $combos = [];
    $canchas = $cancha ? [$cancha] : array_keys(FC_CANCHAS);
    foreach (FC_HORARIOS as $h) {
        if ($fecha === date('Y-m-d') && substr($h, 0, 5) <= date('H:i')) {
            continue;
        }
        foreach ($canchas as $c) {
            $combos[] = ['hora' => $h, 'cancha' => $c];
        }
    }
    return $combos;
}

// Busqueda automatica de horarios libres usando recursividad y busqueda secuencial:
// Revisa los espacios libres del dia actual con Busqueda::secuencialTodos y luego se llama a si misma
// avanzando dia por dia hasta juntar los horarios libres pedidos o acabar los dias
function fcBuscarHorariosLibres(Disponibilidad $disp, $fecha, $diasRestantes, array $c, $cantidad, array $acum = [])
{
    // Caso base de la recursividad: ya no quedan dias por revisar o ya juntamos los horarios necesarios
    if ($diasRestantes <= 0 || count($acum) >= $cantidad) {
        return $acum;
    }

    $combos = fcCombinacionesDia($fecha, $c['cancha'] ?? 0);
    // Usa busqueda secuencial explicita para filtrar los horarios libres de este dia
    $libres = Busqueda::secuencialTodos($combos, function ($combo) use ($disp, $fecha, $c) {
        return $disp->slotLibre(
            $fecha, $combo['hora'], $combo['cancha'],
            $c['arbitro'] ?? 0, $c['local'] ?? 0, $c['visitante'] ?? 0
        );
    });

    foreach ($libres as $l) {
        if (count($acum) >= $cantidad) break;
        $acum[] = ['fecha' => $fecha, 'hora' => $l['hora'], 'cancha' => $l['cancha']];
    }

    // Llamada recursiva para buscar en el dia siguiente
    $siguiente = date('Y-m-d', strtotime($fecha . ' +1 day'));
    return fcBuscarHorariosLibres($disp, $siguiente, $diasRestantes - 1, $c, $cantidad, $acum);
}

// Busca un solo horario libre (el mas cercano), muy util para reprogramacion por clima
function fcPrimerHorarioLibre(Disponibilidad $disp, $desde, array $c, $dias = 60)
{
    $r = fcBuscarHorariosLibres($disp, $desde, $dias, $c, 1);
    return $r ? $r[0] : null;
}

// Guarda la fecha y hora original la primera vez, actualiza el nuevo horario y suma 1 al contador de reprogramaciones
function fcAplicarReprogramacion(PDO $pdo, array $partido, $fecha, $hora, $cancha, $motivo)
{
    $q = $pdo->prepare("UPDATE partidos SET
            fecha_original = COALESCE(fecha_original, ?),
            hora_original  = COALESCE(hora_original, ?),
            fecha = ?, hora = ?, cancha = ?,
            reprogramaciones = reprogramaciones + 1,
            motivo_reprogramacion = ?,
            asistencia_arbitro = 'Pendiente'
        WHERE id = ?");
    $q->execute([$partido['fecha'], $partido['hora'], $fecha, $hora, $cancha, $motivo, $partido['id']]);
}

// Fotografias: guarda una imagen subida (JPG, PNG o WEBP) en la carpeta indicada.
// Si no subieron archivo regresa la ruta anterior; si algo falla llena $error y tambien regresa la anterior
function fcGuardarImagen($campo, $carpeta, $prefijo, $anterior, &$error)
{
    $error = '';
    if (empty($_FILES[$campo]['name'])) {
        return $anterior;
    }
    if ($_FILES[$campo]['error'] !== UPLOAD_ERR_OK) {
        $error = "No se pudo subir la imagen. Intenta con una más pequeña (máximo 2 MB).";
        return $anterior;
    }
    $ext = strtolower(pathinfo($_FILES[$campo]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || @getimagesize($_FILES[$campo]['tmp_name']) === false) {
        $error = "Formato de imagen no válido (usa JPG, PNG o WEBP).";
        return $anterior;
    }
    if ($_FILES[$campo]['size'] > 2 * 1024 * 1024) {
        $error = "La imagen pesa más de 2 MB. Usa una más ligera.";
        return $anterior;
    }
    $dir = rtrim($carpeta, '/') . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $nombreArchivo = uniqid($prefijo) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$campo]['tmp_name'], $dir . $nombreArchivo)) {
        $error = "No se pudo guardar la imagen en el servidor.";
        return $anterior;
    }
    fcBorrarImagen($anterior);
    return $dir . $nombreArchivo;
}

// Borra una foto guardada (solo si esta dentro de la carpeta uploads)
function fcBorrarImagen($ruta)
{
    if (!empty($ruta) && strpos($ruta, 'uploads/') === 0 && is_file($ruta)) {
        @unlink($ruta);
    }
}

// Dibuja la foto en pantalla; si no hay foto muestra un circulo con un icono
// tipo 'club' = escudo cuadrado redondeado, tipo 'persona' = circulo
function fcFoto($ruta, $tipo = 'persona', $tam = 40)
{
    $clase = $tipo === 'club' ? 'foto-club' : 'foto-persona';
    $icono = $tipo === 'club' ? 'fa-shield-halved' : 'fa-user';
    $estilo = 'width:' . (int)$tam . 'px;height:' . (int)$tam . 'px;';
    if (!empty($ruta) && is_file($ruta)) {
        return '<img src="' . esc($ruta) . '" alt="" class="' . $clase . '" style="' . $estilo . '">';
    }
    return '<span class="' . $clase . ' foto-vacia" style="' . $estilo . '"><i class="fa-solid ' . $icono . '"></i></span>';
}

// Muestra tabla de posiciones, goles y tarjetas, reprogramacion,
// cola de arbitros y cambio de contraseña y solo si es administrador muestra panel, empleados e historial
function fcMenuGestion($activo = '')
{
    $items = [
        ['posiciones.php',     'fa-ranking-star',      'Tabla de posiciones', false],
        ['partido_detalle.php','fa-futbol',            'Goles y tarjetas',    false],
        ['reprogramar.php',    'fa-calendar-plus',     'Reprogramación y horarios libres', false],
        ['arbitros_cola.php',  'fa-people-arrows',     'Fila de árbitros',    false],
        ['cambiar_password.php','fa-key',              'Cambiar contraseña',  false],
        ['admin.php',          'fa-gauge-high',        'Panel de administración', true],
        ['empleados.php',      'fa-users-gear',        'Empleados',           true],
        ['historial.php',      'fa-clock-rotate-left', 'Historial de movimientos', true],
    ];
    $esAdm = esAdmin();
    $html  = '<li class="nav-item dropdown">'
           . '<a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Gestión</a>'
           . '<ul class="dropdown-menu dropdown-menu-end">';
    $separado = false;
    foreach ($items as $it) {
        if ($it[3] && !$esAdm) continue;
        if ($it[3] && !$separado) {
            $html .= '<li><hr class="dropdown-divider"></li>';
            $separado = true;
        }
        $cls = ($activo === $it[0]) ? ' active' : '';
        $html .= '<li><a class="dropdown-item' . $cls . '" href="' . $it[0] . '"><i class="fa-solid ' . $it[1] . ' me-2 text-success"></i>' . $it[2] . '</a></li>';
    }
    return $html . '</ul></li>';
}

function fcHead($titulo)
{
    echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc($titulo) . ' - FutCancha</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/styles.css">
</head>
';
}

function fcNavbar($usr, $activo = '')
{
    $links = [
        'equipos.php' => 'Equipos', 'jugadores.php' => 'Jugadores',
        'arbitros.php' => 'Árbitros', 'partidos.php' => 'Partidos'
    ];
    echo '<nav class="navbar navbar-expand-lg navbar-custom sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="equipos.php">
            <i class="fa-solid fa-futbol text-success fs-3"></i>
            <span>FutCancha</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-lg-center">';
    foreach ($links as $href => $txt) {
        echo '<li class="nav-item"><a class="nav-link" href="' . $href . '">' . $txt . '</a></li>';
    }
    echo fcMenuGestion($activo);
    echo '<li class="nav-item ms-lg-2">' . renderNavAuth($usr) . '</li>
            </ul>
        </div>
    </div>
</nav>
';
}

function fcHero($titulo, $subtitulo)
{
    echo '<section class="py-5 bg-white border-bottom">
    <div class="container text-center py-2">
        <h1 class="display-5 fw-bold text-dark mt-2">' . esc($titulo) . '</h1>
        <p class="text-muted fs-5 max-w-700 mx-auto">' . esc($subtitulo) . '</p>
    </div>
</section>
';
}

function fcAlerta($mensaje, $ok)
{
    $extra = '';
    if (!empty($_SESSION['fc_error_esquema']) && esAdmin()) {
        $extra = '<div class="alert alert-warning py-2 mb-3"><strong>Aviso de esquema BD:</strong> ' . esc($_SESSION['fc_error_esquema']) . '</div>';
    }
    if ($mensaje === '' || $mensaje === null) return $extra;
    return $extra . '<div class="alert ' . ($ok ? 'alert-success' : 'alert-danger') . ' py-2 mb-3">' . esc($mensaje) . '</div>';
}

function fcPie()
{
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/imprimir.js"></script>
</body>
</html>';
}