/* global API, initAdminShell, showToast */

const TIPO_LABELS = {
  compra_fornecedor: 'Fornecedor (Materiais)',
  material: 'Material',
  embalagem: 'Embalagem',
  equipamento: 'Equipamento',
  servicos: 'Serviços',
  outro: 'Outro'
};

let movimentosCache = [];

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  }
  const ate = new Date();
  const de = new Date(ate.getFullYear(), ate.getMonth(), 1);
  document.getElementById('despDe').value = de.toISOString().slice(0, 10);
  document.getElementById('despAte').value = ate.toISOString().slice(0, 10);
  document.getElementById('despData').value = ate.toISOString().slice(0, 10);
  document.getElementById('despAplicarBtn').addEventListener('click', loadDespesas);
  document.getElementById('refreshBtn').addEventListener('click', loadDespesas);
  document.getElementById('despAddBtn').addEventListener('click', addDespesa);
  document.getElementById('despExportBtn').addEventListener('click', exportCsv);
  document.getElementById('despEditSave').addEventListener('click', saveEditDespesa);
  document.getElementById('despEditCancel').addEventListener('click', closeEditModal);
  document.getElementById('despEditClose').addEventListener('click', closeEditModal);
  loadDespesas();
});

function fmtEuro(n) {
  if (n == null || Number.isNaN(n)) return '—';
  return '€' + Number(n).toFixed(2);
}

function periodo() {
  return {
    de: document.getElementById('despDe').value,
    ate: document.getElementById('despAte').value
  };
}

async function loadDespesas() {
  const { de, ate } = periodo();
  const banner = document.getElementById('despMigrateBanner');
  try {
    const r = await API.getFinancasMovimentos(de, ate);
    if (banner) banner.classList.add('hidden-banner');
    movimentosCache = r.movimentos || [];
    renderTabela(movimentosCache);
    const total = movimentosCache.reduce((acc, m) => acc + (parseFloat(m.valor) || 0), 0);
    const el = document.getElementById('despResumoTotal');
    if (el) {
      el.textContent =
        movimentosCache.length +
        ' movimento(s) no período · Total: ' +
        fmtEuro(total) +
        ' (compras em Materiais + despesas registadas aqui)';
    }
  } catch (e) {
    const msg = e.message || String(e);
    if (banner && (msg.indexOf('500') !== -1 || msg.indexOf('financas') !== -1)) {
      banner.textContent = 'Execute a migração: /api/migrate_008_financas.php';
      banner.classList.remove('hidden-banner');
    }
    if (typeof showToast === 'function') showToast('Erro: ' + msg, 'warning');
  }
}

function renderTabela(list) {
  const tb = document.querySelector('#tblDespesas tbody');
  if (!list.length) {
    tb.innerHTML =
      '<tr><td colspan="5" class="table-empty-msg">Sem despesas neste período. Adiciona uma acima ou regista compras em Materiais.</td></tr>';
    return;
  }
  tb.innerHTML = list
    .map((m) => {
      const podeEditar = m.despesa_id != null && m.despesa_id > 0;
      let acoes = '<span class="muted">—</span>';
      if (podeEditar) {
        acoes =
          '<button type="button" class="btn btn-secondary btn-sm" data-edit-desp="' +
          m.despesa_id +
          '">Editar</button> ' +
          '<button type="button" class="btn btn-danger btn-sm" data-del-desp="' +
          m.despesa_id +
          '">Apagar</button>';
      }
      return (
        '<tr><td>' +
        escapeHtml(m.data) +
        '</td><td>' +
        escapeHtml(TIPO_LABELS[m.tipo] || m.tipo) +
        '</td><td>' +
        escapeHtml(m.descricao) +
        (m.detalhe ? ' <span class="muted">' + escapeHtml(m.detalhe) + '</span>' : '') +
        '</td><td>' +
        fmtEuro(m.valor) +
        '</td><td>' +
        acoes +
        '</td></tr>'
      );
    })
    .join('');

  tb.querySelectorAll('[data-del-desp]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Apagar esta despesa?')) return;
      try {
        await API.deleteDespesa(btn.getAttribute('data-del-desp'));
        if (typeof showToast === 'function') showToast('Despesa removida', 'success');
        loadDespesas();
      } catch (e) {
        if (typeof showToast === 'function') showToast(e.message, 'warning');
      }
    });
  });

  tb.querySelectorAll('[data-edit-desp]').forEach((btn) => {
    btn.addEventListener('click', () => openEditModal(btn.getAttribute('data-edit-desp')));
  });
}

