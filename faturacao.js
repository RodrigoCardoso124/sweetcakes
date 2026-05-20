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
  document.getElementById('arqFiltrarBtn').addEventListener('click', loadArquivo);
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
    p.classList.remove('fat-panel--active');
  });
  const panel = document.getElementById('panel-' + tab);
  if (panel) panel.classList.add('fat-panel--active');
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

function pdfBadge(tem) {
  return tem
    ? '<span class="badge badge--ok">Arquivado</span>'
    : '<span class="badge badge--muted">Sem ficheiro</span>';
}

function botoesPdf(ficheiroId, tem) {
  if (!tem || !ficheiroId) {
    return '<span class="muted">—</span>';
  }
  return (
    '<button type="button" class="btn btn-secondary btn-sm" data-pdf-ver="' +
    ficheiroId +
    '">Ver</button> ' +
    '<button type="button" class="btn btn-secondary btn-sm" data-pdf-dl="' +
    ficheiroId +
    '">Descarregar</button>'
  );
}

function ligarBotoesPdf(root) {
  if (!root) return;
  root.querySelectorAll('[data-pdf-ver]').forEach((btn) => {
    btn.addEventListener('click', () => abrirPdf(btn.getAttribute('data-pdf-ver'), true));
  });
  root.querySelectorAll('[data-pdf-dl]').forEach((btn) => {
    btn.addEventListener('click', () => abrirPdf(btn.getAttribute('data-pdf-dl'), false));
  });
  root.querySelectorAll('[data-upload-doc]').forEach((btn) => {
    btn.addEventListener('click', () => uploadPdfDocumento(btn));
  });
}

