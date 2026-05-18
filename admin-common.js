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
    localStorage.removeItem('adminToken');
  } catch (e) {}
}

function isCurrentUserAdmin() {
  try {
    var token = localStorage.getItem('apiSessionId') || '';
    if (token && token.indexOf('.') !== -1) {
      var body = token.split('.')[0];
      var padded = body.replace(/-/g, '+').replace(/_/g, '/');
      while (padded.length % 4) padded += '=';
      var json = atob(padded);
      var payload = JSON.parse(json);
      if (payload && payload.adm === true) return true;
      if (payload && String(payload.fid || '') === '13') return true;
    }
    if (localStorage.getItem('adminIsAdmin') === 'true') return true;
    return localStorage.getItem('adminFuncionarioId') === '13';
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
  var hasApiSession = !!localStorage.getItem('apiSessionId');
  var hasToken = !!localStorage.getItem('adminToken');
  if (!hasApiSession && !hasToken) {
    clearAdminSession();
    window.location.href = 'login.html';
    return false;
  }
  return true;
}

async function syncSessionRoleFromServer() {
  if (typeof API === 'undefined' || !API.getSessionInfo) return;
  try {
    var session = await API.getSessionInfo();
    if (!session || !session.logged_in) {
      clearAdminSession();
      window.location.href = 'login.html';
      return;
    }
    localStorage.setItem('adminLoggedIn', 'true');
    localStorage.setItem('adminFuncionarioId', String(session.funcionario_id || ''));
    localStorage.setItem('adminIsAdmin', session.is_admin ? 'true' : 'false');
    if (!enforcePageRole()) return;
    buildAdminSidebarNav();
    applyRoleVisibility();
    initSidebarNavActive();
  } catch (e) {
    if (e && e.status === 401) {
      clearAdminSession();
      window.location.href = 'login.html';
    }
  }
}

function enforcePageRole() {
  var scope = document.body.getAttribute('data-page-scope') || 'employee';
  var hasFuncionario = !!localStorage.getItem('adminFuncionarioId');
  // Só bloqueia quando temos evidência clara de não-admin.
  if (scope === 'admin' && hasFuncionario && localStorage.getItem('adminIsAdmin') === 'false') {
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

/** Menu lateral único — mesma ordem e links em todas as páginas do painel. */
var ADMIN_NAV_ITEMS = [
  { href: 'index.html', icon: '📦', label: 'Encomendas' },
  { href: 'clientes.html', icon: '👥', label: 'Clientes', adminOnly: true },
  { href: 'produtos.html', icon: '🍰', label: 'Produtos' },
  { href: 'promocoes.html', icon: '🎁', label: 'Promoções', adminOnly: true },
  { href: 'funcionarios.html', icon: '👔', label: 'Funcionários', adminOnly: true },
  { href: 'estatisticas.html', icon: '📊', label: 'Estatísticas', adminOnly: true },
  { href: 'despesas.html', icon: '💸', label: 'Despesas', adminOnly: true },
  { href: 'fornecedores.html', icon: '🏢', label: 'Fornecedores', adminOnly: true },
  { href: 'faturacao.html', icon: '🧾', label: 'Faturação', adminOnly: true },
  { href: 'producao.html', icon: '🏭', label: 'Produção' },
  { href: 'receitas.html', icon: '📋', label: 'Receitas', adminOnly: true },
  { href: 'materiais-stock.html', icon: '🧂', label: 'Materiais', adminOnly: true }
];

function buildAdminSidebarNav() {
  var nav = document.querySelector('.sidebar-nav');
  if (!nav) return;

  nav.innerHTML = '';
  ADMIN_NAV_ITEMS.forEach(function (item) {
    var a = document.createElement('a');
    a.href = item.href;
    a.className = 'nav-item';
    if (item.adminOnly) {
      a.setAttribute('data-admin-only', 'true');
    }
    a.innerHTML =
      '<span class="icon">' +
      item.icon +
      '</span><span class="nav-label">' +
      item.label +
      '</span>';
    nav.appendChild(a);
  });
}

function initSidebarNavActive() {
  var path = window.location.pathname || '';
  var file = path.split('/').pop() || 'index.html';
  if (!file || file.indexOf('.html') === -1) file = 'index.html';

  document.querySelectorAll('.sidebar-nav .nav-item').forEach(function (a) {
    var href = a.getAttribute('href') || '';
    var hrefFile = href.split('#')[0].split('/').pop() || '';
    if (hrefFile === file) {
      a.classList.add('active');
      a.setAttribute('aria-current', 'page');
    } else {
      a.classList.remove('active');
      a.removeAttribute('aria-current');
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
  buildAdminSidebarNav();
  applyRoleVisibility();
  initSidebarNavActive();
  syncSessionRoleFromServer();
  bindAdminLogout('logoutBtn');
  initAdminMobileNav();
  return true;
}
