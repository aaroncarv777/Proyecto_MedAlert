<?php
require_once __DIR__ . '/../includes/auth.php';
Auth::cerrarSesion();
header('Location: login.php');
exit;
