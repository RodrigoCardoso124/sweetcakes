/* global API, initAdminShell, showToast, API_BASE_URL */

let taxaPadrao = 23;

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  }
  const ate = new Date();
  const de = new Date(ate.getFullYear(), ate.getMonth(), 1);
  document.getElementById('fatDe').value = de.toISOString().slice(0, 10);
  document.getElementById('fatAte').value = ate.toISOString().slice(0, 10);
  document.getElementById('recData').value = ate.toISOString().slice(0, 10);

  const encParam = new URLSearchParams(location.search).get('encomenda_id');
  if (encParam) document.getElementById('fatEncId').value = encParam;

  document.getElementById('fatAplicarBtn').addEventListener('click', loadAll);
  document.getElementById('fatRefreshBtn').addEventListener('click', loadAll);
  document.getElementById('fatExportAtBtn').addEventListener('click', exportAt);
  document.getElementById('fatPreviewBtn').addEventListener('click', previewEnc);
  document.getElementById('fatEmitirEncBtn').addEventListener('click', emitirEnc);
  document.getElementById('recAddBtn').addEventListener('click', addRecebida);
  document.getElementById('cfgSaveBtn').addEventListener('click', saveConfig);
  document.getElementById('faturaPrintBtn').addEventListener('click', () => window.print());
  document.getElementById('faturaPrintClose').addEventListener('click', closePrint);
  document.getElementById('faturaPrintClose2').addEventListener('click', closePrint);

  document.querySelectorAll('#fatTabs .tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => switchTab(btn.getAttribute('data-tab')));
  });

  loadConfig().then(loadAll);
});

function periodo() {
  return {
    de: document.getElementById('fatDe').value,
    ate: document.getElementById('fatAte').value
  };
}

function fmtEuro(n) {
  if (n == null || Number.isNaN(n)) return '—';
  return '€' + Number(n).toFixed(2);
}

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}

function switchTab(tab) {
  document.querySelectorAll('#fatTabs .tab-btn').forEach((b) => {
    b.classList.toggle('active', b.getAttribute('data-tab') === tab);
  });
  document.querySelectorAll('.fat-panel').forEach((p) => {
    p.style.display = 'none';
  });
  const panel = document.getElementById('panel-' + tab);
  if (panel) panel.style.display = 'block';
}

async function loadConfig() {
  try {
    const r = await API.getFaturacaoConfig();
    const c = r.config || {};
    document.getElementById('cfgNome').value = c.nome || '';
    document.getElementById('cfgNif').value = c.nif || '';
    document.getElementById('cfgMorada').value = c.morada || '';
    document.getElementById('cfgEmail').value = c.email || '';
    taxaPadrao = parseFloat(c.taxa_iva_padrao || 23);
    document.getElementById('cfgTaxa').value = String(taxaPadrao);
    document.getElementById('recTaxa').value = String(taxaPadrao);
  } catch (e) {
    /* ignore */
  }
}

async function loadAll() {
  const banner = document.getElementById('fatMigrateBanner');
  try {
    await Promise.all([loadEmitidas(), loadRecebidas(), loadResumoIva()]);
    if (banner) banner.style.display = 'none';
  } catch (e) {
    const msg = e.message || String(e);
    if (banner && (msg.indexOf('503') !== -1 || msg.indexOf('009') !== -1 || msg.indexOf('faturacao') !== -1)) {
      banner.textContent = 'Execute a migração: /api/migrate_009_faturacao.php';
      banner.style.display = 'block';
    }
    if (typeof showToast === 'function') showToast(msg, 'warning');
  }
}

