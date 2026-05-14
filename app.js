// Painel de encomendas — Operação (fila) + Histórico (entregue / cancelada)
let allEncomendas = [];
const clientesCache = {};
let currentEncomendasPage = 1;
let currentEncomendasPageHistorico = 1;
const ENCOMENDAS_PER_PAGE = 12;
let currentUserIsAdmin = false;
let currentUserCanManageOrders = false;
let filteredOperacao = [];
let filteredHistorico = [];

const ESTADOS_OPERACAO = ['pendente', 'aceite', 'em_preparacao', 'pronta'];
const ESTADOS_HISTORICO = ['entregue', 'cancelada'];
const TODOS_ESTADOS = [...ESTADOS_OPERACAO, ...ESTADOS_HISTORICO];

let currentDashView = 'operacao';

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  } else if (typeof requireAdminPageAuth === 'function' && !requireAdminPageAuth()) return;
  currentUserIsAdmin = typeof isCurrentUserAdmin === 'function' ? isCurrentUserAdmin() : false;
  currentUserCanManageOrders = currentUserIsAdmin || !!localStorage.getItem('adminFuncionarioId');

  fillEstadoSelect(document.getElementById('statusFilter'), 'operacao');
  fillEstadoSelect(document.getElementById('statusFilterHistorico'), 'historico');
  fillEstadoSelect(document.getElementById('newStatusSelect'), 'modal');

  loadEncomendas();
  setupEventListeners();

  setInterval(loadEncomendas, 30000);
});

function fillEstadoSelect(selectEl, mode) {
  if (!selectEl) return;
  var opts = [];
  if (mode === 'operacao') {
    opts.push(['', 'Todos (em curso)']);
    ESTADOS_OPERACAO.forEach((s) => opts.push([s, formatStatus(s)]));
  } else if (mode === 'historico') {
    opts.push(['', 'Todas (histórico)']);
    ESTADOS_HISTORICO.forEach((s) => opts.push([s, formatStatus(s)]));
  } else {
    TODOS_ESTADOS.forEach((s) => opts.push([s, formatStatus(s)]));
  }
  selectEl.innerHTML = opts
    .map((o) => '<option value="' + escapeAttr(o[0]) + '">' + escapeHtml(o[1]) + '</option>')
    .join('');
}

function escapeAttr(s) {
  return String(s)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;');
}

function escapeHtml(s) {
  var d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
}

function setupEventListeners() {
  document.getElementById('refreshBtn').addEventListener('click', loadEncomendas);

  var exportBtn = document.getElementById('exportCsvBtn');
  if (exportBtn) exportBtn.addEventListener('click', exportEncomendasCsv);

  document.getElementById('statusFilter').addEventListener('change', () => applyFilters('operacao', true));
  document.getElementById('searchInput').addEventListener('input', () => applyFilters('operacao', true));

  var sfh = document.getElementById('statusFilterHistorico');
  var sih = document.getElementById('searchInputHistorico');
  if (sfh) sfh.addEventListener('change', () => applyFilters('historico', true));
  if (sih) sih.addEventListener('input', () => applyFilters('historico', true));

  document.querySelectorAll('.dash-tab').forEach((tab) => {
    tab.addEventListener('click', () => setDashView(tab.getAttribute('data-dash-view')));
  });

  var modal = document.getElementById('statusModal');
  var closeBtn = modal.querySelector('.close');
  var cancelBtn = document.getElementById('cancelStatusBtn');

  closeBtn.addEventListener('click', () => modal.classList.remove('active'));
  cancelBtn.addEventListener('click', () => modal.classList.remove('active'));

  window.addEventListener('click', (e) => {
    if (e.target === modal) modal.classList.remove('active');
  });

  document.getElementById('confirmStatusBtn').addEventListener('click', updateOrderStatus);
}

