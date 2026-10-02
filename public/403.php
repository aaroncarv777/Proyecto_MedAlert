<?php
require_once __DIR__ . '/../includes/auth.php';
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Acceso denegado · MedAlert</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<div style="min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; background:var(--gray-100);">
  <div class="card text-center" style="max-width:480px; padding:48px 32px;">
    <div style="width:80px; height:80px; background:var(--red-50); color:var(--red-500); border-radius:50%; margin:0 auto 24px; display:flex; align-items:center; justify-content:center; font-size:38px;">
      <i class="bi bi-shield-exclamation"></i>
    </div>
    <h1 style="font-size:24px; color:var(--navy-900); margin-bottom:10px;">Acceso denegado</h1>
    <p class="text-muted mb-3">No tienes permiso para acceder a esta sección. Si crees que esto es un error, contacta al administrador del sistema.</p>
    <a href="dashboard.php" class="btn btn-primary">
      <i class="bi bi-house"></i> Volver al inicio
    </a>
  </div>
</div>
</body>
</html>
