-- Crea todas las tablas del sistema y carga datos de ejemplo para hacer pruebas

CREATE DATABASE IF NOT EXISTS futcancha_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE futcancha_db;

-- Tabla de usuarios: guarda a los empleados y administradores con su rol y estado activo
-- Sirve para administracion de empleados, permisos de administrador y cambio de contrasena
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    correo VARCHAR(120) NOT NULL UNIQUE,
    telefono VARCHAR(15) NOT NULL,
    password VARCHAR(255) NOT NULL,
    rol VARCHAR(20) NOT NULL DEFAULT 'Empleado',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Tabla de equipos: guarda los clubes inscritos por temporada (alimenta la tabla de posiciones)
CREATE TABLE IF NOT EXISTS equipos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    temporada VARCHAR(60) NOT NULL,
    foto VARCHAR(255) DEFAULT '',
    registrado_por VARCHAR(100) NOT NULL,
    UNIQUE KEY uk_equipo_temp (nombre, temporada)
);

-- Tabla de arbitros: incluye la columna turno_cola para manejar las colas de arbitros en orden de llegada
CREATE TABLE IF NOT EXISTS arbitros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    telefono VARCHAR(15) NOT NULL UNIQUE,
    correo VARCHAR(120) NOT NULL UNIQUE,
    estado ENUM('Disponible', 'Confirmado', 'No Disponible') DEFAULT 'Disponible',
    turno_cola INT NOT NULL DEFAULT 0,
    foto VARCHAR(255) DEFAULT '',
    registrado_por VARCHAR(100) NOT NULL
);

-- Tabla de jugadores: guarda los integrantes de cada equipo, su numero y sus goles totales
CREATE TABLE IF NOT EXISTS jugadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipo_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    numero INT NOT NULL,
    posicion ENUM('Portero', 'Defensa', 'Medio', 'Delantero') NOT NULL,
    foto VARCHAR(255) DEFAULT '',
    goles INT DEFAULT 0,
    registrado_por VARCHAR(100) NOT NULL,
    UNIQUE KEY uk_equipo_num (equipo_id, numero),
    FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE RESTRICT
);

-- Tabla de partidos: guarda fecha, hora, cancha, marcador, estado y datos de reprogramacion de partidos
-- Las reglas uk_cancha_horario y uk_arbitro_horario impiden que se empalmen partidos activos
CREATE TABLE IF NOT EXISTS partidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    local_id INT NOT NULL,
    visitante_id INT NOT NULL,
    cancha INT NOT NULL,
    fecha DATE NOT NULL,
    hora VARCHAR(20) NOT NULL,
    arbitro_id INT NOT NULL,
    asistencia_arbitro ENUM('Pendiente', 'Confirmada') DEFAULT 'Pendiente',
    goles_local INT DEFAULT NULL,
    goles_visitante INT DEFAULT NULL,
    estado ENUM('Programado', 'Finalizado', 'Cancelado') DEFAULT 'Programado',
    reprogramaciones INT NOT NULL DEFAULT 0,
    motivo_reprogramacion VARCHAR(255) NULL,
    fecha_original DATE NULL,
    hora_original VARCHAR(20) NULL,
    activo_slot TINYINT(1) AS (IF(estado != 'Cancelado', 1, NULL)) STORED,
    registrado_por VARCHAR(100) NOT NULL,
    UNIQUE KEY uk_cancha_horario (cancha, fecha, hora, activo_slot),
    UNIQUE KEY uk_arbitro_horario (arbitro_id, fecha, hora, activo_slot),
    FOREIGN KEY (local_id) REFERENCES equipos(id) ON DELETE RESTRICT,
    FOREIGN KEY (visitante_id) REFERENCES equipos(id) ON DELETE RESTRICT,
    FOREIGN KEY (arbitro_id) REFERENCES arbitros(id) ON DELETE RESTRICT
);

-- Tabla de goles: sirve para el registro de goles por partido y funciona junto con las pilas para deshacer goles
CREATE TABLE IF NOT EXISTS goles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partido_id INT NOT NULL,
    jugador_id INT NOT NULL,
    equipo_id INT NOT NULL,
    minuto INT NOT NULL DEFAULT 0,
    registrado_por VARCHAR(100) NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (partido_id) REFERENCES partidos(id) ON DELETE CASCADE,
    FOREIGN KEY (jugador_id) REFERENCES jugadores(id) ON DELETE CASCADE,
    FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE RESTRICT
);

-- Tabla de tarjetas: guarda el registro de tarjetas amarillas y rojas de cada partido
CREATE TABLE IF NOT EXISTS tarjetas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partido_id INT NOT NULL,
    jugador_id INT NOT NULL,
    equipo_id INT NOT NULL,
    tipo ENUM('Amarilla', 'Roja') NOT NULL,
    minuto INT NOT NULL DEFAULT 0,
    registrado_por VARCHAR(100) NOT NULL,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (partido_id) REFERENCES partidos(id) ON DELETE CASCADE,
    FOREIGN KEY (jugador_id) REFERENCES jugadores(id) ON DELETE CASCADE,
    FOREIGN KEY (equipo_id) REFERENCES equipos(id) ON DELETE RESTRICT
);

-- Tabla de historial: guarda el historial de movimientos que hacen los empleados y administradores
CREATE TABLE IF NOT EXISTS historial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    usuario VARCHAR(100) NOT NULL,
    modulo VARCHAR(50) NOT NULL,
    accion VARCHAR(60) NOT NULL,
    detalle TEXT,
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

