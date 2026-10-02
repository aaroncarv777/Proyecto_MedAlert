const MedAlert = (function () {
  'use strict';

  function obtenerContenedorToast() {
    let cont = document.getElementById('toast-container');
    if (!cont) {
      cont = document.createElement('div');
      cont.id = 'toast-container';
      cont.className = 'toast-container';
      document.body.appendChild(cont);
    }
    return cont;
  }

  function toast(mensaje, tipo = 'info', duracion = 4000) {
    const cont = obtenerContenedorToast();
    const div = document.createElement('div');
    div.className = `toast is-${tipo}`;
    const iconos = {
      success: 'bi-check-circle-fill',
      error: 'bi-exclamation-triangle-fill',
      warning: 'bi-exclamation-circle-fill',
      info: 'bi-info-circle-fill',
    };
    div.innerHTML = `<i class="bi ${iconos[tipo] || iconos.info}"></i><div>${mensaje}</div>`;
    cont.appendChild(div);
    setTimeout(() => {
      div.style.opacity = '0';
      div.style.transform = 'translateX(50px)';
      setTimeout(() => div.remove(), 300);
    }, duracion);
  }

  function abrirModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.add('is-shown');
  }
  function cerrarModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.remove('is-shown');
  }
  function inicializarModales() {
    document.querySelectorAll('.modal-backdrop').forEach(bd => {
      bd.addEventListener('click', e => {
        if (e.target === bd) bd.classList.remove('is-shown');
      });
    });
    document.querySelectorAll('[data-modal-close]').forEach(b => {
      b.addEventListener('click', () => {
        const m = b.closest('.modal-backdrop');
        if (m) m.classList.remove('is-shown');
      });
    });
  }

  function inicializarSidebar() {
    const toggle = document.querySelector('.menu-toggle');
    const sidebar = document.querySelector('.sidebar');
    if (!toggle || !sidebar) return;
    let overlay = document.querySelector('.sidebar-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.className = 'sidebar-overlay';
      document.body.appendChild(overlay);
    }
    toggle.addEventListener('click', () => {
      sidebar.classList.toggle('is-open');
      overlay.classList.toggle('is-shown');
    });
    overlay.addEventListener('click', () => {
      sidebar.classList.remove('is-open');
      overlay.classList.remove('is-shown');
    });
  }

  async function api(url, opciones = {}) {
    const opc = Object.assign({
      method: 'GET',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin', 
    }, opciones);
    if (opc.body && typeof opc.body !== 'string') opc.body = JSON.stringify(opc.body);
    try {
      const resp = await fetch(url, opc);
      const datos = await resp.json().catch(() => ({}));

      if (resp.status === 401) {
        window.location.href = 'login.php?expirada=1';
        return { ok: false, status: 401, datos };
      }
      if (!resp.ok) {
        return { ok: false, status: resp.status, datos };
      }
      return { ok: true, status: resp.status, datos };
    } catch (e) {
      return { ok: false, status: 0, datos: { error: 'Sin conexión con el servidor.' } };
    }
  }

  function validarCorreoSimple(correo) {
    if (!correo) return false;
    const limpio = correo.trim().toLowerCase();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(limpio)) return false;
    return /@gmail\.com$/.test(limpio);
  }

  function errorCorreoNoGmail() {
    return 'Por ahora sólo aceptamos cuentas de Gmail. Usa una dirección que termine en @gmail.com';
  }

  function validarTelefono(tel) {
    return /^[0-9]{10}$/.test(tel.replace(/\s+/g, ''));
  }

  function validarDosis(dosis) {
    if (!dosis || !dosis.trim()) return { valido: false, error: 'La dosis es obligatoria.' };
    if (dosis.length > 80) return { valido: false, error: 'Máximo 80 caracteres.' };
    if (!/^[0-9a-zA-ZáéíóúÁÉÍÓÚñÑ\s\.,\-\/\(\)]+$/u.test(dosis)) {
      return { valido: false, error: 'Sólo números, letras y unidades comunes (ej. 500mg, 10ml).' };
    }
    return { valido: true };
  }

  function fuerzaContrasena(c) {
    let nivel = 0;
    if (!c) return 'none';
    if (c.length >= 8) nivel++;
    if (/[A-Z]/.test(c)) nivel++;
    if (/[0-9]/.test(c)) nivel++;
    if (/[^A-Za-z0-9]/.test(c) && c.length >= 12) nivel++;
    if (nivel === 0 || nivel === 1) return 'weak';
    if (nivel === 2) return 'medium';
    if (nivel === 3) return 'strong';
    return 'very-strong';
  }

  function pintarFuerzaContrasena(input, indicador) {
    if (!input || !indicador) return;
    input.addEventListener('input', () => {
      const f = fuerzaContrasena(input.value);
      indicador.classList.remove('is-weak', 'is-medium', 'is-strong', 'is-very-strong');
      if (f !== 'none') indicador.classList.add(`is-${f}`);
    });
  }

  function mostrarErrorCampo(input, mensaje) {
    input.classList.add('is-invalid');
    let err = input.parentElement.parentElement.querySelector('.form-error');
    if (!err) err = input.parentElement.querySelector('.form-error');
    if (err) {
      err.textContent = '';
      err.innerHTML = `<i class="bi bi-exclamation-circle-fill"></i><span>${mensaje}</span>`;
      err.classList.add('is-shown');
    }
  }

  function limpiarErrorCampo(input) {
    input.classList.remove('is-invalid');
    let err = input.parentElement.parentElement.querySelector('.form-error');
    if (!err) err = input.parentElement.querySelector('.form-error');
    if (err) err.classList.remove('is-shown');
  }

  function inicializarTogglePassword() {
    document.querySelectorAll('.toggle-password').forEach(btn => {
      btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('input[type="password"], input[type="text"]');
        if (!input) return;
        const oculto = input.type === 'password';
        input.type = oculto ? 'text' : 'password';
        btn.innerHTML = oculto
          ? '<i class="bi bi-eye-slash"></i>'
          : '<i class="bi bi-eye"></i>';
      });
    });
  }

  function confirmar(mensaje) {
    return new Promise(resolve => {
      const overlay = document.createElement('div');
      overlay.className = 'modal-backdrop is-shown';
      overlay.innerHTML = `
        <div class="modal" style="max-width: 380px;">
          <div class="modal-body" style="text-align:center;">
            <div style="width:56px; height:56px; background:var(--amber-50); color:var(--amber-500); border-radius:50%; margin:0 auto 16px; display:flex; align-items:center; justify-content:center; font-size:28px;">
              <i class="bi bi-question-circle"></i>
            </div>
            <p style="font-size:15px; color:var(--gray-800); margin-bottom:20px;">${mensaje}</p>
            <div style="display:flex; gap:10px; justify-content:center;">
              <button class="btn btn-outline" data-action="no">Cancelar</button>
              <button class="btn btn-danger" data-action="si">Confirmar</button>
            </div>
          </div>
        </div>`;
      document.body.appendChild(overlay);
      overlay.addEventListener('click', e => {
        if (e.target.dataset.action === 'si') { overlay.remove(); resolve(true); }
        else if (e.target.dataset.action === 'no' || e.target === overlay) {
          overlay.remove(); resolve(false);
        }
      });
    });
  }

  function formatearHora12(hora24) {
    const [h, m] = hora24.split(':').map(Number);
    const periodo = h >= 12 ? 'PM' : 'AM';
    const h12 = h === 0 ? 12 : (h > 12 ? h - 12 : h);
    return `${h12}:${String(m).padStart(2, '0')} ${periodo}`;
  }

  document.addEventListener('DOMContentLoaded', () => {
    inicializarSidebar();
    inicializarModales();
    inicializarTogglePassword();
  });

  return {
    toast, api, abrirModal, cerrarModal,
    confirmar, formatearHora12,
    validarCorreoSimple, errorCorreoNoGmail, validarTelefono, validarDosis,
    fuerzaContrasena, pintarFuerzaContrasena,
    mostrarErrorCampo, limpiarErrorCampo,
  };
})();
