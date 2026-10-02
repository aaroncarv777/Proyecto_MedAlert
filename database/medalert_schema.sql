-- ═══════════════════════════════════════════════════════════════════════════
-- MedAlert · Esquema + (versión completa)
-- ═══════════════════════════════════════════════════════════════════════════
-- SPRINT 2 incluye:
-- ✓ cédula profesional + especialidad + validación admin
-- ✓ relación médico-paciente formal
-- ✓ horarios avanzados (días específicos, alternos, antes/después de comida)
-- ✓ interacciones medicamentosas (catálogo + niveles + justificación)
-- ✓ cálculo automático de dosis por peso/edad + dosis máximas
-- ✓ stock de medicamento + alertas de bajo stock
-- ✓ historial completo de cambios
-- ✓ notificaciones multicanal (app/correo/SMS) + registro de envíos
-- ✓ tres avisos escalonados (15min antes, 5min antes, 10min después)
-- ═══════════════════════════════════════════════════════════════════════════

-- Fuerza la codificación de la sesión de carga a UTF-8 para que los ENUM
-- con acentos (Cápsula, inhalación…) se almacenen correctamente, incluso
-- si el cliente mysql/mariadb no recibió --default-character-set=utf8mb4.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET character_set_client = utf8mb4;
SET character_set_connection = utf8mb4;
SET character_set_results = utf8mb4;

DROP DATABASE IF EXISTS medalert;
CREATE DATABASE medalert
 CHARACTER SET utf8mb4
 COLLATE utf8mb4_unicode_ci;
USE medalert;

-- ─── tipos_usuario ─────────────────────────────────────────────────────────
CREATE TABLE tipos_usuario (
 id_tipo INT PRIMARY KEY AUTO_INCREMENT,
 nombre VARCHAR(30) NOT NULL UNIQUE,
 descripcion VARCHAR(255) NOT NULL,
 permisos JSON NOT NULL,
 permite_registro_publico BOOLEAN NOT NULL DEFAULT FALSE,
 activo BOOLEAN NOT NULL DEFAULT TRUE,
 fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP()
) ENGINE=InnoDB;

INSERT INTO tipos_usuario (nombre, descripcion, permisos, permite_registro_publico) VALUES
('Paciente', 'Usuario que registra sus medicamentos y marca sus tomas',
 '{"medicamentos":["crear_propios","editar_propios","eliminar_propios"],"tomas":["marcar"]}', TRUE),
('Cuidador', 'Familiar o tutor del paciente con vista limitada',
 '{"medicamentos":["ver_paciente_asignado"],"tomas":["ver_paciente_asignado"]}', FALSE),
('Medico', 'Profesional de la salud que prescribe medicamentos',
 '{"medicamentos":["prescribir","editar_propios"]}', FALSE),
('Administrador', 'Gestor del sistema con acceso completo',
 '{"sistema":["gestionar_usuarios","validar_medicos"]}', FALSE),
('Enfermero', 'Personal de enfermería que administra tomas en entorno hospitalario',
 '{"tomas":["registrar_masivo_turno"],"modo_hospitalario":["acceder"]}', FALSE);

-- ─── catálogo de preguntas de seguridad ────────────────────────────────────
CREATE TABLE catalogo_preguntas_seguridad (
 id_pregunta INT PRIMARY KEY AUTO_INCREMENT,
 pregunta VARCHAR(150) NOT NULL UNIQUE,
 activa BOOLEAN NOT NULL DEFAULT TRUE,
 orden INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO catalogo_preguntas_seguridad (pregunta, orden) VALUES
('¿Cuál es el nombre de tu primera mascota?', 1),
('¿En qué ciudad naciste?', 2),
('¿Cuál es tu comida favorita?', 3),
('¿Cuál es el segundo nombre de tu madre?', 4),
('¿Cuál fue el nombre de tu primera escuela?', 5),
('¿Cómo se llamaba tu mejor amigo en la infancia?', 6),
('¿Cuál es tu película favorita?', 7),
('¿En qué año te graduaste de la preparatoria?', 8),
('¿Cuál es el nombre de tu calle de la infancia?', 9),
('¿Cuál es tu deporte favorito?', 10);

-- ─── usuarios ──────────────────────────────────────────────────────────────
CREATE TABLE usuarios (
 id_usuario INT PRIMARY KEY AUTO_INCREMENT,
 nombre_completo VARCHAR(100) NOT NULL,
 correo VARCHAR(120) NOT NULL UNIQUE,
 telefono VARCHAR(20),
 contrasena_hash VARCHAR(255) NOT NULL,
 id_tipo INT NOT NULL,
 estado_cuenta ENUM('pendiente_verificacion','activa','suspendida','bloqueada')
 NOT NULL DEFAULT 'pendiente_verificacion',
 codigo_verificacion VARCHAR(10) DEFAULT NULL,
 fecha_codigo_verificacion DATETIME DEFAULT NULL,
 fecha_expiracion_verificacion DATETIME DEFAULT NULL,
 intentos_verificacion INT NOT NULL DEFAULT 0,
 intentos_fallidos INT NOT NULL DEFAULT 0,
 fecha_bloqueo DATETIME DEFAULT NULL,
 confirmar_marcar_toma BOOLEAN NOT NULL DEFAULT FALSE,
 -- preferencias de avisos escalonados
 aviso_temprano_activo BOOLEAN NOT NULL DEFAULT TRUE,
 aviso_recordatorio_activo BOOLEAN NOT NULL DEFAULT TRUE,
 aviso_tardio_activo BOOLEAN NOT NULL DEFAULT TRUE,
 -- canales de notificación globales
 canal_app_activo BOOLEAN NOT NULL DEFAULT TRUE,
 canal_correo_activo BOOLEAN NOT NULL DEFAULT TRUE,
 canal_sms_activo BOOLEAN NOT NULL DEFAULT FALSE,
 -- datos clínicos del paciente
 peso_kg DECIMAL(5,2) DEFAULT NULL,
 fecha_nacimiento DATE DEFAULT NULL,
 -- horas habituales de comida para referencia "antes/después de comida"
 hora_desayuno TIME DEFAULT '08:00:00',
 hora_comida TIME DEFAULT '14:00:00',
 hora_cena TIME DEFAULT '20:00:00',
 -- campos del médico
 cedula_profesional VARCHAR(30) DEFAULT NULL,
 especialidad VARCHAR(80) DEFAULT NULL,
 imagen_cedula VARCHAR(255) DEFAULT NULL,
 cedula_validada BOOLEAN NOT NULL DEFAULT FALSE,
 fecha_validacion_cedula DATETIME DEFAULT NULL,
 id_admin_validador INT DEFAULT NULL,
 motivo_rechazo_cedula VARCHAR(500) DEFAULT NULL,
 fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP(),
 fecha_ultima_sesion DATETIME DEFAULT NULL,
 foto_perfil VARCHAR(255) DEFAULT NULL,
 FOREIGN KEY (id_tipo) REFERENCES tipos_usuario(id_tipo) ON DELETE RESTRICT,
 INDEX idx_correo (correo),
 INDEX idx_estado (estado_cuenta),
 INDEX idx_cedula_validada (cedula_validada)
) ENGINE=InnoDB;

CREATE TABLE preguntas_seguridad (
 id_respuesta INT PRIMARY KEY AUTO_INCREMENT,
 id_usuario INT NOT NULL,
 id_pregunta INT NOT NULL,
 respuesta_hash VARCHAR(255) NOT NULL,
 orden TINYINT NOT NULL,
 fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_pregunta) REFERENCES catalogo_preguntas_seguridad(id_pregunta) ON DELETE RESTRICT,
 UNIQUE KEY uniq_usuario_orden (id_usuario, orden),
 UNIQUE KEY uniq_usuario_pregunta (id_usuario, id_pregunta)
) ENGINE=InnoDB;

CREATE TABLE recuperacion_codigos (
 id_codigo INT PRIMARY KEY AUTO_INCREMENT,
 correo VARCHAR(120) NOT NULL,
 token VARCHAR(10) NOT NULL,
 expires_at DATETIME NOT NULL,
 last_sent_at DATETIME DEFAULT CURRENT_TIMESTAMP(),
 send_count INT NOT NULL DEFAULT 1,
 failed_attempts INT NOT NULL DEFAULT 0,
 blocked_until DATETIME DEFAULT NULL,
 used BOOLEAN NOT NULL DEFAULT FALSE,
 fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 INDEX idx_correo (correo),
 INDEX idx_used (used)
) ENGINE=InnoDB;

