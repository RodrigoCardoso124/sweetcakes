/**
 * Sessão admin, toasts, navegação ativa e menu mobile.
 */
function clearAdminSession() {
  try {
    localStorage.removeItem('adminLoggedIn');
    localStorage.removeItem('adminEmail');
    localStorage.removeItem('adminNome');
    localStorage.removeItem('adminFuncionarioId');
    localStorage.removeItem('adminIsAdmin');
    localStorage.removeItem('apiSessionId');
  } catch (e) {}
}

function isCurrentUserAdmin() {
  try {
    return localStorage.getItem('adminIsAdmin') === 'true';
  } catch (e) {
    return false;
  }
}

/**
 * Após PUT encomendas/:id — mostra se o email ao cliente foi enviado (útil em alojamento como InfinityFree, sem logs locais).
 */
function showToastsForEncomendaEmail(response, encomendaId, estadoNovo) {
  var main =
    'Encomenda #' +
    encomendaId +
    ' atualizada' +
    (estadoNovo ? ' → ' + estadoNovo : '') +
    '.';
  showToast(main, 'success');
  var n = response && response.notificacao_email;
  if (!n) return;
  if (n.enviado) {
    showToast('Email ao cliente: enviado.', 'success');
    return;
  }
  var motivo = n.motivo || '';
  var msgs = {
    smtp_nao_configurado:
      'Email ao cliente: não enviado.\nNo servidor (ex.: InfinityFree) cria/edita via FTP: src/config/mail_config.local.php com enabled=true e dados SMTP.',
    erro_smtp:
      'Email ao cliente: falhou (SMTP).' +
      (n.erro_detalhe ? '\n' + n.erro_detalhe : '\n(Muitos alojamentos gratuitos bloqueiam porta 587/465 — vê a política do teu host.)'),
    cliente_sem_email: 'Email ao cliente: não enviado — o cliente não tem email na base de dados.',
    email_destino_invalido: 'Email ao cliente: não enviado — email do cliente inválido.'
  };
  var extra = msgs[motivo] || 'Email ao cliente: não enviado (' + motivo + ').';
  showToast(extra, 'warning');
}

function showToast(message, type) {
  type = type || 'info';
  var existing = document.getElementById('admin-toast-root');
  if (!existing) {
    existing = document.createElement('div');
    existing.id = 'admin-toast-root';
    existing.setAttribute('aria-live', 'polite');
    document.body.appendChild(existing);
  }
  var t = document.createElement('div');
  t.className = 'admin-toast admin-toast--' + type;
  t.textContent = message;
  existing.appendChild(t);
  setTimeout(function () {
    t.classList.add('admin-toast--out');
    setTimeout(function () {
      t.remove();
    }, 300);
  }, 3800);
}

function requireAdminPageAuth() {
  if (localStorage.getItem('adminLoggedIn') !== 'true') {
    window.location.href = 'login.html';
    return false;
  }
  if (!localStorage.getItem('adminFuncionarioId')) {
    clearAdminSession();
    window.location.href = 'login.html';
    return false;
  }
  return true;
}

function enforcePageRole() {
  var scope = document.body.getAttribute('data-page-scope') || 'employee';
  if (scope === 'admin' && !isCurrentUserAdmin()) {
    window.location.href = 'index.html';
    return false;
  }
  return true;
}

function applyRoleVisibility() {
  var isAdmin = isCurrentUserAdmin();
  document.querySelectorAll('[data-admin-only="true"]').forEach(function (el) {
    if (!isAdmin) el.style.display = 'none';
  });
}

async function bindAdminLogout(buttonId) {
  var btn = document.getElementById(buttonId || 'logoutBtn');
  if (!btn) return;
  btn.addEventListener('click', async function () {
    try {
      if (typeof API !== 'undefined' && API.logout) await API.logout();
    } catch (e) {}
    clearAdminSession();
    window.location.href = 'login.html';
  });
}

function initSidebarNavActive() {
  var path = window.location.pathname || '';
  var file = path.split('/').pop() || 'index.html';
  document.querySelectorAll('.sidebar-nav .nav-item').forEach(function (a) {
    var href = (a.getAttribute('href') || '').split('/').pop();
    if (href === file) {
      a.classList.add('active');
    } else {
      a.classList.remove('active');
    }
  });
}

function initAdminMobileNav() {
  var sidebar = document.querySelector('.sidebar');
  var toggle = document.querySelector('.sidebar-toggle');
  if (!sidebar || !toggle) return;

  var backdrop = document.createElement('div');
  backdrop.className = 'sidebar-backdrop';
  backdrop.setAttribute('aria-hidden', 'true');
  document.body.appendChild(backdrop);

  function close() {
    sidebar.classList.remove('open');
    backdrop.classList.remove('visible');
    toggle.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }

  function open() {
    sidebar.classList.add('open');
    backdrop.classList.add('visible');
    toggle.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  toggle.addEventListener('click', function () {
    if (sidebar.classList.contains('open')) close();
    else open();
  });
  backdrop.addEventListener('click', close);
  window.addEventListener('resize', function () {
    if (window.innerWidth > 900) close();
  });
}

function initAdminShell() {
  if (!requireAdminPageAuth()) return false;
  if (!enforcePageRole()) return false;
  applyRoleVisibility();
  initSidebarNavActive();
  bindAdminLogout('logoutBtn');
  initAdminMobileNav();
  return true;
}
