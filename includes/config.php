<?php

define('DB_HOST', 'localhost');
define('DB_NAME', 'medalert');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('MAIL_HOST',     'smtp.gmail.com');
define('MAIL_PORT',     587);
define('MAIL_USER',     'carvajalaaron44@gmail.com');
define('MAIL_PASS',     'lyqc hfjs gbwu ijim');
define('MAIL_FROM',     'carvajalaaron44@gmail.com');
define('MAIL_FROM_NAME','MedAlert');

define('BCRYPT_COST', 10);
define('MAX_INTENTOS_LOGIN', 3);
define('TIEMPO_BLOQUEO_MINUTOS', 5);

define('EXPIRACION_VERIFICACION_HORAS', 24);

define('RECUPERACION_DURACION_MINUTOS', 20);
define('RECUPERACION_MAX_INTENTOS', 3);
define('RECUPERACION_BLOQUEO_MINUTOS', 5);
define('RECUPERACION_MAX_ENVIOS_HORA', 3);

define('DURACION_SESION_HORAS', 8);
define('VENTANA_DESHACER_MINUTOS', 30);

define('CAMBIO_PERFIL_DURACION_MINUTOS', 20);
define('CAMBIO_PERFIL_MAX_INTENTOS', 3);
define('CAMBIO_PERFIL_BLOQUEO_MINUTOS', 5);

// ─── Constantes de validación de campos (Sprint 1 + 2) ─────────────────
define('DOSIS_MAX_CARACTERES', 80);
define('DOSIS_PATRON_REGEX', '/^[0-9a-zA-ZáéíóúÁÉÍÓÚñÑ\s\.,\-\/\(\)]+$/u');
define('NOMBRE_MEDICAMENTO_MAX', 120);
define('INSTRUCCION_MAX_CARACTERES', 500);
define('NOTAS_CLINICAS_MAX', 1000);
define('JUSTIFICACION_INTERACCION_MIN', 10);
define('JUSTIFICACION_INTERACCION_MAX', 1000);
define('MOTIVO_RECHAZO_CEDULA_MIN', 10);
define('MOTIVO_RECHAZO_CEDULA_MAX', 500);

// ─── HU-04: validación de cédulas ────────────────────────────────────────
define('CEDULA_PATRON_REGEX', '/^[0-9]{7,8}$/');
define('IMAGEN_CEDULA_TAMANO_MAX_MB', 5);
define('IMAGEN_CEDULA_FORMATOS', ['image/jpeg','image/png','image/jpg']);

// ─── HU-09: cálculo de dosis ─────────────────────────────────────────────
define('DOSIS_PESO_MIN_KG', 1);
define('DOSIS_PESO_MAX_KG', 300);
define('DOSIS_MG_KG_MIN', 0.001);
define('DOSIS_MG_KG_MAX', 100);

// ─── HU-10: stock ─────────────────────────────────────────────────────────
define('STOCK_DIAS_ALERTA_DEFAULT', 3);
define('STOCK_DIAS_ALERTA_MIN', 1);
define('STOCK_DIAS_ALERTA_MAX', 30);
define('STOCK_UNIDADES_MAX', 99999);

// ─── HU-12 + HU-13: notificaciones ────────────────────────────────────────
define('AVISO_MINUTOS_TEMPRANO',     15);   // 15 min antes
define('AVISO_MINUTOS_RECORDATORIO',  5);   // 5 min antes
define('AVISO_MINUTOS_TARDIO',       10);   // 10 min después
define('NOTIFICACIONES_LOTE_MAX',   100);   // máx por ejecución del cron
define('NOTIFICACION_TITULO_MAX',   150);
define('NOTIFICACION_MENSAJE_MAX', 1000);

// ─── HU-12: SMS por Twilio (integración real) ─────────────────────────────
// El cliente solicitó SMS real con Twilio.
// Para activarlo en producción, registra una cuenta en https://www.twilio.com/
// y reemplaza estas constantes con tus credenciales reales.
// Si TWILIO_HABILITADO = false, los SMS solo se registran en la base de datos
// y en el log local (logs/sms_twilio.log) sin enviarse realmente, lo cual
// permite probar todo el flujo sin gastar saldo de Twilio.
define('TWILIO_HABILITADO',  false);                          // cambia a true en producción
define('TWILIO_ACCOUNT_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');  // sustituir
define('TWILIO_AUTH_TOKEN',  'tu_auth_token_aqui');                  // sustituir
define('TWILIO_FROM_NUMBER', '+15005550006');                        // número Twilio (formato E.164)
define('TWILIO_PAIS_DEFAULT','+52');                                 // prefijo por defecto (México)
define('TWILIO_SMS_MAX_CARACTERES', 160);
define('TWILIO_TIMEOUT_SEGUNDOS',     8);                            // timeout HTTP

define('JWT_SECRETO',        'medalert_jwt_s3cr3t0_2026_cambiar_en_produccion');
define('JWT_ACCESS_MINUTOS', 2);
define('JWT_REFRESH_DIAS',   7);
define('JWT_ACCESS_COOKIE',  'ma_acc');
define('JWT_REFRESH_COOKIE', 'ma_ref');

define('APP_NAME', 'MedAlert');
define('APP_VERSION', '2.0.0');
define('APP_URL', 'http://localhost/MedAlert/public');
date_default_timezone_set('America/Merida');

define('AMBIENTE', 'desarrollo');
if (AMBIENTE === 'desarrollo') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 0,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
    ]);
}
