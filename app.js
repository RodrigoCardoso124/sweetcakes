// Painel de encomendas
let allEncomendas = [];
let clientesCache = {};
let currentEncomendasPage = 1;
const ENCOMENDAS_PER_PAGE = 10;
let encomendasFiltradas = [];

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  } else if (typeof requireAdminPageAuth === 'function' && !requireAdminPageAuth()) return;

  loadEncomendas();
  setupEventListeners();

  setInterval(loadEncomendas, 30000);
});

function setupEventListeners() {
  document.getElementById('refreshBtn').addEventListener('click', loadEncomendas);

  var exportBtn = document.getElementById('exportCsvBtn');
  if (exportBtn) exportBtn.addEventListener('click', exportEncomendasCsv);

  document.getElementById('statusFilter').addEventListener('change', filterEncomendas);
  document.getElementById('searchInput').addEventListener('input', filterEncomendas);

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

async function loadEncomendas() {
  var tbody = document.getElementById('ordersTableBody');
  tbody.innerHTML = '<tr><td colspan="6" class="loading">Carregando encomendas...</td></tr>';

  try {
    allEncomendas = await API.getAllEncomendas();
    await loadClientes();
    encomendasFiltradas = allEncomendas.slice();
    renderEncomendas(encomendasFiltradas);
    updateStats(allEncomendas);
  } catch (error) {
    tbody.innerHTML =
      '<tr><td colspan="6" class="error-container">Erro ao carregar encomendas: ' +
      error.message +
      '</td></tr>';
    if (typeof showToast === 'function') showToast(error.message, 'error');
  }
}

async function loadClientes() {
  try {
    var pessoas = await API.getPessoas();
    pessoas.forEach(function (pessoa) {
      clientesCache[pessoa.pessoa_id] = pessoa;
    });
  } catch (error) {
    console.error('Error loading clientes:', error);
  }
}

function exportEncomendasCsv() {
  var statusFilter = document.getElementById('statusFilter').value;
  var searchInput = document.getElementById('searchInput').value.toLowerCase();
  var rows = allEncomendas.slice();

  if (statusFilter) rows = rows.filter((e) => e.estado === statusFilter);
  if (searchInput) {
    rows = rows.filter((e) => {
      var cliente = clientesCache[e.cliente_id] || {};
      return (
        e.encomenda_id.toString().includes(searchInput) ||
        (cliente.nome && cliente.nome.toLowerCase().includes(searchInput)) ||
        (cliente.email && cliente.email.toLowerCase().includes(searchInput))
      );
    });
  }

  var headers = ['ID', 'Cliente', 'Email', 'Total', 'Estado', 'Data'];
  var lines = [headers.join(';')];
  rows.forEach((encomenda) => {
    var cliente = clientesCache[encomenda.cliente_id] || {};
    var nome = (cliente.nome || '').replace(/"/g, '""');
    var email = (cliente.email || '').replace(/"/g, '""');
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
  a.download = 'encomendas_sweet_cakes.csv';
  a.click();
  URL.revokeObjectURL(a.href);
  if (typeof showToast === 'function') showToast('CSV exportado.', 'success');
}

function renderEncomendas(encomendas) {
  var tbody = document.getElementById('ordersTableBody');
  var pagination = document.getElementById('encomendasPagination');

  if (encomendas.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" class="loading">Nenhuma encomenda encontrada</td></tr>';
    if (pagination) pagination.innerHTML = '';
    return;
  }

  var totalPages = Math.max(1, Math.ceil(encomendas.length / ENCOMENDAS_PER_PAGE));
  if (currentEncomendasPage > totalPages) currentEncomendasPage = totalPages;
  var start = (currentEncomendasPage - 1) * ENCOMENDAS_PER_PAGE;
  var pageItems = encomendas.slice(start, start + ENCOMENDAS_PER_PAGE);

  tbody.innerHTML = pageItems
    .map((encomenda) => {
      var cliente = clientesCache[encomenda.cliente_id] || {
        nome: 'Cliente #' + encomenda.cliente_id,
        email: 'N/A'
      };
      var statusClass = encomenda.estado ? encomenda.estado.replace('_', '-') : 'pendente';
      var statusText = formatStatus(encomenda.estado);
      var est = (encomenda.estado || 'pendente').replace(/'/g, "\\'");

      return (
        '<tr>' +
        '<td><strong>#' +
        encomenda.encomenda_id +
        '</strong></td>' +
        '<td><div>' +
        (cliente.nome || 'N/A') +
        '</div><small style="color: #666;">' +
        (cliente.email || '') +
        '</small></td>' +
        '<td><strong>€' +
        parseFloat(encomenda.total || 0).toFixed(2) +
        '</strong></td>' +
        '<td><span class="status-badge ' +
        statusClass +
        '">' +
        statusText +
        '</span></td>' +
        '<td>' +
        formatDate(encomenda.data_criacao || new Date().toISOString()) +
        '</td>' +
        '<td class="actions-cell">' +
        '<a href="encomenda.html?id=' +
        encomenda.encomenda_id +
        '" class="action-btn view">Ver</a>' +
        '<button type="button" class="action-btn edit" data-eid="' +
        encomenda.encomenda_id +
        '" data-estado="' +
        est +
        '">Alterar</button>' +
        '</td>' +
        '</tr>'
      );
    })
    .join('');

  renderPagination(pagination, currentEncomendasPage, totalPages, 'goToEncomendasPage');

  tbody.querySelectorAll('.action-btn.edit').forEach((btn) => {
    btn.addEventListener('click', () => {
      openStatusModal(parseInt(btn.getAttribute('data-eid'), 10), btn.getAttribute('data-estado'));
    });
  });
}

function formatStatus(status) {
  var statusMap = {
    pendente: 'Pendente',
    aceite: 'Aceite',
    em_preparacao: 'Em Preparação',
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

function filterEncomendas(resetPage) {
  if (resetPage === undefined) resetPage = true;
  var statusFilter = document.getElementById('statusFilter').value;
  var searchInput = document.getElementById('searchInput').value.toLowerCase();

  var filtered = allEncomendas;

  if (statusFilter) {
    filtered = filtered.filter((e) => e.estado === statusFilter);
  }

  if (searchInput) {
    filtered = filtered.filter((e) => {
      var cliente = clientesCache[e.cliente_id] || {};
      return (
        e.encomenda_id.toString().includes(searchInput) ||
        (cliente.nome && cliente.nome.toLowerCase().includes(searchInput)) ||
        (cliente.email && cliente.email.toLowerCase().includes(searchInput))
      );
    });
  }

  if (resetPage) currentEncomendasPage = 1;
  encomendasFiltradas = filtered;
  renderEncomendas(encomendasFiltradas);
}
function renderPagination(container, current, total, handlerName) {
  if (!container) return;
  var pages = [];
  var start = Math.max(1, current - 2);
  var end = Math.min(total, current + 2);
  for (var p = start; p <= end; p++) pages.push(p);
  container.innerHTML =
    '<span class="pagination-info">Página ' + current + ' de ' + total + '</span>' +
    '<button class="pagination-btn" ' + (current <= 1 ? 'disabled' : '') + ' onclick="' + handlerName + '(' + (current - 1) + ')">‹</button>' +
    pages.map(function(page) {
      return '<button class="pagination-btn ' + (page === current ? 'active' : '') + '" onclick="' + handlerName + '(' + page + ')">' + page + '</button>';
    }).join('') +
    '<button class="pagination-btn" ' + (current >= total ? 'disabled' : '') + ' onclick="' + handlerName + '(' + (current + 1) + ')">›</button>';
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

  document.getElementById('pendingCount').textContent = stats.pendente;
  document.getElementById('acceptedCount').textContent = stats.aceite;
  document.getElementById('preparingCount').textContent = stats.em_preparacao;
  document.getElementById('readyCount').textContent = stats.pronta;
  var del = document.getElementById('deliveredCount');
  var can = document.getElementById('cancelledCount');
  if (del) del.textContent = stats.entregue;
  if (can) can.textContent = stats.cancelada;
}

function openStatusModal(encomendaId, currentStatus) {
  var modal = document.getElementById('statusModal');
  document.getElementById('modalOrderId').textContent = encomendaId;
  document.getElementById('newStatusSelect').value = currentStatus;
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
  filterEncomendas(false);
};