CREATE TABLE cambios_perfil_pendientes (
 id_cambio INT PRIMARY KEY AUTO_INCREMENT,
 id_usuario INT NOT NULL,
 tipo_cambio ENUM('correo','contrasena','nombre') NOT NULL,
 valor_nuevo VARCHAR(255) NOT NULL,
 correo_destino VARCHAR(120) NOT NULL,
 token VARCHAR(10) NOT NULL,
 expires_at DATETIME NOT NULL,
 failed_attempts INT NOT NULL DEFAULT 0,
 blocked_until DATETIME DEFAULT NULL,
 aplicado BOOLEAN NOT NULL DEFAULT FALSE,
 fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 INDEX idx_usuario_tipo (id_usuario, tipo_cambio)
) ENGINE=InnoDB;

CREATE TABLE sesiones_activas (
 id_sesion INT PRIMARY KEY AUTO_INCREMENT,
 id_usuario INT NOT NULL,
 token_sesion VARCHAR(255) NOT NULL UNIQUE,
 dispositivo VARCHAR(150),
 ip_origen VARCHAR(45),
 fecha_inicio DATETIME DEFAULT CURRENT_TIMESTAMP(),
 fecha_expiracion DATETIME NOT NULL,
 activa BOOLEAN NOT NULL DEFAULT TRUE,
 cerrada_por_otra_sesion BOOLEAN NOT NULL DEFAULT FALSE,
 FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 INDEX idx_token (token_sesion),
 INDEX idx_usuario_activa (id_usuario, activa)
) ENGINE=InnoDB;

CREATE TABLE log_cambios_tipo_usuario (
 id_log INT PRIMARY KEY AUTO_INCREMENT,
 id_usuario_afectado INT NOT NULL,
 id_admin INT NOT NULL,
 tipo_anterior VARCHAR(30) NOT NULL,
 tipo_nuevo VARCHAR(30) NOT NULL,
 fecha_cambio DATETIME DEFAULT CURRENT_TIMESTAMP(),
 motivo VARCHAR(255) DEFAULT NULL,
 FOREIGN KEY (id_usuario_afectado) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_admin) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE cuidador_paciente (
 id_relacion INT PRIMARY KEY AUTO_INCREMENT,
 id_cuidador INT NOT NULL,
 id_paciente INT NOT NULL,
 parentesco VARCHAR(50) DEFAULT NULL,
 asignada_por INT NOT NULL,
 fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 fecha_revocacion DATETIME DEFAULT NULL,
 activa BOOLEAN NOT NULL DEFAULT TRUE,
 FOREIGN KEY (id_cuidador) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (asignada_por) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
 UNIQUE KEY uniq_cuidador_paciente (id_cuidador, id_paciente),
 INDEX idx_cuidador (id_cuidador, activa),
 INDEX idx_paciente (id_paciente, activa)
) ENGINE=InnoDB;

-- ════════════════════════════════════════════════════════════════════════════
-- SPRINT 2 · TABLAS NUEVAS
-- ════════════════════════════════════════════════════════════════════════════

-- ─── relación formal médico-paciente ────────────────────────────────
CREATE TABLE medico_paciente (
 id_relacion INT PRIMARY KEY AUTO_INCREMENT,
 id_medico INT NOT NULL,
 id_paciente INT NOT NULL,
 asignada_por INT NOT NULL,
 notas_clinicas TEXT DEFAULT NULL,
 notas_clinicas_fecha DATETIME DEFAULT NULL,
 fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 fecha_revocacion DATETIME DEFAULT NULL,
 activa BOOLEAN NOT NULL DEFAULT TRUE,
 FOREIGN KEY (id_medico) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (asignada_por) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
 UNIQUE KEY uniq_medico_paciente (id_medico, id_paciente),
 INDEX idx_medico (id_medico, activa),
 INDEX idx_paciente (id_paciente, activa)
) ENGINE=InnoDB;

-- ─── log de validación de credenciales médicas ─────────────────────
CREATE TABLE log_validacion_cedulas (
 id_log INT PRIMARY KEY AUTO_INCREMENT,
 id_medico INT NOT NULL,
 id_admin INT NOT NULL,
 accion ENUM('aprobado','rechazado','revocado') NOT NULL,
 motivo VARCHAR(500) DEFAULT NULL,
 cedula VARCHAR(30) DEFAULT NULL,
 especialidad VARCHAR(80) DEFAULT NULL,
 fecha_accion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_medico) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_admin) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
 INDEX idx_medico (id_medico)
) ENGINE=InnoDB;

-- ─── catálogo de interacciones medicamentosas ──────────────────────
CREATE TABLE catalogo_interacciones (
 id_interaccion INT PRIMARY KEY AUTO_INCREMENT,
 medicamento_a VARCHAR(120) NOT NULL,
 medicamento_b VARCHAR(120) NOT NULL,
 nivel_riesgo ENUM('leve','moderada','grave') NOT NULL,
 descripcion TEXT NOT NULL,
 consecuencia_clinica VARCHAR(200) DEFAULT NULL, -- frase corta para mostrar al paciente
 recomendacion TEXT DEFAULT NULL,
 fuente VARCHAR(150) DEFAULT NULL,
 fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP(),
 activa BOOLEAN NOT NULL DEFAULT TRUE,
 INDEX idx_med_a (medicamento_a),
 INDEX idx_med_b (medicamento_b),
 INDEX idx_nivel (nivel_riesgo)
) ENGINE=InnoDB;

-- ─── tabla de dosis máximas recomendadas por grupo de edad ────────
CREATE TABLE dosis_maximas (
 id_dosis_max INT PRIMARY KEY AUTO_INCREMENT,
 nombre_medicamento VARCHAR(120) NOT NULL,
 grupo_edad ENUM('lactante','pediatrico','adolescente','adulto','adulto_mayor') NOT NULL,
 edad_min_anios INT NOT NULL DEFAULT 0,
 edad_max_anios INT NOT NULL DEFAULT 120,
 dosis_max_mg_kg_dia DECIMAL(8,3) NOT NULL,
 dosis_max_total_dia_mg DECIMAL(10,2) DEFAULT NULL,
 fuente VARCHAR(150) DEFAULT NULL,
 activa BOOLEAN NOT NULL DEFAULT TRUE,
 INDEX idx_medicamento_grupo (nombre_medicamento, grupo_edad)
) ENGINE=InnoDB;

-- ─── medicamentos (+ + + ) ─────────────────────────
CREATE TABLE medicamentos (
 id_medicamento INT PRIMARY KEY AUTO_INCREMENT,
 id_usuario_paciente INT NOT NULL,
 id_usuario_creador INT NOT NULL,
 nombre VARCHAR(120) NOT NULL,
 presentacion ENUM('Tableta','Tableta masticable','Cápsula','Jarabe',
 'Inyectable','Gotas','Crema','Inhalador','Otro')
 NOT NULL DEFAULT 'Tableta',
 cantidad_dosis DECIMAL(10,2) NOT NULL DEFAULT 1,
 unidad_dosis ENUM('mg','g','mcg','ml','tableta','cápsula','gota','inhalación','aplicación')
 NOT NULL DEFAULT 'mg',
 frecuencia_horas DECIMAL(5,2) NOT NULL,
 hora_inicio TIME NOT NULL,
 fecha_inicio DATE NOT NULL,
 fecha_fin DATE DEFAULT NULL,
 instrucciones_medico TEXT DEFAULT NULL,
 instrucciones_paciente TEXT DEFAULT NULL,
 duplicado_aceptado BOOLEAN NOT NULL DEFAULT FALSE,
 -- configuración de horario avanzado
 tipo_horario ENUM('diario','dias_semana','dias_alternos','ciclo','referencia_comida')
 NOT NULL DEFAULT 'diario',
 dias_semana_bitmask INT DEFAULT NULL,
 intervalo_dias INT DEFAULT NULL,
 ciclo_dias_activo INT DEFAULT NULL,
 ciclo_dias_descanso INT DEFAULT NULL,
 referencia_comida ENUM('desayuno','comida','cena','ninguna') DEFAULT 'ninguna',
 minutos_referencia INT DEFAULT NULL,
 -- dosificación por peso
 dosis_mg_por_kg DECIMAL(8,3) DEFAULT NULL,
 dosis_calculada_automatica BOOLEAN NOT NULL DEFAULT FALSE,
 -- stock
 unidades_actuales INT DEFAULT NULL,
 unidades_por_toma INT NOT NULL DEFAULT 1,
 alerta_stock_dias INT NOT NULL DEFAULT 3,
 alerta_stock_enviada BOOLEAN NOT NULL DEFAULT FALSE,
 fecha_alerta_stock DATETIME DEFAULT NULL,
 activo BOOLEAN NOT NULL DEFAULT TRUE,
 fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP(),
 fecha_modificacion DATETIME DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_usuario_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_usuario_creador) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
 INDEX idx_paciente (id_usuario_paciente),
 INDEX idx_paciente_activo (id_usuario_paciente, activo),
 INDEX idx_nombre (nombre)
) ENGINE=InnoDB;