async function loadEmitidas() {
  const { de, ate } = periodo();
  const r = await API.getFaturacaoEmitidas(de, ate);
  const list = r.emitidas || [];
  const tb = document.querySelector('#tblEmitidas tbody');
  if (!list.length) {
    tb.innerHTML = '<tr><td colspan="9" class="table-empty-msg">Sem faturas no período.</td></tr>';
    return;
  }
  tb.innerHTML = list
    .map((f) => {
      const doc = f.serie + ' ' + f.numero + '/' + (f.data_emissao || '').slice(0, 4);
      const acoes =
        '<button type="button" class="btn btn-secondary btn-sm" data-ver-fat="' +
        f.fatura_id +
        '">Ver</button> ' +
        (f.estado === 'emitida'
          ? '<button type="button" class="btn btn-danger btn-sm" data-anul-fat="' +
            f.fatura_id +
            '">Anular</button>'
          : '');
      return (
        '<tr><td><strong>' +
        escapeHtml(doc) +
        '</strong></td><td>' +
        escapeHtml(f.data_emissao) +
        '</td><td>' +
        escapeHtml(f.cliente_nome) +
        '</td><td>' +
        escapeHtml(f.cliente_nif || '—') +
        '</td><td>' +
        fmtEuro(f.total_base) +
        '</td><td>' +
        fmtEuro(f.total_iva) +
        '</td><td>' +
        fmtEuro(f.total_com_iva) +
        '</td><td>' +
        escapeHtml(f.estado) +
        '</td><td>' +
        acoes +
        '</td></tr>'
      );
    })
    .join('');
  tb.querySelectorAll('[data-ver-fat]').forEach((btn) => {
    btn.addEventListener('click', () => verFatura(btn.getAttribute('data-ver-fat')));
  });
  tb.querySelectorAll('[data-anul-fat]').forEach((btn) => {
    btn.addEventListener('click', () => anularFatura(btn.getAttribute('data-anul-fat')));
  });
}

async function loadRecebidas() {
  const { de, ate } = periodo();
  const r = await API.getFaturacaoRecebidas(de, ate);
  const list = r.recebidas || [];
  const tb = document.querySelector('#tblRecebidas tbody');
  if (!list.length) {
    tb.innerHTML = '<tr><td colspan="8" class="table-empty-msg">Sem documentos recebidos.</td></tr>';
    return;
  }
  tb.innerHTML = list
    .map((x) => {
      return (
        '<tr><td>' +
        escapeHtml(x.data_documento) +
        '</td><td>' +
        escapeHtml(x.tipo) +
        '</td><td>' +
        escapeHtml(x.entidade_nome) +
        '</td><td>' +
        escapeHtml(x.entidade_nif || '—') +
        '</td><td>' +
        fmtEuro(x.total_base) +
        '</td><td>' +
        fmtEuro(x.total_iva) +
        '</td><td>' +
        fmtEuro(x.total_com_iva) +
        '</td><td><button type="button" class="btn btn-danger btn-sm" data-del-rec="' +
        x.recebida_id +
        '">Apagar</button></td></tr>'
      );
    })
    .join('');
  tb.querySelectorAll('[data-del-rec]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Apagar este documento?')) return;
      try {
        await API.deleteFaturaRecebida(btn.getAttribute('data-del-rec'));
        if (typeof showToast === 'function') showToast('Removido', 'success');
        loadAll();
      } catch (e) {
        if (typeof showToast === 'function') showToast(e.message, 'warning');
      }
    });
  });
}

