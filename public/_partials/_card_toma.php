<?php

$estadoCalc = Helpers::calcularEstadoToma($t);
$claseCard = 'toma-card';
if ($estadoCalc === 'vencida') $claseCard .= ' is-vencida';
elseif ($t['estado'] === 'cumplida') $claseCard .= ' is-cumplida';
?>
<div class="<?= $claseCard ?>" data-id-registro="<?= (int)$t['id_registro'] ?>">
  <div class="toma-head">
    <div class="toma-info">
      <div class="toma-nombre"><?= Auth::escapar($t['medicamento_nombre']) ?></div>
      <div class="toma-detalle">
        <?= Auth::escapar($t['presentacion']) ?> · <?= Auth::escapar($t['dosis']) ?>
      </div>
    </div>
    <div class="toma-hora">
      <i class="bi bi-clock"></i>
      <?= Helpers::hora12($t['hora_programada']) ?>
    </div>
  </div>

  <?php if ($estadoCalc === 'vencida' && $t['estado'] !== 'cumplida'): ?>
    <?php
      $dif = floor((time() - strtotime($t['fecha_programada'].' '.$t['hora_programada'])) / 60);
      $h = floor($dif / 60); $m = $dif % 60;
      $hace = $h > 0 ? "{$h}h " . ($m > 0 ? "{$m}m" : "") : "{$m} min";
    ?>
    <div class="toma-aviso">
      <i class="bi bi-exclamation-triangle-fill"></i>
      Vencida hace <?= $hace ?>. Aún puedes registrarla.
    </div>
  <?php endif; ?>

  <?php if (!empty($t['instrucciones_medico'])): ?>
  <div class="toma-instr is-medico">
    <i class="bi bi-clipboard2-pulse-fill"></i>
    <div><strong>Indicación de tu médico:</strong> <?= Auth::escapar($t['instrucciones_medico']) ?></div>
  </div>
  <?php elseif (!empty($t['instrucciones_paciente'])): ?>
  <div class="toma-instr is-paciente">
    <i class="bi bi-sticky"></i>
    <div><strong>Tu nota:</strong> <?= Auth::escapar($t['instrucciones_paciente']) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($t['estado'] === 'cumplida'): ?>
    <div class="toma-resultado">
      <i class="bi bi-check-circle-fill"></i>
      <div>
        Tomada a las <?= Helpers::hora12(date('H:i', strtotime($t['fecha_real_toma']))) ?>
        <?php if (!empty($t['diferencia_minutos'])): ?>
          (<?= abs((int)$t['diferencia_minutos']) ?> min
          <?= (int)$t['diferencia_minutos'] >= 0 ? 'de retraso' : 'antes' ?>)
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($t['puede_deshacer']) && $t['puede_deshacer']): ?>
      <?php $minRest = max(0, VENTANA_DESHACER_MINUTOS - floor((time() - strtotime($t['fecha_marcado'])) / 60)); ?>
      <div class="toma-deshacer">
        <span class="countdown">
          <i class="bi bi-arrow-counterclockwise"></i>
          Puedes deshacer durante <?= $minRest ?> min más
        </span>
        <button type="button" class="js-deshacer-toma" data-id="<?= (int)$t['id_registro'] ?>">Deshacer</button>
      </div>
    <?php endif; ?>

  <?php elseif ($t['estado'] === 'no_cumplida'): ?>
    <div class="toma-resultado is-omitida">
      <i class="bi bi-x-circle-fill"></i>
      <div>
        No registrada
        <?php if (!empty($t['motivo_omision'])): ?>
          · <?= Auth::escapar($t['motivo_omision']) ?>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!empty($t['puede_deshacer']) && $t['puede_deshacer']): ?>
    <div class="toma-deshacer">
      <span class="countdown"><i class="bi bi-arrow-counterclockwise"></i> Puedes deshacer</span>
      <button type="button" class="js-deshacer-toma" data-id="<?= (int)$t['id_registro'] ?>">Deshacer</button>
    </div>
    <?php endif; ?>

  <?php else: ?>
    <div class="toma-actions">
      <button type="button" class="btn btn-success js-marcar-toma"
              data-id="<?= (int)$t['id_registro'] ?>" data-accion="cumplida"
              data-medicamento="<?= Auth::escapar($t['medicamento_nombre']) ?>">
        <i class="bi bi-check-lg"></i> Sí la tomé
      </button>
      <button type="button" class="btn btn-danger js-marcar-toma"
              data-id="<?= (int)$t['id_registro'] ?>" data-accion="no_cumplida"
              data-medicamento="<?= Auth::escapar($t['medicamento_nombre']) ?>">
        <i class="bi bi-x-lg"></i> No la tomé
      </button>
    </div>
  <?php endif; ?>
</div>
