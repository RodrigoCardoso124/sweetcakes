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

  document.getElementById('fatAplicarBtn').addEventListener('click', loadAll);
  document.getElementById('fatRefreshBtn').addEventListener('click', loadAll);
  document.getElementById('fatExportAtBtn').addEventListener('click', exportAt);
  document.getElementById('recAddBtn').addEventListener('click', addRecebida);
  document.getElementById('cfgSaveBtn').addEventListener('click', saveConfig);

  document.querySelectorAll('#fatTabs .tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => switchTab(btn.getAttribute('data-tab')));
  });

  const encParam = new URLSearchParams(location.search).get('encomenda_id');
  if (encParam) {
    switchTab('emitir');
  }

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
    p.classList.remove('fat-panel--active');
  });
  const panel = document.getElementById('panel-' + tab);
  if (panel) panel.classList.add('fat-panel--active');
}

function botaoAbrirFicheiro(item) {
  const fid = item.ficheiro_id || (item.ficheiro && item.ficheiro.ficheiro_id);
  const url = item.url_abrir || documentUrlFromRow(item.ficheiro);
  if (!fid && !url) {
    return '<span class="muted">Sem PDF</span>';
  }
  const urlAttr = url ? ' data-abrir-url="' + String(url).replace(/"/g, '&quot;') + '"' : '';
  return (
    '<button type="button" class="btn btn-primary btn-sm" data-abrir-ficheiro="' +
    (fid || '') +
    '"' +
    urlAttr +
    '>Abrir ficheiro</button>'
  );
}

function documentUrlFromRow(f) {
  if (!f || !f.caminho_relativo) return '';
  const c = String(f.caminho_relativo);
  if (/^https?:\/\//i.test(c)) return c;
  return '';
}

function ligarAbrirFicheiro(root) {
  if (!root) return;
  root.querySelectorAll('[data-abrir-ficheiro]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const url = btn.getAttribute('data-abrir-url');
      const fid = btn.getAttribute('data-abrir-ficheiro');
      abrirFicheiro(fid, url);
    });
  });
  root.querySelectorAll('[data-upload-doc]').forEach((btn) => {
    btn.addEventListener('click', () => uploadPdfDocumento(btn));
  });
  root.querySelectorAll('[data-emitir-enc]').forEach((btn) => {
    btn.addEventListener('click', () => emitirEncomenda(btn.getAttribute('data-emitir-enc')));
  });
  root.querySelectorAll('[data-anul-fat]').forEach((btn) => {
    btn.addEventListener('click', () => anularFatura(btn.getAttribute('data-anul-fat')));
  });
  root.querySelectorAll('[data-del-rec]').forEach((btn) => {
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

async function abrirFicheiro(ficheiroId, urlDirecta) {
  try {
    await API.openFaturacaoFicheiro(ficheiroId, urlDirecta);
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

function uploadPdfDocumento(btn) {
  const tipo = btn.getAttribute('data-upload-tipo');
  const id = btn.getAttribute('data-upload-id');
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = 'application/pdf,.pdf';
  input.onchange = async () => {
    const file = input.files && input.files[0];
    if (!file) return;
    try {
      await API.uploadFaturacaoDocumento(tipo, id, file);
      if (typeof showToast === 'function') showToast('PDF arquivado', 'success');
      loadAll();
    } catch (e) {
      if (typeof showToast === 'function') showToast(e.message, 'warning');
    }
  };
  input.click();
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
    atualizarBannerArmazenamento(r);
  } catch (e) {
    /* ignore */
  }
}

function atualizarBannerArmazenamento(cfg) {
  const el = document.getElementById('fatPdfBanner');
  if (!el) return;
  if (cfg.cloudinary_ativo) {
    el.classList.add('hidden-banner');
    return;
  }
  el.textContent =
    'PDFs são guardados no Cloudinary em produção. Confirme CLOUDINARY_CLOUD_NAME e CLOUDINARY_UPLOAD_PRESET (ou API key/secret) nas variáveis do projeto Vercel.';
  el.classList.remove('hidden-banner');
}

async function loadAll() {
  const banner = document.getElementById('fatMigrateBanner');
  try {
    await Promise.all([loadCompras(), loadPendentes(), loadEmitidas(), loadResumoIva()]);
    if (banner) banner.classList.add('hidden-banner');
  } catch (e) {
    const msg = e.message || String(e);
    if (banner && (msg.indexOf('503') !== -1 || msg.indexOf('009') !== -1 || msg.indexOf('012') !== -1)) {
      banner.textContent =
        'Execute no Vercel: /api/migrate_009_faturacao.php e /api/migrate_012_documentos.php';
      banner.classList.remove('hidden-banner');
    }
    if (typeof showToast === 'function') showToast(msg, 'warning');
  }
}

async function loadCompras() {
  const { de, ate } = periodo();
  const r = await API.getFaturacaoRecebidas(de, ate);
  const list = r.recebidas || [];
  const tb = document.querySelector('#tblRecebidas tbody');
  if (!list.length) {
    tb.innerHTML =
      '<tr><td colspan="6" class="table-empty-msg">Sem compras/documentos no período. Registe acima com o PDF do fornecedor.</td></tr>';
    return;
  }
  tb.innerHTML = list
    .map((x) => {
      const uploadBtn = !x.tem_ficheiro
        ? ' <button type="button" class="btn btn-secondary btn-sm" data-upload-doc data-upload-tipo="recebida" data-upload-id="' +
          x.recebida_id +
          '">Anexar PDF</button>'
        : '';
      return (
        '<tr><td>' +
        escapeHtml(x.data_documento) +
        '</td><td>' +
        escapeHtml(x.entidade_nome) +
        ' <span class="muted">(' +
        escapeHtml(x.tipo) +
        ')</span></td><td>' +
        escapeHtml(x.entidade_nif || '—') +
        '</td><td>' +
        fmtEuro(x.total_com_iva) +
        '</td><td>' +
        botaoAbrirFicheiro(x) +
        uploadBtn +
        '</td><td><button type="button" class="btn btn-danger btn-sm" data-del-rec="' +
        x.recebida_id +
        '">Apagar</button></td></tr>'
      );
    })
    .join('');
  ligarAbrirFicheiro(tb);
}

async function loadPendentes() {
  const r = await API.getFaturacaoEncomendasPendentes();
  const list = r.encomendas || [];
  const tb = document.querySelector('#tblPendentes tbody');
  if (!list.length) {
    tb.innerHTML =
      '<tr><td colspan="6" class="table-empty-msg">Não há encomendas entregues por faturar.</td></tr>';
    return;
  }
  tb.innerHTML = list
    .map((e) => {
      const pediu =
        e.quer_fatura_contribuinte == 1 || e.quer_fatura_contribuinte === '1'
          ? 'Sim' + (e.cliente_nif ? ' · NIF ' + escapeHtml(e.cliente_nif) : '')
          : 'Não';
      return (
        '<tr><td><strong>#' +
        e.encomenda_id +
        '</strong></td><td>' +
        escapeHtml(e.cliente_nome) +
        '</td><td>' +
        escapeHtml(e.cliente_nif || '—') +
        '</td><td>' +
        fmtEuro(e.total) +
        '</td><td>' +
        pediu +
        '</td><td><button type="button" class="btn btn-primary btn-sm" data-emitir-enc="' +
        e.encomenda_id +
        '">Emitir fatura</button></td></tr>'
      );
    })
    .join('');
  ligarAbrirFicheiro(tb);

  const encParam = new URLSearchParams(location.search).get('encomenda_id');
  if (encParam) {
    const btn = tb.querySelector('[data-emitir-enc="' + encParam + '"]');
    if (btn) btn.focus();
  }
}

async function loadEmitidas() {
  const { de, ate } = periodo();
  const r = await API.getFaturacaoEmitidas(de, ate);
  const list = r.emitidas || [];
  const tb = document.querySelector('#tblEmitidas tbody');
  if (!list.length) {
    tb.innerHTML = '<tr><td colspan="7" class="table-empty-msg">Sem faturas emitidas no período.</td></tr>';
    return;
  }
  tb.innerHTML = list
    .map((f) => {
      const doc = f.serie + ' ' + f.numero + '/' + (f.data_emissao || '').slice(0, 4);
      const uploadBtn = !f.tem_ficheiro
        ? ' <button type="button" class="btn btn-secondary btn-sm" data-upload-doc data-upload-tipo="emitida" data-upload-id="' +
          f.fatura_id +
          '">Anexar PDF</button>'
        : '';
      const anul =
        f.estado === 'emitida'
          ? ' <button type="button" class="btn btn-danger btn-sm" data-anul-fat="' + f.fatura_id + '">Anular</button>'
          : '';
      return (
        '<tr><td><strong>' +
        escapeHtml(doc) +
        '</strong></td><td>' +
        escapeHtml(f.data_emissao) +
        '</td><td>' +
        escapeHtml(f.cliente_nome) +
        '</td><td>' +
        fmtEuro(f.total_com_iva) +
        '</td><td>' +
        (f.encomenda_id ? '#' + f.encomenda_id : '—') +
        '</td><td>' +
        botaoAbrirFicheiro(f) +
        uploadBtn +
        '</td><td>' +
        anul +
        '</td></tr>'
      );
    })
    .join('');
  ligarAbrirFicheiro(tb);
}

async function emitirEncomenda(encomendaId) {
  const id = parseInt(encomendaId, 10);
  if (!id) return;
  if (!confirm('Emitir fatura para a encomenda #' + id + '?')) return;
  try {
    const r = await API.emitirFatura({ encomenda_id: id, taxa_iva_pct: taxaPadrao });
    if (r.error) {
      if (typeof showToast === 'function') showToast(r.error, 'warning');
      return;
    }
    let msg = 'Fatura ' + (r.documento || '') + ' emitida';
    if (typeof showToast === 'function') showToast(msg, 'success');
    await loadAll();
    if (r.ficheiro_id || r.url_abrir) {
      await API.openFaturacaoFicheiro(r.ficheiro_id, r.url_abrir);
    } else if (r.pdf_aviso) {
      if (typeof showToast === 'function') showToast(r.pdf_aviso, 'warning');
    }
    switchTab('vendas');
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

async function addRecebida() {
  const pdfInput = document.getElementById('recPdf');
  const pdfFile = pdfInput.files && pdfInput.files[0] ? pdfInput.files[0] : null;
  if (!pdfFile) {
    if (typeof showToast === 'function') showToast('Anexe o PDF do fornecedor', 'warning');
    return;
  }
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
    const res = await API.createFaturaRecebida(payload, pdfFile);
    if (typeof showToast === 'function') showToast('Compra registada', 'success');
    document.getElementById('recValor').value = '';
    pdfInput.value = '';
    await loadAll();
    if (res.url_abrir) window.open(res.url_abrir, '_blank', 'noopener');
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

async function loadResumoIva() {
  const { de, ate } = periodo();
  const r = await API.getFaturacaoResumoIva(de, ate);
  const deb = r.iva_debito || {};
  const cred = r.iva_credito || {};
  const aEntregar = r.iva_a_entregar != null ? r.iva_a_entregar : Math.max(0, r.iva_liquidar_estimado || 0);
  const credito = r.iva_credito_periodo || 0;
  document.getElementById('ivaCards').innerHTML =
    '<div class="stat-card stat-card--compact"><div class="stat-icon">📤</div><div class="stat-info"><h3>' +
    fmtEuro(deb.iva) +
    '</h3><p>IVA nas vendas</p></div></div>' +
    '<div class="stat-card stat-card--compact"><div class="stat-icon">📥</div><div class="stat-info"><h3>' +
    fmtEuro(cred.iva) +
    '</h3><p>IVA nas compras</p></div></div>' +
    '<div class="stat-card stat-card--compact"><div class="stat-icon">🧮</div><div class="stat-info"><h3>' +
    fmtEuro(aEntregar) +
    '</h3><p>IVA a entregar (est.)</p></div></div>' +
    (credito > 0
      ? '<div class="stat-card stat-card--compact"><div class="stat-icon">↩️</div><div class="stat-info"><h3>' +
        fmtEuro(credito) +
        '</h3><p>Crédito de IVA</p></div></div>'
      : '');
  document.getElementById('ivaNota').textContent = (r.explicacao || '') + ' ' + (r.nota || '');

  const taxas = new Set([...Object.keys(deb.por_taxa || {}), ...Object.keys(cred.por_taxa || {})]);
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
    `${base}/faturacao?view=export-at&de=` + encodeURIComponent(de) + '&ate=' + encodeURIComponent(ate);
  window.open(url, '_blank');
}
