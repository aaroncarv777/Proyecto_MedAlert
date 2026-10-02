<?php
/**
 * MedAlert · API de cédulas médicas (Sprint 2 — HU-04)
 * --------------------------------------------------------------
 * GET  ?accion=pendientes              — solo Administrador
 * POST {accion: aprobar, id_medico}    — solo Administrador
 * POST {accion: rechazar, id_medico, motivo} — solo Administrador
 * POST {accion: actualizar_cedula, cedula, especialidad, imagen_base64?} — solo Medico
 *
 * NOTA: la subida de imagen viaja como base64 dentro del JSON.
 *       Se guarda en /public/uploads/cedulas/ con nombre único.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/notificaciones.php';

$usuario = Auth::usuarioActual();
if (!$usuario) Helpers::jsonRespuesta(['error' => 'Sesión no válida.'], 401);

$idUsuario = (int)$usuario['id_usuario'];
$tipo = $usuario['tipo_nombre'];
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'GET') {
    $accion = Helpers::get('accion');

    if ($accion === 'pendientes') {
        if ($tipo !== 'Administrador') {
            Helpers::jsonRespuesta(['error' => 'Solo el administrador puede ver esta lista.'], 403);
        }
        $estadoFiltro = Helpers::get('estado'); // pendientes|aprobadas|rechazadas
        $sql = "SELECT id_usuario, nombre_completo, correo, cedula_profesional, especialidad,
                       imagen_cedula, cedula_validada, fecha_validacion_cedula, motivo_rechazo_cedula
                  FROM usuarios
                 WHERE id_tipo = (SELECT id_tipo FROM tipos_usuario WHERE nombre = 'Medico')";
        if ($estadoFiltro === 'aprobadas') {
            $sql .= " AND cedula_validada = TRUE";
        } elseif ($estadoFiltro === 'rechazadas') {
            $sql .= " AND cedula_validada = FALSE AND motivo_rechazo_cedula IS NOT NULL";
        } else {
            // por defecto: pendientes (no validadas y sin motivo de rechazo, pero con cédula cargada)
            $sql .= " AND cedula_validada = FALSE";
        }
        $sql .= " ORDER BY fecha_registro DESC";
        Helpers::jsonRespuesta(['ok' => true, 'medicos' => DB::todos($sql)]);
    }

    Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);
}

$datos = Helpers::leerJson();
$accion = $datos['accion'] ?? '';

if ($accion === 'aprobar') {
    if ($tipo !== 'Administrador') {
        Helpers::jsonRespuesta(['error' => 'Solo el administrador puede aprobar.'], 403);
    }
    $idMedico = (int)($datos['id_medico'] ?? 0);
    if ($idMedico <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);

    $medico = DB::uno(
        "SELECT u.* FROM usuarios u
          JOIN tipos_usuario t ON t.id_tipo = u.id_tipo
          WHERE u.id_usuario = :id AND t.nombre = 'Medico'",
        ['id' => $idMedico]
    );
    if (!$medico) Helpers::jsonRespuesta(['error' => 'Médico no encontrado.'], 404);
    if (empty($medico['cedula_profesional'])) {
        Helpers::jsonRespuesta([
            'error' => 'Este médico aún no ha cargado su cédula profesional.'
        ], 400);
    }

    DB::consultar(
        "UPDATE usuarios
            SET cedula_validada = TRUE,
                fecha_validacion_cedula = NOW(),
                id_admin_validador = :adm,
                motivo_rechazo_cedula = NULL
          WHERE id_usuario = :id",
        ['adm' => $idUsuario, 'id' => $idMedico]
    );

    DB::consultar(
        "INSERT INTO log_validacion_cedulas
          (id_medico, id_admin, accion, motivo)
         VALUES (:m, :a, 'aprobada', NULL)",
        ['m' => $idMedico, 'a' => $idUsuario]
    );

    // Notificar al médico
    Mailer::enviarCedulaAprobada(
        $medico['correo'], $medico['nombre_completo'],
        $medico['cedula_profesional'], $medico['especialidad'] ?? '—'
    );
    Notificaciones::crearGenerica(
        $idMedico,
        '¡Tus credenciales fueron aprobadas!',
        'El administrador validó tu cédula. Ya puedes prescribir medicamentos a tus pacientes asignados.',
        'cedula_aprobada'
    );

    Helpers::jsonRespuesta(['ok' => true]);
}

if ($accion === 'rechazar') {
    if ($tipo !== 'Administrador') {
        Helpers::jsonRespuesta(['error' => 'Solo el administrador puede rechazar.'], 403);
    }
    $idMedico = (int)($datos['id_medico'] ?? 0);
    $motivo = trim($datos['motivo'] ?? '');
    if ($idMedico <= 0) Helpers::jsonRespuesta(['error' => 'ID inválido.'], 400);

    $len = mb_strlen($motivo);
    if ($len < MOTIVO_RECHAZO_CEDULA_MIN) {
        Helpers::jsonRespuesta([
            'error' => 'El motivo del rechazo debe tener al menos '
                . MOTIVO_RECHAZO_CEDULA_MIN . ' caracteres.'
        ], 400);
    }
    if ($len > MOTIVO_RECHAZO_CEDULA_MAX) {
        Helpers::jsonRespuesta([
            'error' => 'El motivo no puede superar ' . MOTIVO_RECHAZO_CEDULA_MAX . ' caracteres.'
        ], 400);
    }

    $medico = DB::uno("SELECT * FROM usuarios WHERE id_usuario = :id", ['id' => $idMedico]);
    if (!$medico) Helpers::jsonRespuesta(['error' => 'Médico no encontrado.'], 404);

    DB::consultar(
        "UPDATE usuarios
            SET cedula_validada = FALSE,
                fecha_validacion_cedula = NOW(),
                id_admin_validador = :adm,
                motivo_rechazo_cedula = :mot
          WHERE id_usuario = :id",
        ['adm' => $idUsuario, 'mot' => $motivo, 'id' => $idMedico]
    );

    DB::consultar(
        "INSERT INTO log_validacion_cedulas
          (id_medico, id_admin, accion, motivo)
         VALUES (:m, :a, 'rechazada', :mot)",
        ['m' => $idMedico, 'a' => $idUsuario, 'mot' => $motivo]
    );

    Mailer::enviarCedulaRechazada($medico['correo'], $medico['nombre_completo'], $motivo);
    Notificaciones::crearGenerica(
        $idMedico,
        'Credenciales rechazadas',
        'El administrador no pudo validar tu cédula. Motivo: ' . $motivo
            . ' Corrige tu información en "Mi perfil" y vuelve a enviarla.',
        'cedula_rechazada'
    );

    Helpers::jsonRespuesta(['ok' => true]);
}

if ($accion === 'actualizar_cedula') {
    if ($tipo !== 'Medico') {
        Helpers::jsonRespuesta(['error' => 'Solo el médico puede actualizar su cédula.'], 403);
    }
    $cedula = trim($datos['cedula'] ?? '');
    $especialidad = trim($datos['especialidad'] ?? '');
    $imagenB64 = $datos['imagen_base64'] ?? null;

    if (!preg_match(CEDULA_PATRON_REGEX, $cedula)) {
        Helpers::jsonRespuesta([
            'error' => 'La cédula profesional debe tener 7 u 8 dígitos numéricos.'
        ], 400);
    }
    if (mb_strlen($especialidad) < 3 || mb_strlen($especialidad) > 80) {
        Helpers::jsonRespuesta([
            'error' => 'La especialidad debe tener entre 3 y 80 caracteres.'
        ], 400);
    }

    // Verificar que la cédula no esté ya en uso por otro médico aprobado
    $duplicada = DB::uno(
        "SELECT id_usuario FROM usuarios
          WHERE cedula_profesional = :c AND id_usuario <> :u AND cedula_validada = TRUE",
        ['c' => $cedula, 'u' => $idUsuario]
    );
    if ($duplicada) {
        Helpers::jsonRespuesta([
            'error' => 'Esta cédula profesional ya está registrada por otro médico validado.'
        ], 409);
    }

    $rutaImagen = null;
    if (!empty($imagenB64)) {
        $rutaImagen = guardarImagenCedula($imagenB64, $idUsuario);
        if ($rutaImagen === null) {
            Helpers::jsonRespuesta([
                'error' => 'La imagen no es válida. Debe ser JPG o PNG, máximo '
                    . IMAGEN_CEDULA_TAMANO_MAX_MB . ' MB.'
            ], 400);
        }
    }

    DB::consultar(
        "UPDATE usuarios
            SET cedula_profesional = :c,
                especialidad = :e,
                " . ($rutaImagen ? "imagen_cedula = :img," : "") . "
                cedula_validada = FALSE,
                motivo_rechazo_cedula = NULL,
                fecha_validacion_cedula = NULL,
                id_admin_validador = NULL
          WHERE id_usuario = :id",
        array_filter([
            'c' => $cedula, 'e' => $especialidad,
            'img' => $rutaImagen, 'id' => $idUsuario,
        ], fn($v) => $v !== null || true)
    );

    Helpers::jsonRespuesta([
        'ok' => true,
        'mensaje' => 'Tu cédula fue actualizada y enviada al administrador para revisión.'
    ]);
}

Helpers::jsonRespuesta(['error' => 'Acción no reconocida.'], 400);

/* Guardar imagen base64 en /public/uploads/cedulas/ */
function guardarImagenCedula(string $b64, int $idUsuario): ?string
{
    if (!preg_match('/^data:(image\/(jpeg|png|jpg));base64,(.+)$/', $b64, $m)) {
        return null;
    }
    $mime = $m[1];
    if (!in_array($mime, IMAGEN_CEDULA_FORMATOS, true)) return null;

    $bin = base64_decode($m[3], true);
    if ($bin === false) return null;
    $sizeMB = strlen($bin) / 1048576;
    if ($sizeMB > IMAGEN_CEDULA_TAMANO_MAX_MB) return null;

    $dir = __DIR__ . '/../uploads/cedulas';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $ext = $mime === 'image/png' ? 'png' : 'jpg';
    $nombre = 'cedula_' . $idUsuario . '_' . time() . '.' . $ext;
    $ruta = $dir . '/' . $nombre;

    if (file_put_contents($ruta, $bin) === false) return null;
    return 'uploads/cedulas/' . $nombre;
}