-- Datos iniciales de prueba para entrar al sistema (contrasena de todos: password)
INSERT IGNORE INTO usuarios (id, nombre, correo, telefono, password, rol, activo) VALUES
(1, 'Administrador General', 'admin@futcancha.com', '3312345678', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrador', 1),
(2, 'Pedro Garcia', 'pedro@futcancha.com', '3312345677', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Empleado', 1),
(3, 'Carlos Mendoza', 'empleado@futcancha.com', '3387654321', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Empleado', 1);

-- Equipos de ejemplo
INSERT IGNORE INTO equipos (id, nombre, temporada, registrado_por) VALUES
(1, 'Atlético Zapopan',   'Apertura 2026', 'Administrador General'),
(2, 'Deportivo Tapatío',  'Apertura 2026', 'Administrador General'),
(3, 'Real Providencia',   'Apertura 2026', 'Carlos Mendoza'),
(4, 'Leones del Sur',     'Apertura 2026', 'Carlos Mendoza');

-- Arbitros de ejemplo
INSERT IGNORE INTO arbitros (id, nombre, telefono, correo, estado, turno_cola, registrado_por) VALUES
(1, 'Roberto García',  '3311112222', 'roberto.arb@futcancha.com', 'Disponible', 1, 'Administrador General'),
(2, 'Fernando López',  '3322223333', 'fernando.arb@futcancha.com', 'Confirmado', 2, 'Administrador General'),
(3, 'Miguel Hernández','3333334444', 'miguel.arb@futcancha.com',   'Disponible', 3, 'Carlos Mendoza'),
(4, 'Javier Torres',   '3344445555', 'javier.arb@futcancha.com',   'No Disponible', 4, 'Carlos Mendoza');

-- Jugadores de ejemplo
INSERT IGNORE INTO jugadores (id, equipo_id, nombre, numero, posicion, goles, registrado_por) VALUES
(1,  1, 'Luis Ramírez',     1,  'Portero',   0, 'Administrador General'),
(2,  1, 'Diego Morales',    4,  'Defensa',   0, 'Administrador General'),
(3,  1, 'Andrés Ortega',    9,  'Delantero', 2, 'Administrador General'),
(4,  2, 'Jorge Navarro',    1,  'Portero',   0, 'Administrador General'),
(5,  2, 'Héctor Ruiz',      8,  'Medio',     1, 'Administrador General'),
(6,  2, 'Sebastián Cruz',   10, 'Delantero', 0, 'Administrador General'),
(7,  3, 'Alejandro Soto',   1,  'Portero',   0, 'Carlos Mendoza'),
(8,  3, 'Mateo Delgado',    5,  'Defensa',   0, 'Carlos Mendoza'),
(9,  3, 'Emiliano Vargas',  11, 'Delantero', 0, 'Carlos Mendoza'),
(10, 4, 'Nicolás Silva',    1,  'Portero',   0, 'Carlos Mendoza'),
(11, 4, 'Gabriel Castro',   7,  'Medio',     0, 'Carlos Mendoza'),
(12, 4, 'Óscar Ríos',       9,  'Delantero', 0, 'Carlos Mendoza');

-- Partidos de ejemplo
INSERT IGNORE INTO partidos (id, local_id, visitante_id, cancha, fecha, hora, arbitro_id, asistencia_arbitro, goles_local, goles_visitante, estado, registrado_por) VALUES
(1, 1, 2, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), '18:00 - 19:00', 1, 'Confirmada', 2, 1, 'Finalizado', 'Administrador General'),
(2, 3, 4, 2, DATE_ADD(CURDATE(), INTERVAL 2 DAY), '19:00 - 20:00', 2, 'Confirmada', NULL, NULL, 'Programado', 'Carlos Mendoza'),
(3, 1, 3, 1, DATE_ADD(CURDATE(), INTERVAL 3 DAY), '14:00 - 15:00', 3, 'Pendiente', NULL, NULL, 'Cancelado', 'Carlos Mendoza');

-- Goles de ejemplo del partido 1
INSERT IGNORE INTO goles (id, partido_id, jugador_id, equipo_id, minuto, registrado_por) VALUES
(1, 1, 3, 1, 22, 'Administrador General'),
(2, 1, 5, 2, 41, 'Administrador General'),
(3, 1, 3, 1, 78, 'Administrador General');

-- Tarjetas de ejemplo del partido 1
INSERT IGNORE INTO tarjetas (id, partido_id, jugador_id, equipo_id, tipo, minuto, registrado_por) VALUES
(1, 1, 2, 1, 'Amarilla', 55, 'Administrador General'),
(2, 1, 5, 2, 'Amarilla', 67, 'Administrador General');

-- Movimientos iniciales en el historial
INSERT IGNORE INTO historial (id, usuario_id, usuario, modulo, accion, detalle) VALUES
(1, 1, 'Administrador General', 'Equipos',  'Alta', 'Carga inicial de equipos temporada Apertura 2026.'),
(2, 1, 'Administrador General', 'Partidos', 'Partido finalizado', 'Partido #1 (Atlético Zapopan 2 - 1 Deportivo Tapatío) finalizado.'),
(3, 3, 'Carlos Mendoza',        'Partidos', 'Cancelación', 'Partido #3 cancelado; cancha y árbitro liberados.');