CREATE TABLE horarios_medicamento (
 id_horario INT PRIMARY KEY AUTO_INCREMENT,
 id_medicamento INT NOT NULL,
 hora_programada TIME NOT NULL,
 dia_semana TINYINT DEFAULT NULL,
 activo BOOLEAN NOT NULL DEFAULT TRUE,
 fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_medicamento) REFERENCES medicamentos(id_medicamento) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE registro_tomas (
 id_registro INT PRIMARY KEY AUTO_INCREMENT,
 id_medicamento INT NOT NULL,
 id_usuario INT NOT NULL,
 id_horario INT DEFAULT NULL,
 fecha_programada DATE NOT NULL,
 hora_programada TIME NOT NULL,
 fecha_real_toma DATETIME DEFAULT NULL,
 estado ENUM('pendiente','cumplida','no_cumplida','vencida')
 NOT NULL DEFAULT 'pendiente',
 diferencia_minutos INT DEFAULT NULL,
 motivo_omision VARCHAR(255) DEFAULT NULL,
 -- motivo de omisión estructurado (obligatorio al marcar no realizada)
 motivo_omision_codigo
 ENUM('olvido','efecto_adverso','medicamento_no_disponible',
 'indicacion_medica','otro') DEFAULT NULL,
 motivo_omision_descripcion VARCHAR(255) DEFAULT NULL,
 notas TEXT DEFAULT NULL,
 fecha_marcado DATETIME DEFAULT NULL,
 deshecho BOOLEAN NOT NULL DEFAULT FALSE,
 fecha_deshecho DATETIME DEFAULT NULL,
 -- enfermero que administró la toma en modo hospitalario
 id_enfermero_administra INT DEFAULT NULL,
 -- prescripción digital que originó esta toma (no editable por paciente)
 id_prescripcion_origen INT DEFAULT NULL,
 FOREIGN KEY (id_medicamento) REFERENCES medicamentos(id_medicamento) ON DELETE CASCADE,
 FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_horario) REFERENCES horarios_medicamento(id_horario) ON DELETE SET NULL,
 FOREIGN KEY (id_enfermero_administra) REFERENCES usuarios(id_usuario) ON DELETE SET NULL,
 INDEX idx_paciente_fecha (id_usuario, fecha_programada),
 INDEX idx_estado (estado),
 INDEX idx_motivo_codigo (motivo_omision_codigo)
) ENGINE=InnoDB;

CREATE TABLE historial_cambios_medicamento (
 id_cambio INT PRIMARY KEY AUTO_INCREMENT,
 id_medicamento INT NOT NULL,
 id_usuario_modificador INT NOT NULL,
 campo_modificado VARCHAR(50) NOT NULL,
 valor_anterior TEXT DEFAULT NULL,
 valor_nuevo TEXT DEFAULT NULL,
 tipo_accion ENUM('creacion','modificacion','eliminacion','restauracion') NOT NULL,
 fecha_cambio DATETIME DEFAULT CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_medicamento) REFERENCES medicamentos(id_medicamento) ON DELETE CASCADE,
 FOREIGN KEY (id_usuario_modificador) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
 INDEX idx_medicamento (id_medicamento),
 INDEX idx_fecha (fecha_cambio)
) ENGINE=InnoDB;

-- ─── alertas de interacciones detectadas ─────────────────────────
CREATE TABLE alertas_interaccion (
 id_alerta INT PRIMARY KEY AUTO_INCREMENT,
 id_paciente INT NOT NULL,
 id_medicamento_a INT NOT NULL,
 id_medicamento_b INT NOT NULL,
 id_interaccion INT NOT NULL,
 nivel_riesgo ENUM('leve','moderada','grave') NOT NULL,
 estado ENUM('activa','aceptada','obsoleta') NOT NULL DEFAULT 'activa',
 justificacion_clinica TEXT DEFAULT NULL,
 id_medico_acepta INT DEFAULT NULL,
 fecha_aceptacion DATETIME DEFAULT NULL,
 fecha_deteccion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_medicamento_a) REFERENCES medicamentos(id_medicamento) ON DELETE CASCADE,
 FOREIGN KEY (id_medicamento_b) REFERENCES medicamentos(id_medicamento) ON DELETE CASCADE,
 FOREIGN KEY (id_interaccion) REFERENCES catalogo_interacciones(id_interaccion) ON DELETE RESTRICT,
 FOREIGN KEY (id_medico_acepta) REFERENCES usuarios(id_usuario) ON DELETE SET NULL,
 INDEX idx_paciente (id_paciente),
 INDEX idx_estado (estado)
) ENGINE=InnoDB;

-- ─── + notificaciones generadas y enviadas ───────────────────
CREATE TABLE notificaciones (
 id_notificacion INT PRIMARY KEY AUTO_INCREMENT,
 id_usuario INT NOT NULL,
 id_registro_toma INT DEFAULT NULL,
 id_medicamento INT DEFAULT NULL,
 tipo_aviso ENUM('temprano','recordatorio','tardio','stock_bajo','interaccion',
 'cedula_aprobada','cedula_rechazada','generico',
 'familiar_bajo_70','nueva_prescripcion',
 'reaccion_alergica_urgente') NOT NULL,
 canal ENUM('app','correo','sms') NOT NULL,
 titulo VARCHAR(150) NOT NULL,
 mensaje TEXT NOT NULL,
 estado_envio ENUM('pendiente','enviada','fallida','cancelada') NOT NULL DEFAULT 'pendiente',
 leida BOOLEAN NOT NULL DEFAULT FALSE,
 fecha_programada DATETIME NOT NULL,
 fecha_envio DATETIME DEFAULT NULL,
 fecha_leida DATETIME DEFAULT NULL,
 error_envio TEXT DEFAULT NULL,
 proveedor_sid VARCHAR(64) DEFAULT NULL, -- SID de Twilio para SMS
 fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_registro_toma) REFERENCES registro_tomas(id_registro) ON DELETE CASCADE,
 FOREIGN KEY (id_medicamento) REFERENCES medicamentos(id_medicamento) ON DELETE SET NULL,
 INDEX idx_usuario_leida (id_usuario, leida),
 INDEX idx_estado_fecha (estado_envio, fecha_programada),
 INDEX idx_registro (id_registro_toma)
) ENGINE=InnoDB;

-- ─── preferencias de notificación POR MEDICAMENTO ──────────────────
CREATE TABLE preferencias_notificacion_medicamento (
 id_pref INT PRIMARY KEY AUTO_INCREMENT,
 id_medicamento INT NOT NULL,
 canal_app BOOLEAN NOT NULL DEFAULT TRUE,
 canal_correo BOOLEAN NOT NULL DEFAULT TRUE,
 canal_sms BOOLEAN NOT NULL DEFAULT FALSE,
 fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_medicamento) REFERENCES medicamentos(id_medicamento) ON DELETE CASCADE,
 UNIQUE KEY uniq_med (id_medicamento)
) ENGINE=InnoDB;

-- ════════════════════════════════════════════════════════════════════════════
-- SPRINT 3 · TABLAS NUEVAS
-- ════════════════════════════════════════════════════════════════════════════

-- ─── efectos adversos vinculados a la toma ──────────────────────────
CREATE TABLE efectos_adversos (
 id_efecto INT PRIMARY KEY AUTO_INCREMENT,
 id_registro_toma INT NOT NULL,
 id_medicamento INT NOT NULL,
 id_paciente INT NOT NULL,
 efecto ENUM('nausea','mareo','dolor_cabeza','somnolencia',
 'malestar_estomacal','reaccion_alergica','otro') NOT NULL,
 descripcion VARCHAR(255) DEFAULT NULL,
 es_reaccion_alergica BOOLEAN NOT NULL DEFAULT FALSE,
 aviso_urgente_enviado BOOLEAN NOT NULL DEFAULT FALSE,
 fecha_reporte DATETIME DEFAULT CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_registro_toma) REFERENCES registro_tomas(id_registro) ON DELETE CASCADE,
 FOREIGN KEY (id_medicamento) REFERENCES medicamentos(id_medicamento) ON DELETE CASCADE,
 FOREIGN KEY (id_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 INDEX idx_paciente (id_paciente),
 INDEX idx_toma (id_registro_toma),
 INDEX idx_alergia (es_reaccion_alergica)
) ENGINE=InnoDB;

