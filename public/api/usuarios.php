<?php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);
if ($usuario['tipo_nombre'] !== 'Administrador') {
    Helpers::jsonRespuesta(['error' => 'Sólo el administrador puede gestionar usuarios.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Helpers::jsonRespuesta(['error' => 'Método no permitido.'], 405);
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

switch ($accion) {
    case 'cambiar_tipo':       cambiarTipo($datos, $usuario); break;
    case 'crear_usuario':      crearUsuario($datos, $usuario); break;
    case 'suspender_usuario':  suspenderUsuario($datos, $usuario); break;
    case 'reactivar_usuario':  reactivarUsuario($datos, $usuario); break;
    case 'asignar_paciente':   asignarPaciente($datos, $usuario); break;
    case 'revocar_asignacion': revocarAsignacion($datos, $usuario); break;
    default:                   Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

function cambiarTipo(array $d, array $usuario): void
{
    $idUsuarioAfectado = (int)($d['id_usuario'] ?? 0);
    $idTipoNuevo       = (int)($d['id_tipo_nuevo'] ?? 0);
    $motivo            = trim($d['motivo'] ?? '');

    if ($idUsuarioAfectado <= 0 || $idTipoNuevo <= 0) {
        Helpers::jsonRespuesta(['error' => 'Datos incompletos.'], 400);
    }
    if ($idUsuarioAfectado === (int)$usuario['id_usuario']) {
        Helpers::jsonRespuesta(['error' => 'No puedes cambiar tu propio tipo de cuenta.'], 400);
    }

    $afectado = DB::uno(
        "SELECT u.id_usuario, u.nombre_completo, t.nombre AS tipo_actual_nombre, t.id_tipo
           FROM usuarios u
           INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
          WHERE u.id_usuario = :id",
        ['id' => $idUsuarioAfectado]
    );
    if (!$afectado) Helpers::jsonRespuesta(['error' => 'Usuario no encontrado.'], 404);

    $tipoNuevo = DB::uno(
        "SELECT nombre FROM tipos_usuario WHERE id_tipo = :id AND activo = TRUE",
        ['id' => $idTipoNuevo]
    );
    if (!$tipoNuevo) Helpers::jsonRespuesta(['error' => 'Tipo no válido.'], 400);

    if (!in_array($afectado['tipo_actual_nombre'], ['Medico', 'Administrador'], true)) {
        Helpers::jsonRespuesta([
            'error' => 'Solo se puede cambiar el tipo de cuentas profesionales (Médico/Administrador). Los pacientes y cuidadores eligieron su rol al registrarse.'
        ], 403);
    }
    if (!in_array($tipoNuevo['nombre'], ['Medico', 'Administrador'], true)) {
        Helpers::jsonRespuesta([
            'error' => 'Solo puedes cambiar entre los roles Médico y Administrador.'
        ], 400);
    }
    if ((int)$afectado['id_tipo'] === $idTipoNuevo) {
        Helpers::jsonRespuesta(['error' => 'El usuario ya tiene ese tipo asignado.'], 400);
    }

    if ($afectado['tipo_actual_nombre'] === 'Administrador'
        && $tipoNuevo['nombre'] !== 'Administrador') {
        $totalAdmins = (int)DB::uno(
            "SELECT COUNT(*) AS n FROM usuarios u
              INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
              WHERE t.nombre = 'Administrador' AND u.estado_cuenta = 'activa'"
        )['n'];
        if ($totalAdmins <= 1) {
            Helpers::jsonRespuesta(['error' => 'No se puede quitar el último administrador del sistema.'], 400);
        }
    }

    $db = DB::obtener();
    $db->beginTransaction();
    try {
        DB::consultar("UPDATE usuarios SET id_tipo = :nuevo WHERE id_usuario = :id",
                      ['nuevo' => $idTipoNuevo, 'id' => $idUsuarioAfectado]);
        DB::consultar(
            "INSERT INTO log_cambios_tipo_usuario
              (id_usuario_afectado, id_admin, tipo_anterior, tipo_nuevo, motivo)
             VALUES (:af, :ad, :ta, :tn, :mo)",
            ['af' => $idUsuarioAfectado, 'ad' => (int)$usuario['id_usuario'],
             'ta' => $afectado['tipo_actual_nombre'], 'tn' => $tipoNuevo['nombre'],
             'mo' => $motivo ?: null]
        );
        DB::consultar("UPDATE sesiones_activas SET activa = FALSE
                        WHERE id_usuario = :id AND activa = TRUE",
                      ['id' => $idUsuarioAfectado]);
        $db->commit();
        Helpers::jsonRespuesta(['ok' => true,
            'mensaje' => "Tipo de {$afectado['nombre_completo']} cambiado a {$tipoNuevo['nombre']}."]);
    } catch (Exception $e) {
        $db->rollBack();
        Helpers::jsonRespuesta(['error' => 'Error al cambiar: ' . $e->getMessage()], 500);
    }
}

function crearUsuario(array $d, array $usuario): void
{
    $nombre        = trim($d['nombre_completo'] ?? '');
    $correo        = mb_strtolower(trim($d['correo'] ?? ''));
    $telefono      = preg_replace('/\s+/', '', trim($d['telefono'] ?? ''));
    $contrasena    = (string)($d['contrasena'] ?? '');
    $idTipo        = (int)($d['id_tipo'] ?? 0);
    $cedula        = trim($d['cedula_profesional'] ?? '');
    $especialidad  = trim($d['especialidad'] ?? '');

    if (mb_strlen($nombre) < 3) Helpers::jsonRespuesta(['error' => 'Nombre obligatorio.'], 400);
    if (!Helpers::validarCorreo($correo)) {
        Helpers::jsonRespuesta(['error' => Helpers::errorCorreoNoGmail()], 400);
    }
    if (!Helpers::validarTelefono($telefono)) {
        Helpers::jsonRespuesta(['error' => 'Teléfono de 10 dígitos.'], 400);
    }
    $val = Helpers::validarContrasena($contrasena);
    if (!$val['valida']) Helpers::jsonRespuesta(['error' => $val['error']], 400);

    if (!in_array($idTipo, [3, 4, 5], true)) {
        Helpers::jsonRespuesta(['error' => 'Sólo se pueden crear cuentas de Médico, Enfermero o Administrador.'], 400);
    }

    if (DB::uno("SELECT 1 FROM usuarios WHERE correo = :c", ['c' => $correo])) {
        Helpers::jsonRespuesta(['error' => 'Ya existe una cuenta con ese correo.'], 409);
    }

    if ($idTipo === 3) {
        if (mb_strlen($cedula) < 4) {
            Helpers::jsonRespuesta(['error' => 'La cédula profesional es obligatoria para médicos.'], 400);
        }
        if (mb_strlen($especialidad) < 3) {
            Helpers::jsonRespuesta(['error' => 'La especialidad es obligatoria para médicos.'], 400);
        }
    }

    DB::consultar(
        "INSERT INTO usuarios
          (nombre_completo, correo, telefono, contrasena_hash,
           id_tipo, estado_cuenta, cedula_profesional, especialidad)
         VALUES (:nom, :cor, :tel, :hash, :tip, 'activa', :ced, :esp)",
        [
            'nom'  => $nombre,
            'cor'  => $correo,
            'tel'  => $telefono,
            'hash' => Auth::hashearContrasena($contrasena),
            'tip'  => $idTipo,
            'ced'  => $idTipo === 3 ? $cedula : null,
            'esp'  => $idTipo === 3 ? $especialidad : null,
        ]
    );

    Helpers::jsonRespuesta([
        'ok' => true,
        'mensaje' => "Cuenta creada para {$nombre}. Ya puede iniciar sesión con la contraseña que le compartiste."
    ]);
}

function suspenderUsuario(array $d, array $usuario): void
{
    $idAfectado = (int)($d['id_usuario'] ?? 0);
    if ($idAfectado <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);
    if ($idAfectado === (int)$usuario['id_usuario']) {
        Helpers::jsonRespuesta(['error' => 'No puedes suspender tu propia cuenta.'], 400);
    }

    $afectado = DB::uno(
        "SELECT u.*, t.nombre AS tipo_nombre
           FROM usuarios u
           INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
          WHERE u.id_usuario = :id",
        ['id' => $idAfectado]
    );
    if (!$afectado) Helpers::jsonRespuesta(['error' => 'Usuario no encontrado.'], 404);

    if ($afectado['tipo_nombre'] === 'Administrador') {
        $totalAdmins = (int)DB::uno(
            "SELECT COUNT(*) AS n FROM usuarios u
              INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
              WHERE t.nombre = 'Administrador' AND u.estado_cuenta = 'activa'"
        )['n'];
        if ($totalAdmins <= 1) {
            Helpers::jsonRespuesta(['error' => 'No se puede suspender al último administrador del sistema.'], 400);
        }
    }

    DB::consultar("UPDATE usuarios SET estado_cuenta = 'suspendida' WHERE id_usuario = :id",
                  ['id' => $idAfectado]);
    DB::consultar("UPDATE sesiones_activas SET activa = FALSE WHERE id_usuario = :id",
                  ['id' => $idAfectado]);
    Helpers::jsonRespuesta(['ok' => true,
        'mensaje' => "{$afectado['nombre_completo']} fue suspendido. Sus datos se conservan."]);
}

function reactivarUsuario(array $d, array $usuario): void
{
    $idAfectado = (int)($d['id_usuario'] ?? 0);
    if ($idAfectado <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);

    $afectado = DB::uno("SELECT * FROM usuarios WHERE id_usuario = :id", ['id' => $idAfectado]);
    if (!$afectado) Helpers::jsonRespuesta(['error' => 'No encontrado.'], 404);
    if ($afectado['estado_cuenta'] !== 'suspendida') {
        Helpers::jsonRespuesta(['error' => 'Esta cuenta no está suspendida.'], 400);
    }

    DB::consultar("UPDATE usuarios SET estado_cuenta = 'activa' WHERE id_usuario = :id",
                  ['id' => $idAfectado]);
    Helpers::jsonRespuesta(['ok' => true, 'mensaje' => 'Cuenta reactivada.']);
}

function asignarPaciente(array $d, array $usuario): void
{
    $idCuidador = (int)($d['id_cuidador'] ?? 0);
    $idPaciente = (int)($d['id_paciente'] ?? 0);
    $parentesco = trim($d['parentesco'] ?? '');

    if ($idCuidador <= 0 || $idPaciente <= 0) {
        Helpers::jsonRespuesta(['error' => 'Selecciona el cuidador y el paciente.'], 400);
    }

    $cuidador = DB::uno(
        "SELECT u.*, t.nombre AS tipo_nombre FROM usuarios u
           INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
          WHERE u.id_usuario = :id",
        ['id' => $idCuidador]
    );
    if (!$cuidador || $cuidador['tipo_nombre'] !== 'Cuidador') {
        Helpers::jsonRespuesta(['error' => 'El cuidador seleccionado no es válido.'], 400);
    }

    $paciente = DB::uno(
        "SELECT u.*, t.nombre AS tipo_nombre FROM usuarios u
           INNER JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
          WHERE u.id_usuario = :id",
        ['id' => $idPaciente]
    );
    if (!$paciente || $paciente['tipo_nombre'] !== 'Paciente') {
        Helpers::jsonRespuesta(['error' => 'El paciente seleccionado no es válido.'], 400);
    }

    $existe = DB::uno(
        "SELECT id_relacion FROM cuidador_paciente
          WHERE id_cuidador = :c AND id_paciente = :p AND activa = TRUE",
        ['c' => $idCuidador, 'p' => $idPaciente]
    );
    if ($existe) {
        Helpers::jsonRespuesta(['error' => "{$cuidador['nombre_completo']} ya está asignado a {$paciente['nombre_completo']}."], 409);
    }

    DB::consultar(
        "INSERT INTO cuidador_paciente (id_cuidador, id_paciente, parentesco, asignada_por)
         VALUES (:c, :p, :par, :ad)",
        ['c' => $idCuidador, 'p' => $idPaciente,
         'par' => $parentesco ?: null, 'ad' => (int)$usuario['id_usuario']]
    );
    Helpers::jsonRespuesta(['ok' => true,
        'mensaje' => "{$cuidador['nombre_completo']} asignado a {$paciente['nombre_completo']}."]);
}

function revocarAsignacion(array $d, array $usuario): void
{
    $idRelacion = (int)($d['id_relacion'] ?? 0);
    if ($idRelacion <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);
    DB::consultar(
        "UPDATE cuidador_paciente SET activa = FALSE, fecha_revocacion = NOW()
          WHERE id_relacion = :id AND activa = TRUE",
        ['id' => $idRelacion]
    );
    Helpers::jsonRespuesta(['ok' => true, 'mensaje' => 'Asignación revocada.']);
}
