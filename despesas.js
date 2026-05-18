/* global API, initAdminShell, showToast */

const TIPO_LABELS = {
  compra_fornecedor: 'Fornecedor (Materiais)',
  material: 'Material',
  embalagem: 'Embalagem',
  equipamento: 'Equipamento',
  servicos: 'Serviços',
  outro: 'Outro'
};

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
    if (banner) banner.style.display = 'none';
    const list = r.movimentos || [];
    renderTabela(list);
    const total = list.reduce((acc, m) => acc + (parseFloat(m.valor) || 0), 0);
    const el = document.getElementById('despResumoTotal');
    if (el) {
      el.textContent =
        list.length +
        ' movimento(s) no período · Total: ' +
        fmtEuro(total) +
        ' (compras em Materiais + despesas registadas aqui)';
    }
  } catch (e) {
    const msg = e.message || String(e);
    if (banner && (msg.indexOf('500') !== -1 || msg.indexOf('financas') !== -1)) {
      banner.textContent = 'Execute a migração: /api/migrate_008_financas.php';
      banner.style.display = 'block';
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
      const podeApagar = m.despesa_id != null && m.despesa_id > 0;
      const acoes = podeApagar
        ? '<button type="button" class="btn btn-danger btn-sm" data-del-desp="' +
          m.despesa_id +
          '">Apagar</button>'
        : '<span class="muted">—</span>';
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
}

async function addDespesa() {
  const payload = {
    tipo: document.getElementById('despTipo').value,
    descricao: document.getElementById('despDesc').value.trim(),
    valor: parseFloat(document.getElementById('despValor').value),
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
