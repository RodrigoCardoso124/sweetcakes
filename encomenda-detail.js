// Página de detalhe de encomenda
let currentEncomendaId = null;
let produtosCache = {};
let currentUserIsAdmin = false;
let currentUserCanManageOrders = false;

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  } else if (typeof requireAdminPageAuth === 'function' && !requireAdminPageAuth()) return;
  currentUserIsAdmin = typeof isCurrentUserAdmin === 'function' ? isCurrentUserAdmin() : false;
  currentUserCanManageOrders = currentUserIsAdmin || !!localStorage.getItem('adminFuncionarioId');

  const urlParams = new URLSearchParams(window.location.search);
  currentEncomendaId = urlParams.get('id');

  if (!currentEncomendaId) {
    showError('ID da encomenda não fornecido');
    return;
  }

  setupEventListeners();
  loadEncomendaDetails();
});

function setupEventListeners() {
  document.getElementById('updateStatusBtn').addEventListener('click', updateStatus);
}

function fmtEuro(n) {
  return '€' + Number(n || 0).toFixed(2);
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
    await loadProdutos();
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

    await displayEncomenda(encomenda, cliente, detalhes);

    loading.style.display = 'none';
    details.style.display = 'block';
  } catch (e) {
    loading.style.display = 'none';
    showError('Erro ao carregar encomenda: ' + e.message);
  }
}

/** Preço unitário gravado na linha (promoções) ou preço actual do catálogo. */
function precoUnitarioLinha(detalhe, produto) {
  if (detalhe.preco_unitario != null && detalhe.preco_unitario !== '') {
    const p = parseFloat(detalhe.preco_unitario);
    if (!Number.isNaN(p)) {
      return { preco: p, origem: 'linha' };
    }
  }
  return { preco: parseFloat(produto.preco || 0), origem: 'catalogo' };
}

function quantidadeLinha(detalhe) {
  const q = parseFloat(detalhe.quantidade);
  return Number.isNaN(q) || q <= 0 ? 1 : q;
}

async function displayEncomenda(encomenda, cliente, detalhes) {
  document.getElementById('orderId').textContent = encomenda.encomenda_id;

  const statusBadge = document.getElementById('orderStatus');
  statusBadge.textContent = formatStatus(encomenda.estado);
  statusBadge.className = `status-badge ${encomenda.estado ? encomenda.estado.replace('_', '-') : 'pendente'}`;

  document.getElementById('orderTotal').textContent = fmtEuro(encomenda.total);

  document.getElementById('orderClientId').textContent = encomenda.cliente_id;
  document.getElementById('orderEmployeeId').textContent = encomenda.funcionario_id || 'N/A';

  if (cliente) {
    document.getElementById('clientName').textContent = cliente.nome || 'N/A';
    document.getElementById('clientEmail').textContent = cliente.email || 'N/A';
    document.getElementById('clientPhone').textContent = cliente.telemovel || 'N/A';
    document.getElementById('clientAddress').textContent = cliente.morada || 'N/A';
    const nifEl = document.getElementById('clientNif');
    if (nifEl) nifEl.textContent = cliente.nif || '—';
    document.getElementById('clientCard').style.display = 'block';
  } else {
    document.getElementById('clientCard').style.display = 'none';
  }

  const statusActions = document.querySelector('.actions-card');
  if (currentUserCanManageOrders) {
    document.getElementById('statusSelect').value = encomenda.estado || 'pendente';
    if (statusActions) statusActions.style.display = 'block';
  } else {
    if (statusActions) statusActions.style.display = 'none';
  }

  const linhasResumo = displayProducts(detalhes);
  await renderOrderTotals(encomenda, linhasResumo);
  if (currentUserIsAdmin) await renderFaturaEncomenda(encomenda);
}

async function renderFaturaEncomenda(encomenda) {
  const card = document.getElementById('faturaCard');
  const statusEl = document.getElementById('faturaStatusText');
  const btnEmit = document.getElementById('emitirFaturaBtn');
  const linkVer = document.getElementById('verFaturaLink');
  if (!card || typeof API.getFaturacaoPreview !== 'function') return;
  card.style.display = 'block';
  const entregue = (encomenda.estado || '').toLowerCase() === 'entregue';
  if (!entregue) {
    statusEl.textContent = 'Só é possível faturar encomendas entregues.';
    if (btnEmit) btnEmit.style.display = 'none';
    if (linkVer) linkVer.style.display = 'none';
    return;
  }
  try {
    const preview = await API.getFaturacaoPreview(encomenda.encomenda_id);
    if (preview.error && preview.error.indexOf('já tem fatura') !== -1) {
      statusEl.textContent = 'Fatura já emitida para esta encomenda.';
      if (btnEmit) btnEmit.style.display = 'none';
      if (linkVer) {
        linkVer.style.display = 'inline-block';
        linkVer.href = 'faturacao.html';
      }
      return;
    }
    if (preview.error) {
      statusEl.textContent = preview.error;
      if (btnEmit) btnEmit.style.display = 'none';
      return;
    }
    const t = preview.totais || {};
    statusEl.textContent =
      'Total com IVA estimado: ' +
      fmtEuro(t.total_com_iva) +
      ' (base ' +
      fmtEuro(t.total_base) +
      ' + IVA ' +
      fmtEuro(t.total_iva) +
      ')';
    if (btnEmit) {
      btnEmit.style.display = 'inline-block';
      btnEmit.onclick = async () => {
        if (!confirm('Emitir fatura para esta encomenda?')) return;
        try {
          const r = await API.emitirFatura({ encomenda_id: encomenda.encomenda_id });
          if (typeof showToast === 'function') showToast('Fatura emitida: ' + (r.documento || ''), 'success');
          renderFaturaEncomenda(encomenda);
        } catch (e) {
          if (typeof showToast === 'function') showToast(e.message, 'warning');
        }
      };
    }
    if (linkVer) linkVer.style.display = 'none';
  } catch (e) {
    statusEl.textContent = 'Faturação: ' + (e.message || e);
  }
}