-- ─── relación familiar-paciente con permisos ────────────────────────
CREATE TABLE familiar_paciente (
 id_relacion INT PRIMARY KEY AUTO_INCREMENT,
 id_familiar INT NOT NULL,
 id_paciente INT NOT NULL,
 parentesco VARCHAR(50) DEFAULT NULL,
 puede_ver_tomas BOOLEAN NOT NULL DEFAULT TRUE,
 puede_ver_cumplimiento BOOLEAN NOT NULL DEFAULT TRUE,
 alerta_70_activa BOOLEAN NOT NULL DEFAULT TRUE,
 invitado_por INT NOT NULL,
 token_invitacion VARCHAR(64) DEFAULT NULL,
 estado ENUM('pendiente','activa','revocada') NOT NULL DEFAULT 'pendiente',
 fecha_invitacion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 fecha_aceptacion DATETIME DEFAULT NULL,
 fecha_revocacion DATETIME DEFAULT NULL,
 ultimo_aviso_bajo_70 DATETIME DEFAULT NULL,
 FOREIGN KEY (id_familiar) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (invitado_por) REFERENCES usuarios(id_usuario) ON DELETE RESTRICT,
 UNIQUE KEY uniq_familiar_paciente (id_familiar, id_paciente),
 INDEX idx_familiar (id_familiar, estado),
 INDEX idx_paciente (id_paciente, estado),
 INDEX idx_token (token_invitacion)
) ENGINE=InnoDB;

-- ─── prescripciones digitales del médico ────────────────────────────
CREATE TABLE prescripciones_digitales (
 id_prescripcion INT PRIMARY KEY AUTO_INCREMENT,
 id_medico INT NOT NULL,
 id_paciente INT NOT NULL,
 id_medicamento_generado INT DEFAULT NULL,
 nombre_medicamento VARCHAR(120) NOT NULL,
 presentacion ENUM('Tableta','Tableta masticable','Cápsula','Jarabe',
 'Inyectable','Gotas','Crema','Inhalador','Otro')
 NOT NULL DEFAULT 'Tableta',
 cantidad_dosis DECIMAL(10,2) NOT NULL DEFAULT 1,
 unidad_dosis ENUM('mg','g','mcg','ml','tableta','cápsula','gota','inhalación','aplicación')
 NOT NULL DEFAULT 'mg',
 frecuencia_horas DECIMAL(5,2) NOT NULL,
 hora_inicio TIME NOT NULL DEFAULT '08:00:00',
 fecha_inicio DATE NOT NULL,
 duracion_dias INT DEFAULT NULL,
 fecha_fin DATE DEFAULT NULL,
 instrucciones TEXT DEFAULT NULL,
 estado ENUM('activa','modificada','cancelada') NOT NULL DEFAULT 'activa',
 fecha_emision DATETIME DEFAULT CURRENT_TIMESTAMP(),
 fecha_modificacion DATETIME DEFAULT NULL,
 fecha_cancelacion DATETIME DEFAULT NULL,
 FOREIGN KEY (id_medico) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_medicamento_generado) REFERENCES medicamentos(id_medicamento) ON DELETE SET NULL,
 INDEX idx_medico (id_medico),
 INDEX idx_paciente (id_paciente),
 INDEX idx_estado (estado)
) ENGINE=InnoDB;

-- ─── asignación de pacientes a turnos de enfermería ─────────────────
CREATE TABLE turno_enfermeria (
 id_turno INT PRIMARY KEY AUTO_INCREMENT,
 id_enfermero INT NOT NULL,
 id_paciente INT NOT NULL,
 nombre_turno VARCHAR(50) NOT NULL DEFAULT 'Turno general',
 asignado_por INT DEFAULT NULL,
 activo BOOLEAN NOT NULL DEFAULT TRUE,
 fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP(),
 FOREIGN KEY (id_enfermero) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 UNIQUE KEY uniq_enfermero_paciente (id_enfermero, id_paciente),
 INDEX idx_enfermero (id_enfermero, activo)
) ENGINE=InnoDB;

-- FK diferida de registro_tomas → prescripciones_digitales
ALTER TABLE registro_tomas
 ADD CONSTRAINT fk_rt_prescripcion
 FOREIGN KEY (id_prescripcion_origen)
 REFERENCES prescripciones_digitales(id_prescripcion) ON DELETE SET NULL;

-- ════════════════════════════════════════════════════════════════════════════
-- DATOS DE PRUEBA · contraseña de todos: MedAlert2025!
-- ════════════════════════════════════════════════════════════════════════════
INSERT INTO usuarios
 (id_usuario, nombre_completo, correo, telefono, contrasena_hash, id_tipo, estado_cuenta,
 confirmar_marcar_toma, peso_kg, fecha_nacimiento) VALUES
(1, 'Aaron Cardenas', 'aaron.medalert@gmail.com', '9991234567',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 4, 'activa', FALSE, NULL, NULL),
(2, 'Dra. Maria Lopez', 'dra.maria.medalert@gmail.com', '9992345678',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 3, 'activa', FALSE, NULL, NULL),
(3, 'Juan Perez Martinez', 'juan.perez.medalert@gmail.com','9993456789',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 1, 'activa', TRUE, 75.50, '1965-03-15'),
(4, 'Patricio Sanchez Diaz', 'patricio.medalert@gmail.com', '9994567890',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 2, 'activa', FALSE, NULL, NULL),
(5, 'Dr. Luis Hernandez', 'dr.luis.medalert@gmail.com', '9995678901',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 3, 'activa', FALSE, NULL, NULL),
(6, 'Ana Sanchez Ruiz', 'ana.sanchez.medalert@gmail.com','9996789012',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 1, 'activa', FALSE, 62.00, '1980-07-22');

UPDATE usuarios SET cedula_profesional='12345678', especialidad='Medicina Interna',
 cedula_validada=TRUE, fecha_validacion_cedula=NOW(), id_admin_validador=1
 WHERE id_usuario=2;
UPDATE usuarios SET cedula_profesional='87654321', especialidad='Cardiología',
 cedula_validada=FALSE
 WHERE id_usuario=5;

INSERT INTO preguntas_seguridad (id_usuario, id_pregunta, respuesta_hash, orden) VALUES
(3, 1, '$2y$10$F9ZYU958STA.Kn2MTdbnSuNvsxRXwSkm68uUyLsgWIbdv0iQ16ruG', 1),
(3, 2, '$2y$10$QJ6QBsMIp86Jyu2BqFRaheOSOMSHtiuPyEFYEhR4sw9pbW3/uP7m.', 2),
(3, 3, '$2y$10$HW3nZ3mzJeIxBTXmBPPjiOZSgYDmMOCHCpOTxWcGfV7HstgmZiCPi', 3);

-- relación oficial médico-paciente
INSERT INTO medico_paciente (id_medico, id_paciente, asignada_por, notas_clinicas) VALUES
(2, 3, 1, 'Paciente con DM tipo 2 e hipertensión controlada. Revisar adherencia mensualmente.'),
(2, 6, 1, NULL);

