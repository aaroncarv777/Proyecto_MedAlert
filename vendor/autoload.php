<?php
/**
 * Autoload simple para vendors integrados.
 * Reemplazable por composer/autoload.php cuando uses Composer real.
 */
spl_autoload_register(function (string $clase) {
    $prefijo = 'MedAlert\\Vendor\\';
    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) return;

    $relativa = substr($clase, strlen($prefijo));
    $archivo = __DIR__ . '/' . str_replace('\\', '/', $relativa) . '.php';
    if (is_file($archivo)) require_once $archivo;
});