async function loadResumoIva() {
  const { de, ate } = periodo();
  const r = await API.getFaturacaoResumoIva(de, ate);
  const deb = r.iva_debito || {};
  const cred = r.iva_credito || {};
  document.getElementById('ivaCards').innerHTML =
    '<div class="stat-card stat-card--compact"><div class="stat-icon">📤</motion></div><div class="stat-info"><h3>' +
    fmtEuro(deb.iva) +
    '</h3><p>IVA debitado (vendas)</p></div></div>' +
    '<div class="stat-card stat-card--compact"><div class="stat-icon">📥</div><div class="stat-info"><h3>' +
    fmtEuro(cred.iva) +
    '</h3><p>IVA dedutível (compras)</p></div></div>' +
    '<motion></motion><div class="stat-card stat-card--compact"><div class="stat-icon">🧮</div><div class="stat-info"><h3>' +
    fmtEuro(r.iva_liquidar_estimado) +
    '</h3><p>IVA a liquidar (est.)</p></div></div>';
  document.getElementById('ivaNota').textContent = r.nota || '';

  const taxas = new Set([
    ...Object.keys(deb.por_taxa || {}),
    ...Object.keys(cred.por_taxa || {})
  ]);
  const tb = document.querySelector('#tblIvaTaxas tbody');
  if (!taxas.size) {
    tb.innerHTML = '<tr><td colspan="5" class="table-empty-msg">Sem movimentos no período.</td></tr>';
    return;
  }
  tb.innerHTML = Array.from(taxas)
    .sort((a, b) => parseFloat(b) - parseFloat(a))
    .map((t) => {
      const d = (deb.por_taxa || {})[t] || { base: 0, iva: 0 };
      const c = (cred.por_taxa || {})[t] || { base: 0, iva: 0 };
      return (
        '<tr><td>' +
        t +
        '%</td><td>' +
        fmtEuro(d.base) +
        '</td><td>' +
        fmtEuro(d.iva) +
        '</td><td>' +
        fmtEuro(c.base) +
        '</td><td>' +
        fmtEuro(c.iva) +
        '</td></tr>'
      );
    })
    .join('');
}

async function previewEnc() {
  const id = parseInt(document.getElementById('fatEncId').value, 10);
  const el = document.getElementById('fatPreviewMsg');
  if (!id) {
    el.textContent = 'Indica o ID da encomenda.';
    return;
  }
  try {
    const p = await API.getFaturacaoPreview(id);
    if (p.error) {
      el.textContent = p.error;
      return;
    }
    const t = p.totais || {};
    let txt =
      'Cliente: ' +
      (p.cliente && p.cliente.nome) +
      ' · Base ' +
      fmtEuro(t.total_base) +
      ' + IVA ' +
      fmtEuro(t.total_iva) +
      ' = ' +
      fmtEuro(t.total_com_iva);
    if (p.avisos && p.avisos.length) txt += ' · ' + p.avisos.join(' ');
    el.textContent = txt;
  } catch (e) {
    el.textContent = e.message;
  }
}