-- catálogo de interacciones (≥20 entradas)
-- Cliente solicitó incluir consecuencia clínica corta para que el paciente entienda sin tener que buscar.
INSERT INTO catalogo_interacciones (medicamento_a, medicamento_b, nivel_riesgo, descripcion, consecuencia_clinica, recomendacion, fuente) VALUES
('Warfarina', 'Aspirina', 'grave', 'Riesgo elevado de sangrado por efecto anticoagulante combinado.', 'Puede causar hemorragia interna grave.', 'Evitar la combinación; si es necesaria, monitorizar INR estrechamente.', 'Vademécum'),
('Warfarina', 'Ibuprofeno', 'grave', 'Aumenta el riesgo hemorrágico por inhibición plaquetaria.', 'Puede causar sangrado digestivo o cerebral.', 'Preferir paracetamol como analgésico.', 'Vademécum'),
('Warfarina', 'Amoxicilina', 'moderada', 'Puede potenciar el efecto anticoagulante.', 'Puede aumentar el riesgo de sangrado leve.', 'Vigilar INR durante el tratamiento antibiótico.', 'Vademécum'),
('Metformina', 'Furosemida', 'moderada', 'La furosemida puede elevar la glucemia y reducir el efecto de la metformina.', 'Puede descontrolar la glucosa en sangre.', 'Monitorizar niveles de glucosa.', 'Vademécum'),
('Metformina', 'Cimetidina', 'moderada', 'La cimetidina aumenta los niveles plasmáticos de metformina.', 'Puede causar hipoglucemia o malestar digestivo.', 'Considerar ajuste de dosis o alternativa.', 'Vademécum'),
('Losartán', 'Ibuprofeno', 'moderada', 'AINEs reducen el efecto antihipertensivo y empeoran función renal.','Puede subir la presión y dañar el riñón.', 'Limitar el uso prolongado de AINEs.', 'Vademécum'),
('Losartán', 'Espironolactona','grave', 'Riesgo de hiperpotasemia severa.', 'Puede causar arritmia cardíaca peligrosa.', 'Monitorizar potasio sérico al iniciar.', 'Vademécum'),
('Losartán', 'Litio', 'moderada', 'Puede aumentar las concentraciones de litio.', 'Puede causar temblor, confusión o intoxicación.', 'Monitorizar niveles de litio.', 'Vademécum'),
('Aspirina', 'Ibuprofeno', 'moderada', 'El ibuprofeno reduce el efecto antiagregante de la aspirina.', 'Puede anular la protección cardiovascular.', 'Tomar aspirina al menos 2h antes de ibuprofeno.', 'Vademécum'),
('Aspirina', 'Metotrexato', 'grave', 'Aumenta toxicidad del metotrexato por desplazamiento proteico.', 'Puede causar toxicidad medular grave.', 'Evitar combinación.', 'Vademécum'),
('Simvastatina', 'Claritromicina', 'grave', 'Riesgo de rabdomiólisis por inhibición del CYP3A4.', 'Puede causar daño muscular y renal severo.', 'Suspender estatina durante el antibiótico.', 'Vademécum'),
('Simvastatina', 'Eritromicina', 'grave', 'Riesgo de toxicidad muscular grave.', 'Puede causar dolor muscular intenso y daño renal.','Usar antibiótico alternativo.', 'Vademécum'),
('Atorvastatina', 'Claritromicina', 'moderada', 'Aumenta los niveles plasmáticos de atorvastatina.', 'Puede causar dolor muscular o daño hepático.', 'Considerar reducir la dosis.', 'Vademécum'),
('Digoxina', 'Furosemida', 'moderada', 'Hipopotasemia por diurético potencia toxicidad de digoxina.', 'Puede causar arritmias cardíacas.', 'Monitorizar electrolitos.', 'Vademécum'),
('Digoxina', 'Amiodarona', 'grave', 'Aumenta significativamente los niveles de digoxina.', 'Puede causar intoxicación cardíaca grave.', 'Reducir dosis de digoxina al 50%.', 'Vademécum'),
('Captopril', 'Espironolactona','grave', 'Riesgo elevado de hiperpotasemia.', 'Puede causar paro cardíaco por exceso de potasio.','Monitorizar potasio sérico.', 'Vademécum'),
('Levotiroxina', 'Omeprazol', 'leve', 'El omeprazol puede reducir la absorción de levotiroxina.', 'Puede reducir el efecto del tratamiento tiroideo.','Separar tomas al menos 4 horas.', 'Vademécum'),
('Levotiroxina', 'Calcio', 'moderada', 'El calcio reduce la absorción de levotiroxina.', 'Puede causar hipotiroidismo persistente.', 'Tomar con 4 horas de diferencia.', 'Vademécum'),
('Tramadol', 'Sertralina', 'moderada', 'Riesgo de síndrome serotoninérgico.', 'Puede causar agitación, fiebre y temblores.', 'Vigilar síntomas neurológicos.', 'Vademécum'),
('Tramadol', 'Fluoxetina', 'moderada', 'Riesgo de síndrome serotoninérgico.', 'Puede causar confusión, fiebre y rigidez muscular.','Considerar opioide alternativo.', 'Vademécum'),
('Paracetamol', 'Warfarina', 'moderada', 'Uso prolongado puede potenciar el efecto anticoagulante.', 'Puede aumentar el riesgo de sangrado.', 'Limitar dosis a menos de 2g/día.', 'Vademécum'),
('Insulina', 'Propranolol', 'moderada', 'Puede enmascarar síntomas de hipoglucemia.', 'Puede ocultar señales de azúcar baja.', 'Educar al paciente en signos atípicos.', 'Vademécum');

-- tabla de dosis máximas
INSERT INTO dosis_maximas (nombre_medicamento, grupo_edad, edad_min_anios, edad_max_anios, dosis_max_mg_kg_dia, dosis_max_total_dia_mg, fuente) VALUES
('Paracetamol', 'lactante', 0, 1, 60, 500, 'AAP'),
('Paracetamol', 'pediatrico', 2, 11, 75, 3000, 'AAP'),
('Paracetamol', 'adolescente', 12, 17, 60, 4000, 'AAP'),
('Paracetamol', 'adulto', 18, 64, 50, 4000, 'FDA'),
('Paracetamol', 'adulto_mayor', 65, 120, 40, 3000, 'FDA'),
('Ibuprofeno', 'pediatrico', 2, 11, 40, 1200, 'AAP'),
('Ibuprofeno', 'adolescente', 12, 17, 40, 2400, 'AAP'),
('Ibuprofeno', 'adulto', 18, 64, 40, 3200, 'FDA'),
('Ibuprofeno', 'adulto_mayor', 65, 120, 30, 1200, 'FDA'),
('Metformina', 'adulto', 18, 64, 35, 2550, 'FDA'),
('Metformina', 'adulto_mayor', 65, 120, 25, 2000, 'FDA'),
('Amoxicilina', 'pediatrico', 2, 11, 90, 3000, 'AAP'),
('Amoxicilina', 'adulto', 18, 120, 50, 3000, 'FDA'),
('Losartán', 'adulto', 18, 120, 1.4, 100, 'FDA');

-- Medicamentos demo de Juan
INSERT INTO medicamentos
 (id_medicamento, id_usuario_paciente, id_usuario_creador, nombre, presentacion,
 cantidad_dosis, unidad_dosis, frecuencia_horas, hora_inicio, fecha_inicio,
 instrucciones_medico, instrucciones_paciente,
 tipo_horario, unidades_actuales, unidades_por_toma, alerta_stock_dias) VALUES
(1, 3, 2, 'Metformina', 'Tableta', 500, 'mg', 8.0, '07:00:00', CURDATE(),
 'Tomar con alimentos para evitar molestias estomacales', NULL, 'diario', 30, 1, 3),
(2, 3, 2, 'Losartán', 'Tableta', 50, 'mg', 24.0, '08:00:00', CURDATE(),
 'Por la mañana, antes del desayuno', NULL, 'diario', 8, 1, 3),
(3, 3, 3, 'Vitamina C', 'Tableta masticable',1000, 'mg', 24.0, '09:00:00', CURDATE(),
 NULL, 'Suplemento diario para reforzar defensas', 'diario', 60, 1, 5);

INSERT INTO horarios_medicamento (id_horario, id_medicamento, hora_programada) VALUES
(1, 1, '07:00:00'), (2, 1, '15:00:00'), (3, 1, '23:00:00'),
(4, 2, '08:00:00'),
(5, 3, '09:00:00');

INSERT INTO registro_tomas
 (id_medicamento, id_usuario, id_horario, fecha_programada, hora_programada,
 fecha_real_toma, estado, diferencia_minutos, fecha_marcado) VALUES
(1, 3, 1, CURDATE(), '07:00:00',
 CONCAT(CURDATE(), ' 07:05:00'), 'cumplida', 5, NOW() - INTERVAL 6 HOUR),
(2, 3, 4, CURDATE(), '08:00:00',
 CONCAT(CURDATE(), ' 08:12:00'), 'cumplida', 12, NOW() - INTERVAL 5 HOUR),
(3, 3, 5, CURDATE(), '09:00:00', NULL, 'vencida', NULL, NULL),
(1, 3, 2, CURDATE(), '15:00:00', NULL, 'pendiente', NULL, NULL),
(1, 3, 3, CURDATE(), '23:00:00', NULL, 'pendiente', NULL, NULL);

INSERT INTO preferencias_notificacion_medicamento (id_medicamento, canal_app, canal_correo, canal_sms) VALUES
(1, TRUE, TRUE, FALSE),
(2, TRUE, TRUE, FALSE),
(3, TRUE, FALSE, FALSE);

INSERT INTO cuidador_paciente (id_cuidador, id_paciente, parentesco, asignada_por) VALUES
(4, 3, 'Hermano', 1);

-- ════════════════════════════════════════════════════════════════════════════
-- SPRINT 3 · DATOS DE PRUEBA PARA EL DEMO
-- contraseña de todos: MedAlert2025!
-- ════════════════════════════════════════════════════════════════════════════
-- id_tipo: 1=Paciente 2=Cuidador 3=Medico 4=Administrador 5=Enfermero

INSERT INTO usuarios
 (id_usuario, nombre_completo, correo, telefono, contrasena_hash, id_tipo, estado_cuenta,
 confirmar_marcar_toma, peso_kg, fecha_nacimiento) VALUES