function setDashView(view) {
  currentDashView = view === 'historico' ? 'historico' : 'operacao';
  document.querySelectorAll('.dash-tab').forEach((t) => {
    var on = t.getAttribute('data-dash-view') === currentDashView;
    t.classList.toggle('active', on);
    t.setAttribute('aria-selected', on ? 'true' : 'false');
  });
  var po = document.getElementById('dashPanelOperacao');
  var ph = document.getElementById('dashPanelHistorico');
  if (po) {
    po.classList.toggle('dash-panel--hidden', currentDashView !== 'operacao');
    po.hidden = currentDashView !== 'operacao';
  }
  if (ph) {
    ph.classList.toggle('dash-panel--hidden', currentDashView !== 'historico');
    ph.hidden = currentDashView !== 'historico';
  }
}

function listaBasePorVista(view) {
  return allEncomendas.filter((e) => {
    var st = e.estado || '';
    return view === 'operacao' ? ESTADOS_OPERACAO.indexOf(st) !== -1 : ESTADOS_HISTORICO.indexOf(st) !== -1;
  });
}

function aplicarPesquisaEStatus(list, statusVal, searchVal) {
  var out = list;
  if (statusVal) {
    out = out.filter((e) => e.estado === statusVal);
  }
  if (searchVal) {
    out = out.filter((e) => {
      var cliente = clientesCache[e.cliente_id] || {};
      return (
        e.encomenda_id.toString().includes(searchVal) ||
        (cliente.nome && cliente.nome.toLowerCase().includes(searchVal)) ||
        (cliente.email && cliente.email.toLowerCase().includes(searchVal)) ||
        (e.cliente_nome && String(e.cliente_nome).toLowerCase().includes(searchVal)) ||
        (e.cliente_email && String(e.cliente_email).toLowerCase().includes(searchVal))
      );
    });
  }
  return out;
}

function applyFilters(view, resetPage) {
  if (resetPage === undefined) resetPage = true;
  if (view === 'operacao') {
    var st = document.getElementById('statusFilter').value;
    var q = document.getElementById('searchInput').value.toLowerCase().trim();
    filteredOperacao = aplicarPesquisaEStatus(listaBasePorVista('operacao'), st, q);
    if (resetPage) currentEncomendasPage = 1;
    renderEncomendas(filteredOperacao, 'operacao');
  } else {
    var sth = document.getElementById('statusFilterHistorico').value;
    var qh = (document.getElementById('searchInputHistorico') || { value: '' }).value.toLowerCase().trim();
    filteredHistorico = aplicarPesquisaEStatus(listaBasePorVista('historico'), sth, qh);
    if (resetPage) currentEncomendasPageHistorico = 1;
    renderEncomendas(filteredHistorico, 'historico');
  }
}

function applyAllFilters() {
  applyFilters('operacao', false);
  applyFilters('historico', false);
}

async function loadEncomendas() {
  var tbOp = document.getElementById('ordersTableBody');
  var tbHi = document.getElementById('ordersTableBodyHistorico');
  var loading = '<tr><td colspan="6" class="loading">Carregando encomendas…</td></tr>';
  if (tbOp) tbOp.innerHTML = loading;
  if (tbHi) tbHi.innerHTML = loading;

  try {
    allEncomendas = await API.getAllEncomendas();
    await loadClientes();
    currentEncomendasPage = 1;
    currentEncomendasPageHistorico = 1;
    applyFilters('operacao', false);
    applyFilters('historico', false);
    updateStats(allEncomendas);
  } catch (error) {
    var err =
      '<tr><td colspan="6" class="error-container">Erro ao carregar encomendas: ' +
      escapeHtml(error.message) +
      '</td></tr>';
    if (tbOp) tbOp.innerHTML = err;
    if (tbHi) tbHi.innerHTML = err;
    if (typeof showToast === 'function') showToast(error.message, 'error');
  }
}

async function loadClientes() {
  try {
    var pessoas = await API.getPessoas();
    Object.keys(clientesCache).forEach((k) => delete clientesCache[k]);
    pessoas.forEach(function (pessoa) {
      clientesCache[pessoa.pessoa_id] = pessoa;
    });
  } catch (error) {
    console.error('Error loading clientes:', error);
  }
}

