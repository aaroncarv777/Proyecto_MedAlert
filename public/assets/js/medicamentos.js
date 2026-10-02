/**
 * MedAlert · Sprint 2 — Lógica frontend de medicamentos
 * --------------------------------------------------------------
 * Gestiona el modal de alta/edición con tabs de horario avanzado (HU-07),
 * dispara el cálculo de dosis (HU-09), recoge stock (HU-10) y muestra
 * las alertas de interacción (HU-08) que devuelve el servidor.
 */
(function () {
  'use strict';

  const modal = document.getElementById('modal-medicamento');
  const modalInteracciones = document.getElementById('modal-interacciones');
  const cuerpoInteracciones = document.getElementById('cuerpo-interacciones');
  const form  = document.getElementById('form-medicamento');
  const error = document.getElementById('med-error');
  const tabsHorario = document.getElementById('tabs-horario');
  const tipoHorarioInput = document.getElementById('med-tipo-horario');
  const horaInicioInput = document.getElementById('med-hora-inicio');

  if (!modal || !form || !tabsHorario) {
    console.warn('MedAlert: faltan elementos del modal de medicamento.');
    return;
  }

  function abrirModal(titulo) {
    document.getElementById('modal-titulo').textContent = titulo;
    if (error) { error.hidden = true; error.textContent = ''; }
    modal.hidden = false;
  }
  function cerrarModal() { modal.hidden = true; }
  function cerrarInteracciones() { if (modalInteracciones) modalInteracciones.hidden = true; }

  modal.querySelectorAll('[data-cerrar]').forEach(b => b.addEventListener('click', cerrarModal));
  if (modalInteracciones) {
    modalInteracciones.querySelectorAll('[data-cerrar-interacciones]').forEach(b =>
      b.addEventListener('click', cerrarInteracciones));
  }
  modal.addEventListener('click', (e) => { if (e.target === modal) cerrarModal(); });

  // Fecha mínima seleccionable = hoy (no se permiten fechas pasadas)
  function hoyISO() {
    const d = new Date();
    const off = d.getTimezoneOffset();
    return new Date(d.getTime() - off * 60000).toISOString().slice(0, 10);
  }

  const btnNuevo = document.getElementById('btn-nuevo-medicamento');
  if (btnNuevo) {
    btnNuevo.addEventListener('click', () => {
      form.reset();
      document.getElementById('med-id').value = '';
      const hoy = hoyISO();
      const fIni = document.getElementById('med-fecha-inicio');
      const fFin = document.getElementById('med-fecha-fin');
      fIni.min = hoy;
      fIni.value = hoy;
      if (fFin) fFin.min = hoy;
      cambiarTabHorario('diario');
      horaInicioInput.required = true;
      abrirModal('Nuevo medicamento');
    });
  }

  // ─── Pestañas de tipo de horario ──────────────────────────────────
  tabsHorario.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', (ev) => {
      ev.preventDefault();
      cambiarTabHorario(btn.dataset.tipo);
    });
  });

  function cambiarTabHorario(tipo) {
    tabsHorario.querySelectorAll('.tab-btn').forEach(b => {
      b.classList.toggle('is-active', b.dataset.tipo === tipo);
    });
    document.querySelectorAll('.contenido-horario').forEach(c => {
      c.classList.toggle('is-active', c.dataset.contenido === tipo);
    });
    if (tipoHorarioInput) tipoHorarioInput.value = tipo;
    if (horaInicioInput) {
      horaInicioInput.required = (tipo !== 'referencia_comida');
      horaInicioInput.disabled  = (tipo === 'referencia_comida');
      if (tipo === 'referencia_comida') horaInicioInput.value = '';
    }
  }

  // ─── Autoselección de unidad según presentación ──────────────────
  const selPresentacion = document.getElementById('med-presentacion');
  const selUnidad = document.getElementById('med-unidad');
  if (selPresentacion && selUnidad) {
    selPresentacion.addEventListener('change', () => {
      const opt = selPresentacion.options[selPresentacion.selectedIndex];
      if (!opt) return;
      const unidad = opt.getAttribute('data-unidad') || '';
      if (unidad) {
        selUnidad.value = unidad;
      }
    });
  }

  // Checkboxes de días de la semana
  document.querySelectorAll('.dia-checkbox input').forEach(chk => {
    chk.addEventListener('change', () => {
      chk.closest('.dia-checkbox').classList.toggle('is-checked', chk.checked);
    });
  });

  // Editar medicamento
  document.querySelectorAll('.btn-editar-med').forEach(btn => {
    btn.addEventListener('click', () => {
      const card = btn.closest('.med-card');
      const m = JSON.parse(card.dataset.medicamento);
      llenarFormulario(m);
      abrirModal('Editar medicamento');
    });
  });

  function llenarFormulario(m) {
    document.getElementById('med-id').value = m.id_medicamento;
    document.getElementById('med-nombre').value = m.nombre;
    document.getElementById('med-presentacion').value = m.presentacion;
    document.getElementById('med-cantidad').value = m.cantidad_dosis;
    document.getElementById('med-unidad').value = m.unidad_dosis;
    document.getElementById('med-frecuencia').value = m.frecuencia_horas;
    document.getElementById('med-hora-inicio').value = (m.hora_inicio || '').substring(0, 5);
    document.getElementById('med-fecha-inicio').value = m.fecha_inicio || '';
    document.getElementById('med-fecha-fin').value = m.fecha_fin || '';
    document.getElementById('med-unidades-actuales').value = m.unidades_actuales || 0;
    document.getElementById('med-unidades-toma').value = m.unidades_por_toma || 1;
    document.getElementById('med-alerta-dias').value = m.alerta_stock_dias || 3;
    cambiarTabHorario(m.tipo_horario || 'diario');

    if (m.tipo_horario === 'dias_semana' && m.dias_semana_bitmask) {
      const bm = parseInt(m.dias_semana_bitmask, 10);
      document.querySelectorAll('.dia-checkbox input').forEach(chk => {
        const bit = 1 << (parseInt(chk.value, 10) - 1);
        chk.checked = (bm & bit) !== 0;
        chk.closest('.dia-checkbox').classList.toggle('is-checked', chk.checked);
      });
    }
    if (m.intervalo_dias) form.querySelector('[name="intervalo_dias"]').value = m.intervalo_dias;
    if (m.ciclo_dias_activo) form.querySelector('[name="ciclo_dias_activo"]').value = m.ciclo_dias_activo;
    if (m.ciclo_dias_descanso) form.querySelector('[name="ciclo_dias_descanso"]').value = m.ciclo_dias_descanso;
    if (m.referencia_comida) form.querySelector('[name="referencia_comida"]').value = m.referencia_comida;
    if (m.minutos_referencia !== null) form.querySelector('[name="minutos_referencia"]').value = m.minutos_referencia;
  }

  // Submit
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (error) error.hidden = true;
    const idMed = document.getElementById('med-id').value;
    const accion = idMed ? 'editar' : 'crear';

    const datos = { accion: accion };
    if (idMed) datos.id_medicamento = parseInt(idMed, 10);

    const idPaciente = document.getElementById('med-id-paciente');
    if (idPaciente) datos.id_paciente = parseInt(idPaciente.value, 10) || 0;

    ['nombre','presentacion','unidad_dosis'].forEach(k => datos[k] = form.elements[k].value);
    datos.cantidad_dosis    = parseFloat(form.elements['cantidad_dosis'].value);
    datos.frecuencia_horas  = parseFloat(form.elements['frecuencia_horas'].value);
    datos.hora_inicio       = form.elements['hora_inicio'].value || '08:00';
    datos.fecha_inicio      = form.elements['fecha_inicio'].value;
    datos.fecha_fin         = form.elements['fecha_fin'].value || null;

    // Solo al crear: no se permiten fechas/horas pasadas
    if (accion === 'crear') {
      const hoy = hoyISO();
      if (!datos.fecha_inicio) {
        return mostrarError('Debes indicar la fecha de inicio.');
      }
      if (datos.fecha_inicio < hoy) {
        return mostrarError('La fecha de inicio no puede ser anterior a hoy.');
      }
      if (datos.fecha_inicio === hoy
          && tipoHorarioInput.value !== 'referencia_comida') {
        const ahora = new Date().toTimeString().slice(0, 5);
        const horaSel = (form.elements['hora_inicio'].value || '').slice(0, 5);
        if (horaSel && horaSel < ahora) {
          return mostrarError('La hora de inicio ya pasó. Elige una hora posterior a la actual o una fecha futura.');
        }
      }
      if (datos.fecha_fin && datos.fecha_fin < datos.fecha_inicio) {
        return mostrarError('La fecha de fin debe ser igual o posterior a la de inicio.');
      }
    }

    datos.instrucciones     = form.elements['instrucciones'].value;
    datos.tipo_horario      = tipoHorarioInput.value;
    datos.unidades_actuales = parseInt(form.elements['unidades_actuales'].value, 10) || 0;
    datos.unidades_por_toma = parseInt(form.elements['unidades_por_toma'].value, 10) || 0;
    datos.alerta_stock_dias = parseInt(form.elements['alerta_stock_dias'].value, 10) || 3;

    if (datos.tipo_horario === 'dias_semana') {
      datos.dias_semana = Array.from(document.querySelectorAll('.dia-checkbox input:checked'))
        .map(c => parseInt(c.value, 10));
    } else if (datos.tipo_horario === 'dias_alternos') {
      datos.intervalo_dias = parseInt(form.elements['intervalo_dias'].value, 10) || 2;
    } else if (datos.tipo_horario === 'ciclo') {
      datos.ciclo_dias_activo   = parseInt(form.elements['ciclo_dias_activo'].value, 10) || 21;
      datos.ciclo_dias_descanso = parseInt(form.elements['ciclo_dias_descanso'].value, 10) || 7;
    } else if (datos.tipo_horario === 'referencia_comida') {
      datos.referencia_comida  = form.elements['referencia_comida'].value;
      datos.minutos_referencia = parseInt(form.elements['minutos_referencia'].value, 10) || 0;
    }

    const mgKg = form.elements['dosis_mg_por_kg'];
    if (mgKg && mgKg.value) {
      datos.dosis_mg_por_kg    = parseFloat(mgKg.value);
      datos.ajuste_manual_dosis = !!(form.elements['ajuste_manual_dosis'] && form.elements['ajuste_manual_dosis'].checked);
    }

    try {
      const r = await fetch('api/medicamentos.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(datos)
      });
      const d = await r.json().catch(() => ({
        error: 'El servidor no respondió correctamente. Inténtalo de nuevo.'
      }));
      if (!d.ok) {
        if (d.duplicado) {
          if (confirm(d.error + '\n\nYa existe: ' + d.duplicado.nombre + ' · ' + d.duplicado.dosis + ' a las ' + d.duplicado.hora + '. ¿Guardarlo igualmente?')) {
            datos.duplicado_aceptado = true;
            const r2 = await fetch('api/medicamentos.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(datos)
            });
            const d2 = await r2.json().catch(() => ({
              error: 'El servidor no respondió correctamente. Inténtalo de nuevo.'
            }));
            if (d2.ok) {
              cerrarModal();
              mostrarAlertasInteraccion(d2.alertas_interaccion);
              return setTimeout(() => window.location.reload(), d2.alertas_interaccion ? 4500 : 600);
            }
            return mostrarError(d2.error || 'No se pudo guardar el medicamento.');
          }
          return;
        }
        return mostrarError(d.error || 'No se pudo guardar el medicamento.');
      }
      cerrarModal();
      mostrarAlertasInteraccion(d.alertas_interaccion);
      setTimeout(() => window.location.reload(), d.alertas_interaccion ? 4500 : 600);
    } catch (err) {
      mostrarError('No se pudo contactar con el servidor. Revisa tu conexión e inténtalo de nuevo.');
    }
  });

  function mostrarError(msg) {
    error.textContent = msg;
    error.hidden = false;
  }

  function mostrarAlertasInteraccion(alertas) {
    if (!alertas || alertas.length === 0) return;
    cuerpoInteracciones.innerHTML = alertas.map(a => `
      <div class="alerta-interaccion nivel-${a.nivel_riesgo}">
        <h4>
          <i class="bi bi-exclamation-triangle"></i>
          ${escapar(a.medicamento_a)} + ${escapar(a.medicamento_b)}
          <span class="badge-riesgo badge-riesgo-${a.nivel_riesgo}">${a.nivel_riesgo}</span>
        </h4>
        ${a.consecuencia_clinica ? `<div class="alerta-consecuencia">⚠ ${escapar(a.consecuencia_clinica)}</div>` : ''}
        <p style="font-size:13px;margin:6px 0;">${escapar(a.descripcion)}</p>
        ${a.recomendacion ? `<p class="alerta-recomendacion"><strong>Recomendación clínica:</strong> ${escapar(a.recomendacion)}</p>` : ''}
      </div>
    `).join('');
    modalInteracciones.hidden = false;
  }

  const escapar = (s) => String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  // Eliminar
  document.querySelectorAll('.btn-eliminar-med').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (!confirm('¿Estás seguro de eliminar este medicamento? Se cancelarán todas sus tomas futuras.')) return;
      const id = parseInt(btn.dataset.id, 10);
      btn.disabled = true;
      try {
        const r = await fetch('api/medicamentos.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ accion: 'eliminar', id_medicamento: id })
        });
        const d = await r.json().catch(() => ({
          error: 'El servidor no respondió correctamente. Inténtalo de nuevo.'
        }));
        if (d.ok) {
          window.location.reload();
        } else {
          btn.disabled = false;
          alert(d.error || 'No se pudo eliminar el medicamento.');
        }
      } catch (e) {
        btn.disabled = false;
        alert('No se pudo contactar con el servidor. Revisa tu conexión e inténtalo de nuevo.');
      }
    });
  });
})();