-- Familiar de prueba — vinculado al paciente con bajo cumplimiento
(7, 'Rosa Martinez Vda', 'rosa.familiar.medalert@gmail.com', '9997001122',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 2, 'activa', FALSE, NULL, NULL),
-- Enfermero de prueba
(8, 'Enf. Carlos Tun Pech', 'carlos.enfermero.medalert@gmail.com','9997002233',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 5, 'activa', FALSE, NULL, NULL),
-- Paciente escenario 1: cumplimiento BAJO (<70%) con omisiones por motivo
(9, 'Pedro Canul Balam', 'pedro.paciente.medalert@gmail.com', '9997003344',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 1, 'activa', FALSE, 70.00, '1958-09-10'),
-- Paciente escenario 2: MEJORÍA entre semanas
(10, 'Lucia Poot Chan', 'lucia.paciente.medalert@gmail.com', '9997004455',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 1, 'activa', FALSE, 64.00, '1972-02-18'),
-- Pacientes extra para que el panel multi-paciente y modo hospitalario tengan datos
(11, 'Jose Uc Dzib', 'jose.paciente.medalert@gmail.com', '9997005566',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 1, 'activa', FALSE, 80.00, '1949-11-30'),
(12, 'Maria Couoh Ek', 'maria.paciente.medalert@gmail.com', '9997006677',
 '$2y$10$nb1Nnz88kO.4YSl2zTN3vu2Mwal7J.a91LkWx1xLVVazwuIIy9RVW', 1, 'activa', FALSE, 58.00, '1985-06-05');

-- Médico 2 (Dra. Maria Lopez, id 2) atiende a los pacientes del demo
INSERT INTO medico_paciente (id_medico, id_paciente, asignada_por, notas_clinicas, notas_clinicas_fecha) VALUES
(2, 9, 1, 'Hipertensión y diabetes. Vigilar adherencia, ha tenido efectos adversos reportados.', NOW() - INTERVAL 2 DAY),
(2, 10, 1, 'Tratamiento ajustado la semana pasada. Evaluar si la adherencia mejoró tras el ajuste.', NOW() - INTERVAL 8 DAY),
(2, 11, 1, NULL, NULL),
(2, 12, 1, 'Paciente nueva, sin tratamiento iniciado aún.', NOW() - INTERVAL 1 DAY);

-- Familiar vinculado al paciente con bajo cumplimiento (relación ACTIVA)
INSERT INTO familiar_paciente
 (id_familiar, id_paciente, parentesco, invitado_por, estado, fecha_aceptacion) VALUES
(7, 9, 'Esposa', 9, 'activa', NOW() - INTERVAL 5 DAY);

-- Turno de enfermería: el enfermero atiende 6 pacientes
INSERT INTO turno_enfermeria (id_enfermero, id_paciente, nombre_turno, asignado_por) VALUES
(8, 3, 'Turno matutino', 1),
(8, 6, 'Turno matutino', 1),
(8, 9, 'Turno matutino', 1),
(8, 10, 'Turno matutino', 1),
(8, 11, 'Turno matutino', 1),
(8, 12, 'Turno matutino', 1);

-- ── Medicamentos de los pacientes del demo ──────────────────────────────────
INSERT INTO medicamentos
 (id_medicamento, id_usuario_paciente, id_usuario_creador, nombre, presentacion,
 cantidad_dosis, unidad_dosis, frecuencia_horas, hora_inicio, fecha_inicio,
 instrucciones_medico, tipo_horario, unidades_actuales, unidades_por_toma, alerta_stock_dias) VALUES
-- Pedro (id 9): 2 medicamentos
(10, 9, 2, 'Enalapril', 'Tableta', 10, 'mg', 12.0, '08:00:00', CURDATE() - INTERVAL 30 DAY,
 'Tomar mañana y noche, controlar presión', 'diario', 40, 1, 3),
(11, 9, 2, 'Metformina', 'Tableta', 850, 'mg', 12.0, '09:00:00', CURDATE() - INTERVAL 30 DAY,
 'Con alimentos, mañana y noche', 'diario', 35, 1, 3),
-- Lucia (id 10): 2 medicamentos
(12, 10, 2, 'Levotiroxina', 'Tableta', 50, 'mcg', 24.0, '07:00:00', CURDATE() - INTERVAL 21 DAY,
 'En ayunas, 30 min antes del desayuno', 'diario', 25, 1, 3),
(13, 10, 2, 'Atorvastatina','Tableta', 20, 'mg', 24.0, '21:00:00', CURDATE() - INTERVAL 21 DAY,
 'Por la noche', 'diario', 20, 1, 3),
-- Jose (id 11): 1 medicamento (para panel/hospitalario)
(14, 11, 2, 'Losartán', 'Tableta', 50, 'mg', 24.0, '08:00:00', CURDATE() - INTERVAL 10 DAY,
 'Antes del desayuno', 'diario', 15, 1, 3),
-- Maria (id 12): medicamento recién creado, sin tomas aún (caso "sin datos")
(15, 12, 2, 'Omeprazol', 'Cápsula', 20, 'mg', 24.0, '07:00:00', CURDATE(),
 'En ayunas', 'diario', 28, 1, 3);

INSERT INTO horarios_medicamento (id_medicamento, hora_programada) VALUES
(10, '08:00:00'), (10, '20:00:00'),
(11, '09:00:00'), (11, '21:00:00'),
(12, '07:00:00'),
(13, '21:00:00'),
(14, '08:00:00'),
(15, '07:00:00');

-- ════════════════════════════════════════════════════════════════════════════
-- DATOS DE PRUEBA PARA EL DEMO — anclados a la SEMANA ISO (lunes a domingo)
-- ════════════════════════════════════════════════════════════════════════════
-- Los escenarios se generan SIEMPRE dentro de la semana ISO en curso (y la
-- anterior para el comparativo), sin importar el día en que se cargue la BD.
-- Así la demo es reproducible cualquier día: lunes o domingo, los datos de
-- Pedro caen en "esta semana" y los de Lucía muestran la mejoría real.
--
-- @lun0 = lunes de la semana ISO actual
-- @lun1 = lunes de la semana ISO anterior
SET @lun0 := DATE_SUB(CURDATE(), INTERVAL (WEEKDAY(CURDATE())) DAY);
SET @lun1 := DATE_SUB(@lun0, INTERVAL 7 DAY);
-- Día tope: hoy si ya pasó el lunes; nunca generamos tomas en el futuro.
SET @hoy := CURDATE();