async function emitirEnc() {
  const id = parseInt(document.getElementById('fatEncId').value, 10);
  if (!id) {
    if (typeof showToast === 'function') showToast('ID da encomenda obrigatório', 'warning');
    return;
  }
  if (!confirm('Emitir fatura para a encomenda #' + id + '?')) return;
  try {
    const r = await API.emitirFatura({ encomenda_id: id, taxa_iva_pct: taxaPadrao });
    if (typeof showToast === 'function') showToast('Fatura ' + (r.documento || '') + ' emitida', 'success');
    loadAll();
    if (r.fatura_id) verFatura(r.fatura_id);
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

async function addRecebida() {
  const modo = document.querySelector('input[name="recModo"]:checked').value;
  const payload = {
    action: 'recebida',
    tipo: document.getElementById('recTipo').value,
    data_documento: document.getElementById('recData').value,
    entidade_nome: document.getElementById('recEntidade').value.trim(),
    entidade_nif: document.getElementById('recNif').value.trim(),
    numero: document.getElementById('recNumero').value.trim(),
    valor: parseFloat(document.getElementById('recValor').value),
    taxa_iva_pct: parseFloat(document.getElementById('recTaxa').value),
    modo_valor: modo
  };
  if (!payload.entidade_nome || !(payload.valor > 0)) {
    if (typeof showToast === 'function') showToast('Entidade e valor são obrigatórios', 'warning');
    return;
  }
  try {
    await API.createFaturaRecebida(payload);
    if (typeof showToast === 'function') showToast('Documento registado', 'success');
    document.getElementById('recValor').value = '';
    loadAll();
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

async function saveConfig() {
  try {
    await API.saveFaturacaoConfig({
      action: 'config',
      nome: document.getElementById('cfgNome').value.trim(),
      nif: document.getElementById('cfgNif').value.trim(),
      morada: document.getElementById('cfgMorada').value.trim(),
      email: document.getElementById('cfgEmail').value.trim(),
      taxa_iva_padrao: document.getElementById('cfgTaxa').value
    });
    taxaPadrao = parseFloat(document.getElementById('cfgTaxa').value);
    if (typeof showToast === 'function') showToast('Dados guardados', 'success');
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

async function verFatura(id) {
  try {
    const f = await API.getFatura(id);
    const emp = f.empresa || {};
    const linhas = (f.linhas || [])
      .map(
        (l) =>
          '<tr><td>' +
          escapeHtml(l.descricao) +
          '</td><td>' +
          l.quantidade +
          '</td><td>' +
          fmtEuro(l.preco_unitario_sem_iva) +
          '</td><td>' +
          l.taxa_iva_pct +
          '%</td><td>' +
          fmtEuro(l.base_linha) +
          '</td><td>' +
          fmtEuro(l.iva_linha) +
          '</td><td>' +
          fmtEuro(l.total_linha) +
          '</td></tr>'
      )
      .join('');
    const anulada = f.estado === 'anulada' ? '<p class="fatura-anulada">DOCUMENTO ANULADO</p>' : '';
    document.getElementById('faturaPrintBody').innerHTML =
      '<div class="fatura-doc">' +
      anulada +
      '<header class="fatura-header"><h1>' +
      escapeHtml(emp.nome || 'Sweet Cakes') +
      '</h1><p>NIF ' +
      escapeHtml(emp.nif || '—') +
      '<br>' +
      escapeHtml(emp.morada || '') +
      '</p></header>' +
      '<h2>Fatura ' +
      escapeHtml(f.serie + ' ' + f.numero + '/' + (f.data_emissao || '').slice(0, 4)) +
      '</h2>' +
      '<p><strong>Data:</strong> ' +
      escapeHtml(f.data_emissao) +
      '</p>' +
      '<p><strong>Cliente:</strong> ' +
      escapeHtml(f.cliente_nome) +
      '<br><strong>NIF:</strong> ' +
      escapeHtml(f.cliente_nif || 'Consumidor final') +
      '<br>' +
      escapeHtml(f.cliente_morada || '') +
      '</p>' +
      '<table class="orders-table fatura-linhas"><thead><tr><th>Descrição</th><th>Qtd</th><th>Preço s/IVA</th><th>IVA</th><th>Base</th><th>IVA €</th><th>Total</th></tr></thead><tbody>' +
      linhas +
      '</tbody></table>' +
      '<p class="fatura-totais">Base: ' +
      fmtEuro(f.total_base) +
      ' · IVA: ' +
      fmtEuro(f.total_iva) +
      ' · <strong>Total: ' +
      fmtEuro(f.total_com_iva) +
      '</strong></p>' +
      (f.notas ? '<p class="muted">' + escapeHtml(f.notas) + '</p>' : '') +
      '</div>';
    document.getElementById('faturaPrintModal').classList.add('active');
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

function closePrint() {
  document.getElementById('faturaPrintModal').classList.remove('active');
}

async function anularFatura(id) {
  if (!confirm('Anular esta fatura?')) return;
  try {
    await API.anularFatura(id);
    if (typeof showToast === 'function') showToast('Fatura anulada', 'success');
    loadAll();
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

function exportAt() {
  const { de, ate } = periodo();
  const base = typeof API_BASE_URL !== 'undefined' ? API_BASE_URL : '/index.php';
  const url =
    base +
    '/faturacao?view=export-at&de=' +
    encodeURIComponent(de) +
    '&ate=' +
    encodeURIComponent(ate);
  window.open(url, '_blank');
}
