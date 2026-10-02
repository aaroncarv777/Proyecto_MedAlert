<?php
require_once __DIR__ . '/../includes/auth.php';
if (Auth::usuarioActual()) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