function exportEncomendasCsv() {
  var rows = currentDashView === 'historico' ? filteredHistorico.slice() : filteredOperacao.slice();
  var headers = ['ID', 'Cliente', 'Email', 'Total', 'Estado', 'Data'];
  var lines = [headers.join(';')];
  rows.forEach((encomenda) => {
    var cliente = clientesCache[encomenda.cliente_id] || {};
    var nome = (encomenda.cliente_nome || cliente.nome || '').replace(/"/g, '""');
    var email = (encomenda.cliente_email || cliente.email || '').replace(/"/g, '""');
    lines.push(
      [
        encomenda.encomenda_id,
        '"' + nome + '"',
        '"' + email + '"',
        (encomenda.total || 0).toString().replace('.', ','),
        encomenda.estado || '',
        (encomenda.data_criacao || '').toString()
      ].join(';')
    );
  });

  var blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'encomendas_sweet_cakes_' + currentDashView + '.csv';
  a.click();
  URL.revokeObjectURL(a.href);
  if (typeof showToast === 'function') showToast('CSV exportado.', 'success');
}

function renderEncomendas(encomendas, view) {
  var tbody =
    view === 'historico'
      ? document.getElementById('ordersTableBodyHistorico')
      : document.getElementById('ordersTableBody');
  var pagination =
    view === 'historico'
      ? document.getElementById('encomendasPaginationHistorico')
      : document.getElementById('encomendasPagination');
  var page = view === 'historico' ? currentEncomendasPageHistorico : currentEncomendasPage;
  var handler = view === 'historico' ? 'goToEncomendasPageHistorico' : 'goToEncomendasPage';

  if (!tbody) return;

  if (encomendas.length === 0) {
    tbody.innerHTML =
      '<tr><td colspan="6" class="loading table-empty-msg">Nenhuma encomenda nesta vista.</td></tr>';
    if (pagination) pagination.innerHTML = '';
    return;
  }

  var totalPages = Math.max(1, Math.ceil(encomendas.length / ENCOMENDAS_PER_PAGE));
  if (page > totalPages) {
    if (view === 'historico') currentEncomendasPageHistorico = totalPages;
    else currentEncomendasPage = totalPages;
    page = totalPages;
  }
  var start = (page - 1) * ENCOMENDAS_PER_PAGE;
  var pageItems = encomendas.slice(start, start + ENCOMENDAS_PER_PAGE);

  tbody.innerHTML = pageItems
    .map((encomenda) => {
      var cliente = clientesCache[encomenda.cliente_id] || {
        nome: encomenda.cliente_nome || 'Cliente #' + encomenda.cliente_id,
        email: encomenda.cliente_email || 'N/A'
      };
      var statusClass = encomenda.estado ? encomenda.estado.replace('_', '-') : 'pendente';
      var statusText = formatStatus(encomenda.estado);
      var est = (encomenda.estado || 'pendente').replace(/'/g, "\\'");

      return (
        '<tr>' +
        '<td><strong>#' +
        encomenda.encomenda_id +
        '</strong></td>' +
        '<td><div class="cell-client-name">' +
        escapeHtml(String(encomenda.cliente_nome || cliente.nome || 'N/A')) +
        '</div><small class="cell-client-email">' +
        escapeHtml(String(encomenda.cliente_email || cliente.email || '')) +
        '</small></td>' +
        '<td><strong>€' +
        parseFloat(encomenda.total || 0).toFixed(2) +
        '</strong></td>' +
        '<td><span class="status-badge ' +
        statusClass +
        '">' +
        statusText +
        '</span></td>' +
        '<td class="cell-date">' +
        formatDate(encomenda.data_criacao || new Date().toISOString()) +
        '</td>' +
        '<td class="actions-cell">' +
        '<a href="encomenda.html?id=' +
        encomenda.encomenda_id +
        '" class="action-btn view">Ver</a>' +
        (currentUserCanManageOrders
          ? '<button type="button" class="action-btn edit" data-eid="' +
            encomenda.encomenda_id +
            '" data-estado="' +
            est +
            '">Estado</button>'
          : '') +
        '</td>' +
        '</tr>'
      );
    })
    .join('');

  renderPagination(pagination, page, totalPages, handler);

  if (currentUserCanManageOrders) {
    tbody.querySelectorAll('.action-btn.edit').forEach((btn) => {
      btn.addEventListener('click', () => {
        openStatusModal(parseInt(btn.getAttribute('data-eid'), 10), btn.getAttribute('data-estado'));
      });
    });
  }
}

function formatStatus(status) {
  var statusMap = {
    pendente: 'Pendente',
    aceite: 'Aceite',
    em_preparacao: 'Em preparação',
    pronta: 'Pronta',
    entregue: 'Entregue',
    cancelada: 'Cancelada'
  };
  return statusMap[status] || status;
}

function formatDate(dateString) {
  if (!dateString) return 'N/A';
  var date = new Date(dateString);
  return date.toLocaleDateString('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

function renderPagination(container, current, total, handlerName) {
  if (!container) return;
  var pages = [];
  var start = Math.max(1, current - 2);
  var end = Math.min(total, current + 2);
  for (var p = start; p <= end; p++) pages.push(p);
  container.innerHTML =
    '<span class="pagination-info">Página ' +
    current +
    ' de ' +
    total +
    '</span>' +
    '<button type="button" class="pagination-btn" ' +
    (current <= 1 ? 'disabled' : '') +
    ' onclick="' +
    handlerName +
    '(' +
    (current - 1) +
    ')">‹</button>' +
    pages
      .map(function (page) {
        return (
          '<button type="button" class="pagination-btn ' +
          (page === current ? 'active' : '') +
          '" onclick="' +
          handlerName +
          '(' +
          page +
          ')">' +
          page +
          '</button>'
        );
      })
      .join('') +
    '<button type="button" class="pagination-btn" ' +
    (current >= total ? 'disabled' : '') +
    ' onclick="' +
    handlerName +
    '(' +
    (current + 1) +
    ')">›</button>';
}

function updateStats(encomendas) {
  var stats = {
    pendente: 0,
    aceite: 0,
    em_preparacao: 0,
    pronta: 0,
    entregue: 0,
    cancelada: 0
  };

  encomendas.forEach((e) => {
    if (Object.prototype.hasOwnProperty.call(stats, e.estado)) {
      stats[e.estado]++;
    }
  });

  var set = (id, v) => {
    var el = document.getElementById(id);
    if (el) el.textContent = v;
  };
  set('pendingCount', stats.pendente);
  set('acceptedCount', stats.aceite);
  set('preparingCount', stats.em_preparacao);
  set('readyCount', stats.pronta);
  set('deliveredCount', stats.entregue);
  set('cancelledCount', stats.cancelada);
}

function openStatusModal(encomendaId, currentStatus) {
  var modal = document.getElementById('statusModal');
  document.getElementById('modalOrderId').textContent = encomendaId;
  var sel = document.getElementById('newStatusSelect');
  sel.value = currentStatus;
  modal.classList.add('active');
  modal.dataset.encomendaId = encomendaId;
}

async function updateOrderStatus() {
  var modal = document.getElementById('statusModal');
  var encomendaId = modal.dataset.encomendaId;
  var newStatus = document.getElementById('newStatusSelect').value;

  try {
    var encomenda = await API.getEncomenda(encomendaId);

    var updateData = {
      cliente_id: encomenda.cliente_id,
      estado: newStatus,
      total: encomenda.total
    };

    var response = await API.updateEncomenda(encomendaId, updateData);

    modal.classList.remove('active');
    loadEncomendas();

    if (typeof showToastsForEncomendaEmail === 'function') {
      showToastsForEncomendaEmail(response, encomendaId, response && response.estado_novo);
    } else if (typeof showToast === 'function') {
      showToast(
        'Encomenda #' +
          encomendaId +
          ' atualizada' +
          (response && response.estado_novo ? ' → ' + response.estado_novo : '') +
          '.',
        'success'
      );
    }
  } catch (error) {
    console.error('Erro:', error);
    var msg = error.message || 'Erro ao atualizar';
    if (typeof showToast === 'function') showToast(msg, 'error');
    else alert(msg);
  }
}

window.openStatusModal = openStatusModal;
window.goToEncomendasPage = function (page) {
  currentEncomendasPage = Math.max(1, page);
  applyFilters('operacao', false);
};
window.goToEncomendasPageHistorico = function (page) {
  currentEncomendasPageHistorico = Math.max(1, page);
  applyFilters('historico', false);
};