DROP PROCEDURE IF EXISTS sp_seed_demo;
DELIMITER //
CREATE PROCEDURE sp_seed_demo()
BEGIN
 DECLARE d INT;

 -- ── ESCENARIO 1 · Pedro (id 9): cumplimiento < 70% con motivos ──────────
 -- 2 medicamentos (10 Enalapril, 11 Metformina) × 2 horarios = 4 tomas/día.
 -- Patrón objetivo ≈ 58%: por cada día generamos 2 cumplidas y 2 omitidas
 -- (la mayoría con motivo; una sin motivo para "sin justificar").
 SET d = 0;
 WHILE DATE_ADD(@lun0, INTERVAL d DAY) <= @hoy AND d < 7 DO
 SET @f := DATE_ADD(@lun0, INTERVAL d DAY);
 -- Enalapril 08:00 — cumplida
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (10,9,@f,'08:00:00',CONCAT(@f,' 08:05:00'),'cumplida',5,CONCAT(@f,' 08:05:00'));
 -- Enalapril 20:00 — omitida (efecto adverso, justificada)
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,
 motivo_omision,motivo_omision_codigo,motivo_omision_descripcion,fecha_marcado)
 VALUES (10,9,@f,'20:00:00','no_cumplida','efecto_adverso','efecto_adverso',
 'Malestar estomacal tras la toma',CONCAT(@f,' 20:30:00'));
 -- Metformina 09:00 — cumplida
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (11,9,@f,'09:00:00',CONCAT(@f,' 09:10:00'),'cumplida',10,CONCAT(@f,' 09:10:00'));
 -- Metformina 21:00 — omitida; el primer día SIN motivo (sin justificar),
 -- el resto con motivo (olvido / medicamento no disponible / indicación).
 IF d = 0 THEN
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,fecha_marcado)
 VALUES (11,9,@f,'21:00:00','no_cumplida',CONCAT(@f,' 23:00:00'));
 ELSEIF d % 3 = 1 THEN
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,
 motivo_omision,motivo_omision_codigo,fecha_marcado)
 VALUES (11,9,@f,'21:00:00','no_cumplida','olvido','olvido',CONCAT(@f,' 23:30:00'));
 ELSEIF d % 3 = 2 THEN
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,
 motivo_omision,motivo_omision_codigo,fecha_marcado)
 VALUES (11,9,@f,'21:00:00','no_cumplida','medicamento_no_disponible',
 'medicamento_no_disponible',CONCAT(@f,' 22:00:00'));
 ELSE
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,
 motivo_omision,motivo_omision_codigo,motivo_omision_descripcion,fecha_marcado)
 VALUES (11,9,@f,'21:00:00','no_cumplida','indicacion_medica','indicacion_medica',
 'Suspendido por el medico ese dia',CONCAT(@f,' 21:10:00'));
 END IF;
 SET d = d + 1;
 END WHILE;

 -- Semana ANTERIOR de Pedro (completa) para que la tendencia y el
 -- comparativo siempre tengan al menos dos puntos. Adherencia ~63%.
 SET d = 0;
 WHILE d < 7 DO
 SET @fp := DATE_ADD(@lun1, INTERVAL d DAY);
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (10,9,@fp,'08:00:00',CONCAT(@fp,' 08:05:00'),'cumplida',5,CONCAT(@fp,' 08:05:00'));
 IF d % 2 = 0 THEN
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (10,9,@fp,'20:00:00',CONCAT(@fp,' 20:05:00'),'cumplida',5,CONCAT(@fp,' 20:05:00'));
 ELSE
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,
 motivo_omision,motivo_omision_codigo,fecha_marcado)
 VALUES (10,9,@fp,'20:00:00','no_cumplida','olvido','olvido',CONCAT(@fp,' 22:00:00'));
 END IF;
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (11,9,@fp,'09:00:00',CONCAT(@fp,' 09:05:00'),'cumplida',5,CONCAT(@fp,' 09:05:00'));
 IF d % 3 = 0 THEN
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,
 motivo_omision,motivo_omision_codigo,motivo_omision_descripcion,fecha_marcado)
 VALUES (11,9,@fp,'21:00:00','no_cumplida','efecto_adverso','efecto_adverso',
 'Malestar leve',CONCAT(@fp,' 21:30:00'));
 ELSE
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (11,9,@fp,'21:00:00',CONCAT(@fp,' 21:05:00'),'cumplida',5,CONCAT(@fp,' 21:05:00'));
 END IF;
 SET d = d + 1;
 END WHILE;

 -- ── ESCENARIO 2 · Lucia (id 10): MEJORÍA semana anterior → actual ───────
 -- 2 medicamentos (12 Levotiroxina 07:00, 13 Atorvastatina 21:00).
 -- Semana ANTERIOR: adherencia baja (~43%). Semana ACTUAL: alta (~92%).
 SET d = 0;
 WHILE d < 7 DO
 SET @fa := DATE_ADD(@lun1, INTERVAL d DAY); -- semana anterior (completa)
 -- Levotiroxina: cumple sólo ~3 de 7 días
 IF d IN (0,3,6) THEN
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (12,10,@fa,'07:00:00',CONCAT(@fa,' 07:05:00'),'cumplida',5,CONCAT(@fa,' 07:05:00'));
 ELSE
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,
 motivo_omision,motivo_omision_codigo,fecha_marcado)
 VALUES (12,10,@fa,'07:00:00','no_cumplida','olvido','olvido',CONCAT(@fa,' 10:00:00'));
 END IF;
 -- Atorvastatina: cumple sólo ~2 de 7 días
 IF d IN (2,5) THEN
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (13,10,@fa,'21:00:00',CONCAT(@fa,' 21:05:00'),'cumplida',5,CONCAT(@fa,' 21:05:00'));
 ELSE
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,
 motivo_omision,motivo_omision_codigo,fecha_marcado)
 VALUES (13,10,@fa,'21:00:00','no_cumplida','olvido','olvido',CONCAT(@fa,' 23:00:00'));
 END IF;
 SET d = d + 1;
 END WHILE;

 SET d = 0;
 WHILE DATE_ADD(@lun0, INTERVAL d DAY) <= @hoy AND d < 7 DO
 SET @fc := DATE_ADD(@lun0, INTERVAL d DAY); -- semana actual (hasta hoy)
 -- Levotiroxina: cumple casi siempre
 IF d = 4 THEN
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,
 motivo_omision,motivo_omision_codigo,fecha_marcado)
 VALUES (12,10,@fc,'07:00:00','no_cumplida','olvido','olvido',CONCAT(@fc,' 10:00:00'));
 ELSE
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (12,10,@fc,'07:00:00',CONCAT(@fc,' 07:00:00'),'cumplida',0,CONCAT(@fc,' 07:00:00'));
 END IF;
 -- Atorvastatina: cumple siempre
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (13,10,@fc,'21:00:00',CONCAT(@fc,' 21:02:00'),'cumplida',2,CONCAT(@fc,' 21:02:00'));
 SET d = d + 1;
 END WHILE;

 -- ── Jose (id 11): cumplimiento medio en el panel ────────────────────────
 SET d = 0;
 WHILE DATE_ADD(@lun0, INTERVAL d DAY) <= @hoy AND d < 7 DO
 SET @fj := DATE_ADD(@lun0, INTERVAL d DAY);
 IF d % 3 = 1 THEN
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,estado,fecha_marcado)
 VALUES (14,11,@fj,'08:00:00','no_cumplida',CONCAT(@fj,' 12:00:00'));
 ELSE
 INSERT INTO registro_tomas
 (id_medicamento,id_usuario,fecha_programada,hora_programada,fecha_real_toma,
 estado,diferencia_minutos,fecha_marcado)
 VALUES (14,11,@fj,'08:00:00',CONCAT(@fj,' 08:00:00'),'cumplida',0,CONCAT(@fj,' 08:00:00'));
 END IF;
 SET d = d + 1;
 END WHILE;
END //
DELIMITER ;
CALL sp_seed_demo();
DROP PROCEDURE sp_seed_demo;
-- La omisión sin código de la primera Metformina de Pedro y la falta de
-- código en algunas tomas representan "omisión sin justificar".


-- Tomas de HOY pendientes para el modo hospitalario (enfermero registra masivo)
INSERT INTO registro_tomas
 (id_medicamento, id_usuario, fecha_programada, hora_programada, estado)
VALUES
(10, 9, CURDATE(), '08:00:00', 'pendiente'),
(11, 9, CURDATE(), '09:00:00', 'pendiente'),
(12, 10, CURDATE(), '07:00:00', 'pendiente'),
(13, 10, CURDATE(), '21:00:00', 'pendiente'),
(14, 11, CURDATE(), '08:00:00', 'pendiente'),
(15, 12, CURDATE(), '07:00:00', 'pendiente'),
(1, 3, CURDATE(), '07:00:00', 'pendiente'),
(3, 3, CURDATE(), '09:00:00', 'pendiente');

-- Efecto adverso histórico de Pedro (reacción alérgica → aviso urgente ya enviado)
INSERT INTO efectos_adversos
 (id_registro_toma, id_medicamento, id_paciente, efecto, descripcion,
 es_reaccion_alergica, aviso_urgente_enviado, fecha_reporte)
SELECT rt.id_registro, 10, 9, 'reaccion_alergica', 'Ronchas y comezón en brazos',
 TRUE, TRUE, CONCAT(CURDATE()-INTERVAL 3 DAY,' 20:30:00')
FROM registro_tomas rt
WHERE rt.id_usuario = 9 AND rt.id_medicamento = 10
 AND rt.fecha_programada = CURDATE()-INTERVAL 3 DAY AND rt.hora_programada = '20:00:00'
LIMIT 1;

INSERT INTO efectos_adversos
 (id_registro_toma, id_medicamento, id_paciente, efecto, descripcion,
 es_reaccion_alergica, fecha_reporte)
SELECT rt.id_registro, 11, 9, 'nausea', 'Náuseas moderadas después de comer',
 FALSE, CONCAT(CURDATE()-INTERVAL 2 DAY,' 09:40:00')
FROM registro_tomas rt
WHERE rt.id_usuario = 9 AND rt.id_medicamento = 11
 AND rt.fecha_programada = CURDATE()-INTERVAL 2 DAY AND rt.hora_programada = '09:00:00'
LIMIT 1;

-- Prescripción digital de ejemplo (activa) emitida por la Dra. Maria a Pedro
INSERT INTO prescripciones_digitales
 (id_medico, id_paciente, nombre_medicamento, presentacion, cantidad_dosis,
 unidad_dosis, frecuencia_horas, hora_inicio, fecha_inicio, duracion_dias,
 fecha_fin, instrucciones, estado)
