-- ════════════════════════════════════════════════════════════════════════════
-- MedAlert · MIGRACIÓN SPRINT 3
-- Reportes · Vista familiar · Panel multi-paciente · Prescripciones digitales
-- Motivo de omisión · Efectos adversos · Modo hospitalario · Comparativo
-- ════════════════════════════════════════════════════════════════════════════
--
-- Ejecutar SOBRE una base que ya tiene el esquema del instalado.
-- Si instalas desde cero, usa database/medalert_schema.sql (ya lo incluye todo).
--
-- Es idempotente en lo posible: usa IF NOT EXISTS donde MySQL lo permite.
-- ────────────────────────────────────────────────────────────────────────────

-- ─── 1 · Nuevo rol: Enfermero ───────────────────────────────────────
INSERT INTO tipos_usuario (nombre, descripcion, permisos, permite_registro_publico)
SELECT 'Enfermero',
 'Personal de enfermería que administra tomas en entorno hospitalario',
 '{"tomas":["registrar_masivo_turno"],"modo_hospitalario":["acceder"]}',
 FALSE
WHERE NOT EXISTS (SELECT 1 FROM tipos_usuario WHERE nombre = 'Enfermero');

-- ─── 2 · Motivo de omisión estructurado en registro_tomas ───────────
-- El ya tenía motivo_omision (texto). Añadimos código estructurado
-- y descripción libre para poder filtrar y distinguir en reportes.
ALTER TABLE registro_tomas
 ADD COLUMN motivo_omision_codigo
 ENUM('olvido','efecto_adverso','medicamento_no_disponible',
 'indicacion_medica','otro') DEFAULT NULL AFTER motivo_omision,
 ADD COLUMN motivo_omision_descripcion VARCHAR(255) DEFAULT NULL
 AFTER motivo_omision_codigo,
 ADD COLUMN id_enfermero_administra INT DEFAULT NULL AFTER deshecho,
 ADD COLUMN id_prescripcion_origen INT DEFAULT NULL AFTER id_enfermero_administra,
 ADD INDEX idx_motivo_codigo (motivo_omision_codigo);

ALTER TABLE registro_tomas
 ADD CONSTRAINT fk_rt_enfermero
 FOREIGN KEY (id_enfermero_administra)
 REFERENCES usuarios(id_usuario) ON DELETE SET NULL;

-- ─── 3 · Efectos adversos vinculados a la toma ──────────────────────
CREATE TABLE IF NOT EXISTS efectos_adversos (
 id_efecto INT PRIMARY KEY AUTO_INCREMENT,
 id_registro_toma INT NOT NULL,
 id_medicamento INT NOT NULL,
 id_paciente INT NOT NULL,
 efecto ENUM('nausea','mareo','dolor_cabeza','somnolencia',
 'malestar_estomacal','reaccion_alergica','otro') NOT NULL,
 descripcion VARCHAR(255) DEFAULT NULL,
 es_reaccion_alergica BOOLEAN NOT NULL DEFAULT FALSE,
 aviso_urgente_enviado BOOLEAN NOT NULL DEFAULT FALSE,
 fecha_reporte DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (id_registro_toma) REFERENCES registro_tomas(id_registro) ON DELETE CASCADE,
 FOREIGN KEY (id_medicamento) REFERENCES medicamentos(id_medicamento) ON DELETE CASCADE,
 FOREIGN KEY (id_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 INDEX idx_paciente (id_paciente),
 INDEX idx_toma (id_registro_toma),
 INDEX idx_alergia (es_reaccion_alergica)
) ENGINE=InnoDB;

-- ─── 4 · Relación familiar-paciente con permisos ────────────────────
CREATE TABLE IF NOT EXISTS familiar_paciente (
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
 fecha_invitacion DATETIME DEFAULT CURRENT_TIMESTAMP,
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

-- ─── 5 · Prescripciones digitales del médico ────────────────────────
CREATE TABLE IF NOT EXISTS prescripciones_digitales (
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
 fecha_emision DATETIME DEFAULT CURRENT_TIMESTAMP,
 fecha_modificacion DATETIME DEFAULT NULL,
 fecha_cancelacion DATETIME DEFAULT NULL,
 FOREIGN KEY (id_medico) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_medicamento_generado) REFERENCES medicamentos(id_medicamento) ON DELETE SET NULL,
 INDEX idx_medico (id_medico),
 INDEX idx_paciente (id_paciente),
 INDEX idx_estado (estado)
) ENGINE=InnoDB;

-- ─── 6 · Asignación de pacientes a turnos de enfermería ─────────────
CREATE TABLE IF NOT EXISTS turno_enfermeria (
 id_turno INT PRIMARY KEY AUTO_INCREMENT,
 id_enfermero INT NOT NULL,
 id_paciente INT NOT NULL,
 nombre_turno VARCHAR(50) NOT NULL DEFAULT 'Turno general',
 asignado_por INT DEFAULT NULL,
 activo BOOLEAN NOT NULL DEFAULT TRUE,
 fecha_asignacion DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (id_enfermero) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 FOREIGN KEY (id_paciente) REFERENCES usuarios(id_usuario) ON DELETE CASCADE,
 UNIQUE KEY uniq_enfermero_paciente (id_enfermero, id_paciente),
 INDEX idx_enfermero (id_enfermero, activo)
) ENGINE=InnoDB;

-- ─── 7 · Notas clínicas visibles al paciente ────────────────────────
-- medico_paciente ya tiene notas_clinicas (TEXT) del ; lo reutilizamos.
-- Añadimos solo la fecha de última nota para mostrar al paciente "actualizada el...".
ALTER TABLE medico_paciente
 ADD COLUMN notas_clinicas_fecha DATETIME DEFAULT NULL AFTER notas_clinicas;

-- ─── 8 · Nuevos tipos de aviso de notificación ───────────────────
-- Ampliamos el ENUM tipo_aviso para los nuevos eventos del.
ALTER TABLE notificaciones
 MODIFY COLUMN tipo_aviso
 ENUM('temprano','recordatorio','tardio','stock_bajo','interaccion',
 'cedula_aprobada','cedula_rechazada','generico',
 'familiar_bajo_70','nueva_prescripcion','reaccion_alergica_urgente')
 NOT NULL;

-- ════════════════════════════════════════════════════════════════════════════
-- VISTAS DE REPORTES
-- ════════════════════════════════════════════════════════════════════════════

-- Vista base: cada toma marcada con su clasificación de omisión
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

SELECT 'MIGRACIÓN SPRINT 3 COMPLETA' AS estado;