async function abrirPdf(ficheiroId, inline) {
  try {
    const r = await API.downloadFaturacaoFicheiro(ficheiroId, inline);
    if (inline) {
      window.open(r.url, '_blank');
    } else {
      const a = document.createElement('a');
      a.href = r.url;
      a.download = r.nome;
      a.click();
    }
    setTimeout(() => URL.revokeObjectURL(r.url), 60000);
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

async function loadAll() {
  const banner = document.getElementById('fatMigrateBanner');
  try {
    await Promise.all([loadEmitidas(), loadRecebidas(), loadResumoIva(), loadArquivo(), atualizarBannerPdf()]);
    if (banner) banner.classList.add('hidden-banner');
  } catch (e) {
    const msg = e.message || String(e);
    if (banner && (msg.indexOf('503') !== -1 || msg.indexOf('009') !== -1 || msg.indexOf('012') !== -1 || msg.indexOf('faturacao') !== -1)) {
      banner.textContent =
        'Execute as migrações: /api/migrate_009_faturacao.php e /api/migrate_012_documentos.php';
      banner.classList.remove('hidden-banner');
    }
    if (typeof showToast === 'function') showToast(msg, 'warning');
  }
}

async function atualizarBannerPdf() {
  const el = document.getElementById('fatPdfBanner');
  if (!el) return;
  try {
    const r = await API.getFaturacaoConfig();
    if (r.pdf_disponivel) {
      el.classList.add('hidden-banner');
      return;
    }
    el.textContent =
      'Geração automática de PDF: execute composer install na pasta do projeto (Dompdf). Enquanto isso, as faturas ficam arquivadas em HTML e pode carregar PDFs manualmente.';
    el.classList.remove('hidden-banner');
  } catch (e) {
    /* ignore */
  }
}

async function loadEmitidas() {
  const { de, ate } = periodo();
  const r = await API.getFaturacaoEmitidas(de, ate);
  const list = r.emitidas || [];
  const tb = document.querySelector('#tblEmitidas tbody');
  if (!list.length) {
    tb.innerHTML = '<tr><td colspan="10" class="table-empty-msg">Sem faturas no período.</td></tr>';
    return;
  }
  tb.innerHTML = list
    .map((f) => {
      const doc = f.serie + ' ' + f.numero + '/' + (f.data_emissao || '').slice(0, 4);
      const fid = f.ficheiro_id || (f.ficheiro && f.ficheiro.ficheiro_id);
      const acoes =
        '<button type="button" class="btn btn-secondary btn-sm" data-ver-fat="' +
        f.fatura_id +
        '">Ver</button> ' +
        (f.estado === 'emitida'
          ? '<button type="button" class="btn btn-danger btn-sm" data-anul-fat="' +
            f.fatura_id +
            '">Anular</button>'
          : '');
      const uploadBtn = !f.tem_ficheiro
        ? ' <button type="button" class="btn btn-secondary btn-sm" data-upload-doc data-upload-tipo="emitida" data-upload-id="' +
          f.fatura_id +
          '">Carregar PDF</button>'
        : '';
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
        pdfBadge(f.tem_ficheiro) +
        ' ' +
        botoesPdf(fid, f.tem_ficheiro) +
        uploadBtn +
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
  ligarBotoesPdf(tb);
}

async function loadRecebidas() {
  const { de, ate } = periodo();
  const r = await API.getFaturacaoRecebidas(de, ate);
  const list = r.recebidas || [];
  const tb = document.querySelector('#tblRecebidas tbody');
  if (!list.length) {
    tb.innerHTML = '<tr><td colspan="9" class="table-empty-msg">Sem documentos recebidos.</td></tr>';
    return;
  }
  tb.innerHTML = list
    .map((x) => {
      const uploadBtn = !x.tem_ficheiro
        ? '<button type="button" class="btn btn-secondary btn-sm" data-upload-doc data-upload-tipo="recebida" data-upload-id="' +
          x.recebida_id +
          '">Carregar PDF</button> '
        : '';
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
        '</td><td>' +
        pdfBadge(x.tem_ficheiro) +
        ' ' +
        botoesPdf(x.ficheiro_id || (x.ficheiro && x.ficheiro.ficheiro_id), x.tem_ficheiro) +
        ' ' +
        uploadBtn +
        '</td><td><button type="button" class="btn btn-danger btn-sm" data-del-rec="' +
        x.recebida_id +
        '">Apagar</button></td></tr>'
      );
    })
    .join('');
  ligarBotoesPdf(tb);
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

async function loadArquivo() {
  const { de, ate } = periodo();
  const tipo = document.getElementById('arqTipo').value;
  const q = document.getElementById('arqQ').value.trim();
  const cfRaw = document.getElementById('arqFicheiro').value;
  const opts = { tipo: tipo || undefined, q: q || undefined };
  if (cfRaw === '1') opts.com_ficheiro = true;
  if (cfRaw === '0') opts.com_ficheiro = false;
  const r = await API.getFaturacaoArquivo(de, ate, opts);
  const list = r.documentos || [];
  const tb = document.querySelector('#tblArquivo tbody');
  if (!list.length) {
    tb.innerHTML = '<tr><td colspan="8" class="table-empty-msg">Nenhum documento no arquivo para estes filtros.</td></tr>';
    return;
  }
  tb.innerHTML = list
    .map((d) => {
      const tipoLabel = d.tipo === 'emitida' ? 'Emitida' : 'Recebida';
      const acoes =
        botoesPdf(d.ficheiro_id, d.tem_ficheiro) +
        (!d.tem_ficheiro
          ? ' <button type="button" class="btn btn-secondary btn-sm" data-upload-doc data-upload-tipo="' +
            d.tipo +
            '" data-upload-id="' +
            d.documento_id +
            '">Carregar</button>'
          : '') +
        (d.tipo === 'emitida'
          ? ' <button type="button" class="btn btn-secondary btn-sm" data-ver-fat="' + d.documento_id + '">Detalhe</button>'
          : '');
      return (
        '<tr><td>' +
        escapeHtml(tipoLabel) +
        '</td><td><strong>' +
        escapeHtml(d.referencia || '—') +
        '</strong></td><td>' +
        escapeHtml(d.data) +
        '</td><td>' +
        escapeHtml(d.entidade) +
        '</td><td>' +
        escapeHtml(d.nif || '—') +
        '</td><td>' +
        fmtEuro(d.total_com_iva) +
        '</td><td>' +
        pdfBadge(d.tem_ficheiro) +
        '</td><td>' +
        acoes +
        '</td></tr>'
      );
    })
    .join('');
  ligarBotoesPdf(tb);
  tb.querySelectorAll('[data-ver-fat]').forEach((btn) => {
    btn.addEventListener('click', () => verFatura(btn.getAttribute('data-ver-fat')));
  });
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
    '</h3><p>IVA nas vendas (debitado)</p></div></div>' +
    '<div class="stat-card stat-card--compact"><div class="stat-icon">📥</div><div class="stat-info"><h3>' +
    fmtEuro(cred.iva) +
    '</h3><p>IVA nas compras (dedutível)</p></div></div>' +
    '<div class="stat-card stat-card--compact"><div class="stat-icon">🧮</div><div class="stat-info"><h3>' +
    fmtEuro(aEntregar) +
    '</h3><p>IVA a entregar (estimado)</p></div></div>' +
    (credito > 0
      ? '<div class="stat-card stat-card--compact"><div class="stat-icon">↩️</div><div class="stat-info"><h3>' +
        fmtEuro(credito) +
        '</h3><p>Crédito de IVA (compras &gt; vendas)</p></div></div>'
      : '');
  const explic = (r.explicacao || '') + (r.compras_maior_que_vendas ? ' Neste período o IVA dedutível supera o debitado.' : '');
  document.getElementById('ivaNota').textContent = explic + ' ' + (r.nota || '');

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
    let msg = 'Fatura ' + (r.documento || '') + ' emitida';
    if (r.pdf_aviso) msg += ' — ' + r.pdf_aviso;
    if (typeof showToast === 'function') showToast(msg, r.pdf_aviso ? 'warning' : 'success');
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
  const pdfInput = document.getElementById('recPdf');
  const pdfFile = pdfInput.files && pdfInput.files[0] ? pdfInput.files[0] : null;
  try {
    await API.createFaturaRecebida(payload, pdfFile);
    if (typeof showToast === 'function') showToast('Documento registado', 'success');
    document.getElementById('recValor').value = '';
    pdfInput.value = '';
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
        fmtEuro(l.preco_unitario_com_iva != null ? l.preco_unitario_com_iva : l.total_linha / l.quantidade) +
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
      '<p class="muted" style="margin-bottom:10px;">Valores pagos pelo cliente já incluíam IVA; abaixo a repartição para a fatura.</p>' +
      '<table class="orders-table fatura-linhas"><thead><tr><th>Descrição</th><th>Qtd</th><th>Preço c/IVA</th><th>Taxa</th><th>Base</th><th>IVA €</th><th>Total</th></tr></thead><tbody>' +
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
    const footer = document.querySelector('#faturaPrintModal .modal-footer-actions');
    const fid = f.ficheiro_id || (f.ficheiro && f.ficheiro.ficheiro_id);
    let extra = '';
    if (fid) {
      extra =
        '<button type="button" class="btn btn-secondary" id="fatModalPdfVer">Abrir PDF</button>' +
        '<button type="button" class="btn btn-secondary" id="fatModalPdfDl">Descarregar PDF</button>';
    }
    if (footer) {
      const base = footer.innerHTML;
      footer.innerHTML = extra + base;
      const v = document.getElementById('fatModalPdfVer');
      const d = document.getElementById('fatModalPdfDl');
      if (v) v.onclick = () => abrirPdf(fid, true);
      if (d) d.onclick = () => abrirPdf(fid, false);
    }
    document.getElementById('faturaPrintModal').classList.add('active');
  } catch (e) {
    if (typeof showToast === 'function') showToast(e.message, 'warning');
  }
}

function closePrint() {
  document.getElementById('faturaPrintModal').classList.remove('active');
  const footer = document.querySelector('#faturaPrintModal .modal-footer-actions');
  if (footer) {
    footer.innerHTML =
      '<button type="button" id="faturaPrintBtn" class="btn btn-primary">Imprimir / PDF</button>' +
      '<button type="button" id="faturaPrintClose2" class="btn btn-secondary">Fechar</button>';
    document.getElementById('faturaPrintClose2').addEventListener('click', closePrint);
    document.getElementById('faturaPrintBtn').addEventListener('click', () => window.print());
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
    `${base}/faturacao?view=export-at&de=` +
    encodeURIComponent(de) +
    '&ate=' +
    encodeURIComponent(ate);
  window.open(url, '_blank');
}