VALUES
(2, 9, 'Aspirina', 'Tableta', 100, 'mg', 24.0, '08:00:00',
 CURDATE(), 30, CURDATE()+INTERVAL 30 DAY,
 'Tomar después del desayuno. Acudir a control en 15 días.', 'activa');

-- Notificaciones recientes para que el sistema se vea "vivo" al explorarlo
INSERT INTO notificaciones
 (id_usuario, tipo_aviso, canal, titulo, mensaje, estado_envio, leida,
 fecha_programada, fecha_envio)
VALUES
(9, 'recordatorio','app','🔔 Tu medicamento es en 5 minutos',
 'Medicamento: Enalapril\nDosis: 10 mg\nHora programada: 8:00 AM','enviada',FALSE,
 NOW()-INTERVAL 2 HOUR, NOW()-INTERVAL 2 HOUR),
(9, 'tardio','app','⚠️ Aún no registras tu medicamento',
 'Medicamento: Metformina\nDosis: 850 mg\nHora programada: 9:00 AM','enviada',FALSE,
 NOW()-INTERVAL 1 HOUR, NOW()-INTERVAL 1 HOUR),
(7, 'familiar_bajo_70','app','👥 Alerta: cumplimiento bajo de Pedro Canul Balam',
 'El cumplimiento semanal de Pedro Canul Balam bajó a 60%. Te sugerimos contactarlo para apoyarlo con su tratamiento.','enviada',FALSE,
 NOW()-INTERVAL 3 HOUR, NOW()-INTERVAL 3 HOUR),
(2, 'reaccion_alergica_urgente','app','🚨 URGENTE: Reacción alérgica de Pedro Canul Balam',
 'El paciente Pedro Canul Balam reportó una REACCIÓN ALÉRGICA tras tomar Enalapril. Descripción: Ronchas y comezón en brazos. Requiere atención inmediata.','enviada',FALSE,
 NOW()-INTERVAL 30 MINUTE, NOW()-INTERVAL 30 MINUTE),
(9, 'nueva_prescripcion','app','💊 Tu médico te emitió una nueva prescripción',
 'La Dra. Maria Lopez te emitió una nueva prescripción: Aspirina 100 mg cada 24 h durante 30 días. Ya aparece en tus medicamentos con recordatorios configurados.','enviada',FALSE,
 NOW()-INTERVAL 20 MINUTE, NOW()-INTERVAL 20 MINUTE);

-- ════════════════════════════════════════════════════════════════════════════
-- VISTAS
-- ════════════════════════════════════════════════════════════════════════════
CREATE OR REPLACE VIEW v_tomas_dia AS
SELECT
 rt.id_registro, rt.id_medicamento, rt.id_usuario,
 m.nombre AS medicamento_nombre, m.presentacion,
 CONCAT(
 CASE WHEN m.cantidad_dosis = FLOOR(m.cantidad_dosis)
 THEN CAST(m.cantidad_dosis AS UNSIGNED)
 ELSE TRIM(TRAILING '0' FROM TRIM(TRAILING '.' FROM CAST(m.cantidad_dosis AS CHAR)))
 END,
 ' ', m.unidad_dosis
 ) AS dosis,
 m.cantidad_dosis, m.unidad_dosis,
 COALESCE(m.instrucciones_medico, m.instrucciones_paciente) AS instruccion_principal,
 m.instrucciones_medico, m.instrucciones_paciente,
 CASE WHEN m.instrucciones_medico IS NOT NULL AND m.instrucciones_medico <> ''
 THEN 'medico' ELSE 'paciente' END AS origen_instruccion,
 rt.fecha_programada, rt.hora_programada, rt.fecha_real_toma,
 rt.estado, rt.diferencia_minutos, rt.motivo_omision,
 rt.fecha_marcado, rt.deshecho,
 CASE
 WHEN rt.fecha_marcado IS NULL THEN FALSE
 WHEN rt.deshecho = TRUE THEN FALSE
 WHEN TIMESTAMPDIFF(MINUTE, rt.fecha_marcado, NOW()) <= 30 THEN TRUE
 ELSE FALSE
 END AS puede_deshacer
FROM registro_tomas rt
INNER JOIN medicamentos m ON m.id_medicamento = rt.id_medicamento
WHERE m.activo = TRUE;

CREATE OR REPLACE VIEW v_resumen_dia AS
SELECT
 id_usuario, fecha_programada,
 COUNT(*) AS total,
 SUM(CASE WHEN estado='cumplida' THEN 1 ELSE 0 END) AS cumplidas,
 SUM(CASE WHEN estado='vencida' THEN 1 ELSE 0 END) AS vencidas,
 SUM(CASE WHEN estado='pendiente' THEN 1 ELSE 0 END) AS pendientes,
 SUM(CASE WHEN estado='no_cumplida' THEN 1 ELSE 0 END) AS no_cumplidas,
 ROUND(SUM(CASE WHEN estado='cumplida' THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*),0), 1)
 AS porcentaje_cumplimiento
FROM registro_tomas
GROUP BY id_usuario, fecha_programada;

CREATE OR REPLACE VIEW v_stock_medicamentos AS
SELECT
 m.id_medicamento,
 m.id_usuario_paciente,
 m.nombre,
 m.unidades_actuales,
 m.unidades_por_toma,
 m.frecuencia_horas,
 m.alerta_stock_dias,
 m.alerta_stock_enviada,
 CASE WHEN m.frecuencia_horas > 0 AND m.unidades_por_toma > 0
 THEN ROUND((24 / m.frecuencia_horas) * m.unidades_por_toma, 2)
 ELSE NULL END AS unidades_por_dia,
 CASE WHEN m.unidades_actuales IS NULL OR m.frecuencia_horas <= 0 OR m.unidades_por_toma <= 0
 THEN NULL
 ELSE FLOOR(m.unidades_actuales / ((24 / m.frecuencia_horas) * m.unidades_por_toma))
 END AS dias_restantes,
 CASE
 WHEN m.unidades_actuales IS NULL THEN 'sin_seguimiento'
 WHEN m.unidades_actuales = 0 THEN 'agotado'
 WHEN FLOOR(m.unidades_actuales / NULLIF((24 / NULLIF(m.frecuencia_horas,0)) * m.unidades_por_toma, 0)) <= m.alerta_stock_dias THEN 'bajo'
 ELSE 'ok'
 END AS estado_stock
FROM medicamentos m
WHERE m.activo = TRUE;

CREATE OR REPLACE VIEW v_alertas_interaccion_activas AS
SELECT
 a.id_alerta, a.id_paciente, a.estado, a.nivel_riesgo,
 a.fecha_deteccion,
 a.justificacion_clinica AS justificacion_medico,
 a.fecha_aceptacion,
 ma.id_medicamento AS id_med_a, ma.nombre AS medicamento_a,
 mb.id_medicamento AS id_med_b, mb.nombre AS medicamento_b,
 ci.descripcion, ci.consecuencia_clinica, ci.recomendacion, ci.fuente,
 p.nombre_completo AS paciente_nombre,
 me.nombre_completo AS medico_nombre
FROM alertas_interaccion a
INNER JOIN medicamentos ma ON ma.id_medicamento = a.id_medicamento_a
INNER JOIN medicamentos mb ON mb.id_medicamento = a.id_medicamento_b
INNER JOIN catalogo_interacciones ci ON ci.id_interaccion = a.id_interaccion
INNER JOIN usuarios p ON p.id_usuario = a.id_paciente
LEFT JOIN usuarios me ON me.id_usuario = a.id_medico_acepta
WHERE a.estado IN ('activa','aceptada');

-- ─── SPRINT 3 · Vista de tomas clasificadas para reportes (/21/23) ────
CREATE OR REPLACE VIEW v_tomas_clasificadas AS
SELECT
 rt.id_registro,
 rt.id_usuario AS id_paciente,
 rt.id_medicamento,
 m.nombre AS medicamento_nombre,
 rt.fecha_programada,
 rt.hora_programada,
 rt.estado,
 rt.motivo_omision_codigo,
 rt.motivo_omision_descripcion,
 CASE
 WHEN rt.estado = 'cumplida' THEN 'cumplida'
 WHEN rt.estado = 'no_cumplida' AND rt.motivo_omision_codigo IS NOT NULL
 THEN 'omitida_justificada'
 WHEN rt.estado IN ('no_cumplida','vencida')
 THEN 'omitida_sin_justificar'
 ELSE 'pendiente'
 END AS clasificacion
FROM registro_tomas rt
INNER JOIN medicamentos m ON m.id_medicamento = rt.id_medicamento
WHERE m.activo = TRUE;

SELECT 'INSTALACIÓN COMPLETA (MedAlert)' AS estado;