async function openEditModal(id) {
  try {
    const d = await API.getDespesa(id);
    document.getElementById('despEditId').value = id;
    document.getElementById('despEditTipo').value = d.tipo || 'outro';
    document.getElementById('despEditData').value = (d.data_despesa || '').slice(0, 10);
    document.getElementById('despEditDesc').value = d.descricao || '';
    document.getElementById('despEditValor').value =
      d.total_base != null && d.total_iva != null ? d.total_base + d.total_iva : d.valor != null ? d.valor : '';
    if (d.taxa_iva_pct != null) document.getElementById('despEditTaxa').value = String(d.taxa_iva_pct);
    document.querySelector('input[name="despEditModo"][value="com_iva"]').checked = true;
    document.getElementById('despEditNotas').value = d.notas || '';
    document.getElementById('despEditModal').classList.add('active');
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

function closeEditModal() {
  document.getElementById('despEditModal').classList.remove('active');
}

async function saveEditDespesa() {
  const id = document.getElementById('despEditId').value;
  const payload = {
    tipo: document.getElementById('despEditTipo').value,
    descricao: document.getElementById('despEditDesc').value.trim(),
    valor: parseFloat(document.getElementById('despEditValor').value),
    taxa_iva_pct: parseFloat(document.getElementById('despEditTaxa').value),
    modo_valor: document.querySelector('input[name="despEditModo"]:checked').value,
    data_despesa: document.getElementById('despEditData').value,
    notas: document.getElementById('despEditNotas').value.trim() || null
  };
  if (!payload.descricao || !(payload.valor > 0)) {
    if (typeof showToast === 'function') showToast('Descrição e valor são obrigatórios', 'warning');
    return;
  }
  try {
    await API.updateDespesa(id, payload);
    if (typeof showToast === 'function') showToast('Despesa actualizada', 'success');
    closeEditModal();
    loadDespesas();
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

function exportCsv() {
  if (!movimentosCache.length) {
    if (typeof showToast === 'function') showToast('Nada para exportar', 'warning');
    return;
  }
  const header = 'Data;Origem;Descrição;Valor;Detalhe\n';
  const rows = movimentosCache
    .map((m) =>
      [
        m.data,
        TIPO_LABELS[m.tipo] || m.tipo,
        (m.descricao || '').replace(/;/g, ','),
        m.valor,
        (m.detalhe || '').replace(/;/g, ',')
      ].join(';')
    )
    .join('\n');
  const blob = new Blob(['\ufeff' + header + rows], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'despesas-' + periodo().de + '_' + periodo().ate + '.csv';
  a.click();
  URL.revokeObjectURL(a.href);
}

async function addDespesa() {
  const payload = {
    tipo: document.getElementById('despTipo').value,
    descricao: document.getElementById('despDesc').value.trim(),
    valor: parseFloat(document.getElementById('despValor').value),
    taxa_iva_pct: parseFloat(document.getElementById('despTaxa').value),
    modo_valor: document.querySelector('input[name="despModo"]:checked').value,
    data_despesa: document.getElementById('despData').value,
    notas: document.getElementById('despNotas').value.trim() || null
  };
  if (!payload.descricao || !(payload.valor > 0)) {
    if (typeof showToast === 'function') showToast('Descrição e valor são obrigatórios', 'warning');
    return;
  }
  try {
    await API.createDespesa(payload);
    if (typeof showToast === 'function') showToast('Despesa registada', 'success');
    document.getElementById('despDesc').value = '';
    document.getElementById('despValor').value = '';
    document.getElementById('despNotas').value = '';
    loadDespesas();
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}
