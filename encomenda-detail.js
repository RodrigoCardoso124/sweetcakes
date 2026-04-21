// Página de detalhe de encomenda
let currentEncomendaId = null;
let produtosCache = {};
let currentUserIsAdmin = false;

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  } else if (typeof requireAdminPageAuth === 'function' && !requireAdminPageAuth()) return;
  currentUserIsAdmin = typeof isCurrentUserAdmin === 'function' ? isCurrentUserAdmin() : false;

  const urlParams = new URLSearchParams(window.location.search);
  currentEncomendaId = urlParams.get('id');

  if (!currentEncomendaId) {
    showError('ID da encomenda não fornecido');
    return;
  }

  loadEncomendaDetails();
  setupEventListeners();
  loadProdutos();
});

function setupEventListeners() {
  document.getElementById('updateStatusBtn').addEventListener('click', updateStatus);
}

async function loadProdutos() {
  try {
    const produtos = await API.getProdutos();
    produtos.forEach((produto) => {
      produtosCache[produto.produto_id] = produto;
    });
  } catch (error) {
    console.error('Error loading produtos:', error);
  }
}

async function loadEncomendaDetails() {
  const loading = document.getElementById('loading');
  const details = document.getElementById('orderDetails');
  const error = document.getElementById('errorMessage');

  loading.style.display = 'block';
  details.style.display = 'none';
  error.style.display = 'none';

  try {
    const encomenda = await API.getEncomenda(currentEncomendaId);

    let cliente = null;
    if (currentUserIsAdmin) {
      try {
        cliente = await API.getPessoa(encomenda.cliente_id);
      } catch (err) {
        console.error('Error loading cliente:', err);
      }
    }

    let detalhes = [];
    try {
      detalhes = await API.getEncomendaDetalhes(currentEncomendaId);
    } catch (err) {
      console.error('Error loading detalhes:', err);
    }

    displayEncomenda(encomenda, cliente, detalhes);

    loading.style.display = 'none';
    details.style.display = 'block';
  } catch (e) {
    loading.style.display = 'none';
    showError('Erro ao carregar encomenda: ' + e.message);
  }
}

function displayEncomenda(encomenda, cliente, detalhes) {
  document.getElementById('orderId').textContent = encomenda.encomenda_id;

  const statusBadge = document.getElementById('orderStatus');
  statusBadge.textContent = formatStatus(encomenda.estado);
  statusBadge.className = `status-badge ${encomenda.estado ? encomenda.estado.replace('_', '-') : 'pendente'}`;

  document.getElementById('orderTotal').textContent = `€${parseFloat(encomenda.total || 0).toFixed(2)}`;

  document.getElementById('orderClientId').textContent = encomenda.cliente_id;
  document.getElementById('orderEmployeeId').textContent = encomenda.funcionario_id || 'N/A';

  if (cliente) {
    document.getElementById('clientName').textContent = cliente.nome || 'N/A';
    document.getElementById('clientEmail').textContent = cliente.email || 'N/A';
    document.getElementById('clientPhone').textContent = cliente.telemovel || 'N/A';
    document.getElementById('clientAddress').textContent = cliente.morada || 'N/A';
    document.getElementById('clientCard').style.display = 'block';
  } else {
    document.getElementById('clientCard').style.display = 'none';
  }

  const statusActions = document.querySelector('.actions-card');
  if (currentUserIsAdmin) {
    document.getElementById('statusSelect').value = encomenda.estado || 'pendente';
    if (statusActions) statusActions.style.display = 'block';
  } else {
    if (statusActions) statusActions.style.display = 'none';
  }

  displayProducts(detalhes);
}

function displayProducts(detalhes) {
  const productsList = document.getElementById('productsList');

  if (!detalhes || detalhes.length === 0) {
    productsList.innerHTML =
      '<p style="color: #666; font-style: italic;">Nenhum produto encontrado para esta encomenda.</p>';
    return;
  }

  productsList.innerHTML = detalhes
    .map((detalhe) => {
      const produto = produtosCache[detalhe.produto_id] || {
        nome: `Produto #${detalhe.produto_id}`,
        preco: 0
      };
      const subtotal = parseFloat(produto.preco || 0) * parseInt(detalhe.quantidade || 1);

      return `
            <div class="product-item">
                <div class="product-info">
                    <div class="product-name">${produto.nome}</div>
                    ${detalhe.especifico ? `<div class="product-specs">${detalhe.especifico}</div>` : ''}
                </div>
                <div style="text-align: right;">
                    <div class="product-quantity">${detalhe.quantidade}x</div>
                    <div style="color: #666; font-size: 14px;">€${subtotal.toFixed(2)}</div>
                </div>
            </div>
        `;
    })
    .join('');
}

function formatStatus(status) {
  const statusMap = {
    pendente: 'Pendente',
    aceite: 'Aceite',
    em_preparacao: 'Em Preparação',
    pronta: 'Pronta',
    entregue: 'Entregue',
    cancelada: 'Cancelada'
  };
  return statusMap[status] || status;
}

async function updateStatus() {
  if (!currentUserIsAdmin) return;
  const newStatus = document.getElementById('statusSelect').value;
  const btn = document.getElementById('updateStatusBtn');

  if (!newStatus || newStatus.trim() === '') {
    if (typeof showToast === 'function') showToast('Seleciona um estado válido.', 'error');
    return;
  }

  btn.disabled = true;
  btn.textContent = 'A atualizar...';

  try {
    const encomenda = await API.getEncomenda(currentEncomendaId);

    const updateData = {
      cliente_id: encomenda.cliente_id,
      estado: newStatus.trim(),
      total: encomenda.total
    };

    const response = await API.updateEncomenda(currentEncomendaId, updateData);
    await loadEncomendaDetails();
    if (typeof showToastsForEncomendaEmail === 'function') {
      showToastsForEncomendaEmail(response, currentEncomendaId, response && response.estado_novo);
    } else if (typeof showToast === 'function') showToast('Estado atualizado.', 'success');
  } catch (error) {
    if (typeof showToast === 'function') showToast(error.message || 'Erro ao atualizar', 'error');
    else alert(error.message || 'Erro');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Atualizar Estado';
  }
}

function showError(message) {
  const error = document.getElementById('errorMessage');
  error.innerHTML = `<p>${message}</p><p><a href="index.html">Voltar à lista</a></p>`;
  error.style.display = 'block';
}
