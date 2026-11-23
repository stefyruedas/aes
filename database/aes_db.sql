-- =============================================================
-- Gestión AES - Hostal Casa Zen
-- Script de creación de base de datos MySQL
-- Compatible con XAMPP (MySQL / MariaDB)
-- =============================================================
-- Para importar:
--   1. Abrir phpMyAdmin (http://localhost/phpmyadmin)
--   2. Importar este archivo, o pegar su contenido en la pestaña SQL.
-- =============================================================

DROP DATABASE IF EXISTS aes_db;
CREATE DATABASE aes_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE aes_db;

-- -------------------------------------------------------------
-- Tabla 4: rol
-- -------------------------------------------------------------
CREATE TABLE rol (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    nombre_rol  VARCHAR(30) NOT NULL UNIQUE,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla 1: hostal
-- -------------------------------------------------------------
CREATE TABLE hostal (
    id        INT(11) NOT NULL AUTO_INCREMENT,
    nombre    VARCHAR(100) NOT NULL,
    direccion TEXT NOT NULL,
    telefono  VARCHAR(15) NOT NULL,
    nit       VARCHAR(30) NOT NULL UNIQUE,
    rut       VARCHAR(30) NOT NULL UNIQUE,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla 3: usuario
-- Correcciones aplicadas:
--   - contrasena VARCHAR(255) para almacenar hash (password_hash)
--   - tipo_identificacion (pedido por la interfaz de registro)
--   - estado (pendiente / activo / inactivo) para validación por admin
--   - ultimo_inicio_sesion / ultimo_cierre_sesion (pedido por gestión de usuarios)
--   - fecha_creacion para auditoría
-- -------------------------------------------------------------
CREATE TABLE usuario (
    id                   INT(11) NOT NULL AUTO_INCREMENT,
    id_rol               INT(11) NOT NULL,
    usuario              VARCHAR(100) NOT NULL UNIQUE,
    contrasena           VARCHAR(255) NOT NULL,
    telefono             VARCHAR(15) NOT NULL,
    nombre               VARCHAR(100) NOT NULL,
    email                VARCHAR(100) NOT NULL UNIQUE,
    tipo_identificacion  VARCHAR(20)  NOT NULL DEFAULT 'CC',
    dni                  VARCHAR(20) NOT NULL UNIQUE,
    estado               ENUM('pendiente','activo','inactivo') NOT NULL DEFAULT 'pendiente',
    ultimo_inicio_sesion DATETIME NULL,
    ultimo_cierre_sesion DATETIME NULL,
    fecha_creacion       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_usuario_rol FOREIGN KEY (id_rol) REFERENCES rol(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla auxiliar: codigo_verificacion
-- (Correccion: necesaria para los codigos de 4 digitos de
--  registro/activacion y recuperacion de contrasena, con
--  expiracion de 10 minutos.)
-- -------------------------------------------------------------
CREATE TABLE codigo_verificacion (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    id_usuario    INT(11) NOT NULL,
    codigo        VARCHAR(4) NOT NULL,
    tipo          ENUM('activacion','recuperacion') NOT NULL,
    usado         TINYINT(1) NOT NULL DEFAULT 0,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_expira   DATETIME NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_codigo_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla 2: huesped
-- -------------------------------------------------------------
CREATE TABLE huesped (
    id           INT(11) NOT NULL AUTO_INCREMENT,
    id_hostal    INT(11) NOT NULL,
    nombre       VARCHAR(100) NOT NULL,
    dni          VARCHAR(20) NOT NULL UNIQUE,
    telefono     VARCHAR(15) NOT NULL,
    email        VARCHAR(100) NULL,
    nacionalidad VARCHAR(50) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_huesped_hostal FOREIGN KEY (id_hostal) REFERENCES hostal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla NUEVA (correccion): habitacion
-- El documento maneja "tipo/nombre/numero de habitacion" dentro
-- de reservas, pero para controlar disponibilidad real y evitar
-- cruces conviene una tabla de habitaciones.
-- -------------------------------------------------------------
CREATE TABLE habitacion (
    id              INT(11) NOT NULL AUTO_INCREMENT,
    id_hostal       INT(11) NOT NULL,
    nombre          VARCHAR(50) NOT NULL,
    numero          VARCHAR(10) NOT NULL,
    tipo            VARCHAR(50) NOT NULL,
    capacidad       INT(11) NOT NULL,
    precio_noche    DECIMAL(10,2) NOT NULL,
    estado          ENUM('disponible','ocupada','mantenimiento') NOT NULL DEFAULT 'disponible',
    PRIMARY KEY (id),
    UNIQUE KEY uq_habitacion_numero (id_hostal, numero),
    CONSTRAINT fk_habitacion_hostal FOREIGN KEY (id_hostal) REFERENCES hostal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla 5: disponibilidad
-- -------------------------------------------------------------
CREATE TABLE disponibilidad (
    id                 INT(11) NOT NULL AUTO_INCREMENT,
    id_habitacion      INT(11) NOT NULL,
    habitaciones_disp  SMALLINT NOT NULL DEFAULT 1,
    habitaciones_ocu   SMALLINT NOT NULL DEFAULT 0,
    fecha_disponibilidad DATE NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_disp_habitacion FOREIGN KEY (id_habitacion) REFERENCES habitacion(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla 6: reservas
-- -------------------------------------------------------------
CREATE TABLE reservas (
    id               INT(11) NOT NULL AUTO_INCREMENT,
    id_huesped       INT(11) NOT NULL,
    id_usuario       INT(11) NOT NULL,
    id_habitacion    INT(11) NOT NULL,
    canal_reserva    VARCHAR(50) NOT NULL,
    tipo_habitacion  VARCHAR(50) NOT NULL,
    fecha_inicio     DATE NOT NULL,
    fecha_fin        DATE NOT NULL,
    estado           ENUM('pendiente','activa','finalizada','cancelada') NOT NULL DEFAULT 'pendiente',
    nombre_habitacion VARCHAR(50) NOT NULL,
    capacidad        INT(11) NOT NULL,
    precio_noche     DECIMAL(10,2) NOT NULL,
    numero_huespedes INT(11) NOT NULL DEFAULT 1,
    observaciones    VARCHAR(250) NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_reserva_huesped FOREIGN KEY (id_huesped) REFERENCES huesped(id),
    CONSTRAINT fk_reserva_usuario FOREIGN KEY (id_usuario) REFERENCES usuario(id),
    CONSTRAINT fk_reserva_habitacion FOREIGN KEY (id_habitacion) REFERENCES habitacion(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla 7: check_in
-- -------------------------------------------------------------
CREATE TABLE check_in (
    id               INT(11) NOT NULL AUTO_INCREMENT,
    id_reservas      INT(11) NULL,
    id_huesped       INT(11) NOT NULL,
    cantidad_huesped SMALLINT NOT NULL DEFAULT 1,
    fecha_llegada    DATETIME NOT NULL,
    numero_personas  INT(11) NOT NULL DEFAULT 1,
    observaciones    VARCHAR(250) NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_checkin_reservas FOREIGN KEY (id_reservas) REFERENCES reservas(id),
    CONSTRAINT fk_checkin_huesped FOREIGN KEY (id_huesped) REFERENCES huesped(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla 8: servicios_adicionales
-- -------------------------------------------------------------
CREATE TABLE servicios_adicionales (
    id              INT(11) NOT NULL AUTO_INCREMENT,
    id_check_in     INT(11) NOT NULL,
    nombre_servicio VARCHAR(100) NOT NULL,
    precio          DECIMAL(10,2) NOT NULL,
    observacion     VARCHAR(250) NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_servicio_checkin FOREIGN KEY (id_check_in) REFERENCES check_in(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla 9: check_out
-- -------------------------------------------------------------
CREATE TABLE check_out (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    id_servicios  INT(11) NULL,
    id_check_in   INT(11) NOT NULL,
    fecha_salida  DATETIME NOT NULL,
    valor_alojamiento DECIMAL(10,2) NOT NULL DEFAULT 0,
    valor_servicios   DECIMAL(10,2) NOT NULL DEFAULT 0,
    total         DECIMAL(10,2) NOT NULL DEFAULT 0,
    estado_pago   ENUM('pendiente','pagado') NOT NULL DEFAULT 'pendiente',
    PRIMARY KEY (id),
    CONSTRAINT fk_checkout_servicios FOREIGN KEY (id_servicios) REFERENCES servicios_adicionales(id),
    CONSTRAINT fk_checkout_checkin FOREIGN KEY (id_check_in) REFERENCES check_in(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla 10: finanzas
-- -------------------------------------------------------------
CREATE TABLE finanzas (
    id           INT(11) NOT NULL AUTO_INCREMENT,
    id_check_out INT(11) NULL,
    fecha        DATE NOT NULL,
    tipo         VARCHAR(30) NOT NULL,
    concepto     TEXT NOT NULL,
    monto        DECIMAL(10,2) NOT NULL,
    medio_pago   VARCHAR(30) NOT NULL,
    es_fijo      TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT fk_finanzas_checkout FOREIGN KEY (id_check_out) REFERENCES check_out(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------------------------
-- Tabla 11: inventario
-- -------------------------------------------------------------
CREATE TABLE inventario (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    tipo_producto VARCHAR(100) NOT NULL,
    nombre        VARCHAR(100) NOT NULL,
    stock         SMALLINT NOT NULL DEFAULT 0,
    descripcion   VARCHAR(150) NOT NULL,
    stock_minimo  INT(11) NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================
-- DATOS INICIALES (SEED)
-- =============================================================

-- Roles
INSERT INTO rol (nombre_rol) VALUES ('administrador'), ('operario');

-- Hostal
INSERT INTO hostal (nombre, direccion, telefono, nit, rut) VALUES
('Hostal Casa Zen', 'Calle 10 # 5-20, Centro, Ciudad', '3001234567', '900123456-7', 'RUT-900123456');

-- Usuario administrador por defecto:
--   El usuario admin se crea ejecutando una vez el instalador web:
--   http://localhost/aes/instalar.php
--   (Esto garantiza que el hash de la contrasena sea valido para tu
--    version de PHP.  Credenciales:  usuario: admin  /  contrasena: Admin123)

-- Habitaciones de ejemplo
INSERT INTO habitacion (id_hostal, nombre, numero, tipo, capacidad, precio_noche, estado) VALUES
(1, 'Zen Individual', '101', 'Individual', 1, 60000.00, 'disponible'),
(1, 'Zen Doble',      '102', 'Doble',      2, 90000.00, 'disponible'),
(1, 'Zen Familiar',   '103', 'Familiar',   4, 150000.00,'disponible'),
(1, 'Zen Suite',      '201', 'Suite',      2, 180000.00,'disponible');

-- Inventario de ejemplo
INSERT INTO inventario (tipo_producto, nombre, stock, descripcion, stock_minimo) VALUES
('Aseo',   'Jabon corporal',  50, 'Jabon corporal individual para huespedes', 10),
('Lenceria','Toallas',        40, 'Toallas de bano blancas',                   15),
('Aseo',   'Papel higienico', 80, 'Rollos de papel higienico doble hoja',      20),
('Lenceria','Sabanas',        30, 'Juego de sabanas matrimoniales',            10),
('Bebida', 'Agua',            60, 'Bolsa/botella de agua 500ml',               20),
('Bebida', 'Cerveza',         48, 'Cerveza nacional 330ml',                    12);

-- Egresos fijos de ejemplo (finanzas)
INSERT INTO finanzas (fecha, tipo, concepto, monto, medio_pago, es_fijo) VALUES
(CURRENT_DATE, 'egreso', 'Arriendo del local', 1500000.00, 'consignacion', 1),
(CURRENT_DATE, 'egreso', 'Servicios publicos', 400000.00, 'consignacion', 1),
(CURRENT_DATE, 'egreso', 'Nomina', 2500000.00, 'consignacion', 1);