function displayProducts(detalhes) {
  const productsList = document.getElementById('productsList');
  let subtotalLinhas = 0;

  if (!detalhes || detalhes.length === 0) {
    productsList.innerHTML =
      '<p style="color: #666; font-style: italic;">Nenhum produto encontrado para esta encomenda.</p>';
    return { subtotalLinhas: 0, linhas: [] };
  }

  const linhas = [];

  productsList.innerHTML = detalhes
    .map((detalhe) => {
      const produto = produtosCache[detalhe.produto_id] || {
        nome: `Produto #${detalhe.produto_id}`,
        preco: 0
      };
      const qtd = quantidadeLinha(detalhe);
      const { preco, origem } = precoUnitarioLinha(detalhe, produto);
      const subtotal = Math.round(preco * qtd * 100) / 100;
      subtotalLinhas += subtotal;
      linhas.push({ subtotal, origem });

      const origemHint =
        origem === 'catalogo'
          ? ' <span class="muted" style="font-size:12px;">(preço catálogo)</span>'
          : '';

      return `
            
            <div class="product-item">
                <div class="product-info">
                    
                    <div class="product-name">${escapeHtml(produto.nome)}</div>
                    ${detalhe.especifico ? `<div class="product-specs">${escapeHtml(detalhe.especifico)}</div>` : ''}
                </div>
                <div class="product-line-prices">
                    <div class="product-quantity">${qtd % 1 === 0 ? qtd : qtd.toFixed(2)} × ${fmtEuro(preco)}${origemHint}</div>
                    <div class="product-line-total">${fmtEuro(subtotal)}</div>
                </div>
            </div>
        `;
    })
    .join('');

  return { subtotalLinhas: Math.round(subtotalLinhas * 100) / 100, linhas };
}

async function renderOrderTotals(encomenda, linhasResumo) {
  const el = document.getElementById('orderLinesTotals');
  if (!el) return;

  const subtotal = linhasResumo.subtotalLinhas || 0;
  const desconto = parseFloat(encomenda.desconto || 0) || 0;
  const totalEnc = parseFloat(encomenda.total || 0) || 0;
  const esperado = Math.round((subtotal - desconto) * 100) / 100;
  const diff = Math.abs(totalEnc - esperado) > 0.05;

  let promoHtml = '';
  const promoId = encomenda.promocao_id != null ? parseInt(encomenda.promocao_id, 10) : 0;
  if (promoId > 0) {
    let promoTitulo = 'Promoção #' + promoId;
    if (currentUserIsAdmin && typeof API.getPromocao === 'function') {
      try {
        const promo = await API.getPromocao(promoId);
        if (promo && promo.titulo) promoTitulo = escapeHtml(promo.titulo);
      } catch (e) {
        /* ignora */
      }
    }
    promoHtml =
      '<div class="order-totals-row"><span>' +
      promoTitulo +
      '</span><span class="order-totals-discount">− ' +
      fmtEuro(desconto) +
      '</span></div>';
  } else if (desconto > 0) {
    promoHtml =
      '<div class="order-totals-row"><span>Desconto</span><span class="order-totals-discount">− ' +
      fmtEuro(desconto) +
      '</span></div>';
  }

  el.innerHTML =
    '<div class="order-totals-row"><span>Subtotal (linhas)</span><span>' +
    fmtEuro(subtotal) +
    '</span></div>' +
    promoHtml +
    '<div class="order-totals-row order-totals-row--total"><span>Total da encomenda</span><span>' +
    fmtEuro(totalEnc) +
    '</span></div>' +
    (diff
      ? '<p class="muted order-totals-warn">A soma das linhas' +
        (desconto > 0 ? ' menos o desconto' : '') +
        ' (' +
        fmtEuro(esperado) +
        ') difere ligeiramente do total gravado — pode ser arredondamento ou linhas antigas sem preço guardado.</p>'
      : '');

  el.style.display = 'block';
}

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
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
  if (!currentUserCanManageOrders) return;
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
