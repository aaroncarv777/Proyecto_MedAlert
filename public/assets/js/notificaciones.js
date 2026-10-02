/**
 * MedAlert · Sprint 2 — Centro de notificaciones (HU-12 + HU-13)
 * ----------------------------------------------------------------
 * Maneja la campanita del header:
 *   · Polling cada 30s para actualizar el badge.
 *   · Click → carga las últimas 30 notificaciones y abre el panel.
 *   · Click en item → marca como leída.
 *   · Botón "Marcar todas como leídas".
 *   · Cada 60s dispara ejecutar_pendientes (simulador de cron):
 *     procesa correos, SMS Twilio y avisos in-app pendientes.
 */
(function () {
  'use strict';

  const btn       = document.getElementById('btn-campanita');
  const panel     = document.getElementById('panel-notificaciones');
  const lista     = document.getElementById('notif-lista');
  const badge     = document.getElementById('badge-no-leidas');
  const btnTodas  = document.getElementById('btn-marcar-todas');
  if (!btn || !panel || !lista || !badge) return;

  let abierto = false;

  const fmtFecha = (iso) => {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return iso;
    const ahora = new Date();
    const dif = Math.round((ahora - d) / 60000);
    if (dif < 1)    return 'hace un momento';
    if (dif < 60)   return 'hace ' + dif + ' min';
    if (dif < 1440) return 'hace ' + Math.floor(dif / 60) + ' h';
    return d.toLocaleDateString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  };

  const iconoPorTipo = (tipo) => ({
    'temprano':         'bi-bell',
    'recordatorio':     'bi-alarm',
    'tardio':           'bi-exclamation-circle',
    'stock_bajo':       'bi-box-seam',
    'interaccion':      'bi-exclamation-triangle',
    'cedula_aprobada':  'bi-patch-check',
    'cedula_rechazada': 'bi-patch-exclamation',
    'generico':         'bi-info-circle'
  }[tipo] || 'bi-bell');

  async function actualizarContador() {
    try {
      const r = await fetch('api/notificaciones.php?accion=contar_no_leidas');
      const d = await r.json();
      if (!d.ok) return;
      pintarBadge(d.no_leidas);
    } catch (e) { /* silencioso */ }
  }

  function pintarBadge(n) {
    const num = parseInt(n, 10) || 0;
    if (num > 0) {
      badge.textContent = num > 99 ? '99+' : num;
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  async function cargarLista() {
    lista.innerHTML = '<div class="notif-vacio">Cargando…</div>';
    try {
      const r = await fetch('api/notificaciones.php?accion=pendientes');
      const d = await r.json();
      if (!d.ok) {
        lista.innerHTML = '<div class="notif-vacio">No se pudieron cargar las notificaciones.</div>';
        return;
      }
      pintarBadge(d.no_leidas);
      pintarLista(d.notificaciones || []);
    } catch (e) {
      lista.innerHTML = '<div class="notif-vacio">Error de conexión.</div>';
    }
  }

  function pintarLista(items) {
    if (items.length === 0) {
      lista.innerHTML = '<div class="notif-vacio">No tienes notificaciones por ahora.</div>';
      return;
    }
    lista.innerHTML = items.map(n => `
      <div class="notif-item ${n.leida == 1 || n.leida === true ? 'is-leida' : ''}"
           data-id="${n.id_notificacion}" data-leida="${n.leida == 1 ? '1' : '0'}">
        <div class="notif-icono notif-icono-${n.tipo_aviso}">
          <i class="bi ${iconoPorTipo(n.tipo_aviso)}"></i>
        </div>
        <div class="notif-cuerpo">
          <div class="notif-titulo">${escapar(n.titulo)}</div>
          <div class="notif-mensaje">${escapar(n.mensaje || '')}</div>
          <div class="notif-fecha">${fmtFecha(n.fecha_creacion)}</div>
        </div>
      </div>
    `).join('');

    lista.querySelectorAll('.notif-item').forEach(el => {
      el.addEventListener('click', () => marcarLeida(el));
    });
  }

  const escapar = (s) => String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

  async function marcarLeida(el) {
    if (el.dataset.leida === '1') return;
    const id = el.dataset.id;
    try {
      const r = await fetch('api/notificaciones.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accion: 'marcar_leida', id_notificacion: parseInt(id, 10) })
      });
      const d = await r.json();
      if (d.ok) {
        el.classList.add('is-leida');
        el.dataset.leida = '1';
        actualizarContador();
      }
    } catch (e) { /* silencioso */ }
  }

  async function marcarTodas() {
    if (!confirm('¿Marcar todas las notificaciones como leídas?')) return;
    try {
      const r = await fetch('api/notificaciones.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accion: 'marcar_todas_leidas' })
      });
      const d = await r.json();
      if (d.ok) {
        lista.querySelectorAll('.notif-item').forEach(el => {
          el.classList.add('is-leida');
          el.dataset.leida = '1';
        });
        pintarBadge(0);
      }
    } catch (e) { /* silencioso */ }
  }

  /** Dispara el procesamiento de pendientes (cron-like).
   *  Envía correos por SMTP, SMS por Twilio y completa avisos in-app.
   *  Cliente solicitó esto explícitamente en su review del Sprint 2.
   */
  async function ejecutarCron() {
    try {
      await fetch('api/notificaciones.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accion: 'ejecutar_pendientes' })
      });
      actualizarContador();
    } catch (e) { /* silencioso */ }
  }

  // ─── Eventos ───────────────────────────────────────────────────────
  btn.addEventListener('click', (ev) => {
    ev.stopPropagation();
    abierto = !abierto;
    panel.hidden = !abierto;
    if (abierto) cargarLista();
  });

  document.addEventListener('click', (ev) => {
    if (!abierto) return;
    if (panel.contains(ev.target) || btn.contains(ev.target)) return;
    abierto = false;
    panel.hidden = true;
  });

  if (btnTodas) {
    btnTodas.addEventListener('click', (ev) => {
      ev.stopPropagation();
      marcarTodas();
    });
  }

  // Polling
  actualizarContador();
  setInterval(actualizarContador, 30000);
  // Cron del lado del cliente — Aaron lo armó así porque XAMPP local
  // no garantiza un cron del sistema operativo. Ejecuta procesarPendientes()
  // cada 60 segundos para simular el aviso escalonado.
  ejecutarCron();
  setInterval(ejecutarCron, 60000);
})();
