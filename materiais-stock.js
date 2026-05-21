/* global API, initAdminShell, showToast */

let ingredientes = [];
let pedidos = [];
let fornecedores = [];

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  }
  document.getElementById('novoIngBtn').addEventListener('click', criarIngrediente);
  document.getElementById('refreshBtn').addEventListener('click', load);
  document.getElementById('pedClose').addEventListener('click', closePed);
  document.getElementById('pedCancel').addEventListener('click', closePed);
  document.getElementById('pedSave').addEventListener('click', savePed);
  document.getElementById('pedFornModo').addEventListener('change', syncPedFornModo);
  document.getElementById('pedFornecedorId').addEventListener('change', updatePedFornEmailHint);
  document.getElementById('recClose').addEventListener('click', closeRec);
  document.getElementById('recCancel').addEventListener('click', closeRec);
  document.getElementById('recSave').addEventListener('click', saveRec);
  window.addEventListener('click', (e) => {
    if (e.target.id === 'pedModal') closePed();
    if (e.target.id === 'recModal') closeRec();
  });
  load();
});

function closePed() {
  document.getElementById('pedModal').classList.remove('active');
}

function closeRec() {
  document.getElementById('recModal').classList.remove('active');
  resetRecModal();
}

function resetRecModal() {
  document.querySelector('#recModal .modal-actions').style.display = '';
  document.getElementById('recLucroWrap').style.display = 'none';
}

function fmtEuro(n) {
  if (n == null || Number.isNaN(n)) return '—';
  return '€' + Number(n).toFixed(2);
}

function renderPedidosPendentesBar() {
  const bar = document.getElementById('pedidosPendentesBar');
  if (!bar) return;
  const n = pedidos.filter((p) => p.estado === 'pendente').length;
  if (!n) {
    bar.style.display = 'none';
    bar.innerHTML = '';
    return;
  }
  bar.style.display = 'block';
  bar.innerHTML =
    '<strong>' +
    n +
    ' pedido(s) pendente(s)</strong>. Ao receber, indica o <strong>valor total pago</strong> — só entra nas despesas. ' +
    '<a href="estatisticas.html#sec-lucro">Ver lucro em Estatísticas</a>';
}

async function criarIngrediente() {
  const nome = document.getElementById('novoIngNome').value.trim();
  const unidade = document.getElementById('novoIngUnidade').value.trim();
  const quantidade_atual = parseFloat(document.getElementById('novoIngAtual').value);
  const quantidade_minima = parseFloat(document.getElementById('novoIngMin').value);
  if (!nome) {
    showToast('Indica o nome do material', 'warning');
    return;
  }
  if (!unidade) {
    showToast('Indica a unidade (kg, g, L…)', 'warning');
    return;
  }
  if (Number.isNaN(quantidade_atual) || quantidade_atual < 0) {
    showToast('Stock inicial inválido', 'warning');
    return;
  }
  if (Number.isNaN(quantidade_minima) || quantidade_minima < 0) {
    showToast('Mínimo inválido', 'warning');
    return;
  }
  try {
    await API.createIngrediente({
      nome,
      unidade,
      quantidade_atual,
      quantidade_minima
    });
    showToast('Material criado', 'success');
    document.getElementById('novoIngNome').value = '';
    document.getElementById('novoIngUnidade').value = '';
    document.getElementById('novoIngAtual').value = '0';
    document.getElementById('novoIngMin').value = '0';
    load();
  } catch (e) {
    showToast(e.message || 'Erro', 'warning');
  }
}

function syncPedFornModo() {
  const modo = document.getElementById('pedFornModo').value;
  const reg = document.getElementById('pedFornRegistadoWrap');
  const outro = document.getElementById('pedFornOutroWrap');
  if (modo === 'outro') {
    reg.style.display = 'none';
    outro.style.display = '';
    document.getElementById('pedEmailFornecedor').value = '';
  } else {
    reg.style.display = '';
    outro.style.display = 'none';
    document.getElementById('pedEmailFornecedor').value = '';
    updatePedFornEmailHint();
  }
}

function updatePedFornEmailHint() {
  const hint = document.getElementById('pedFornEmailHint');
  if (!hint) return;
  const fid = document.getElementById('pedFornecedorId').value;
  const f = fornecedores.find((x) => String(x.fornecedor_id) === String(fid));
  if (!f) {
    hint.textContent = '';
    return;
  }
  if (f.email) {
    hint.textContent = 'Será enviado para: ' + f.email;
  } else {
    hint.textContent = 'Este fornecedor não tem email — edite em Fornecedores ou use «Outro fornecedor».';
    hint.style.color = 'var(--warn, #b45309)';
  }
}

function fillPedFornecedorSelect() {
  const sel = document.getElementById('pedFornecedorId');
  if (!sel) return;
  const cur = sel.value;
  sel.innerHTML =
    '<option value="">— Seleccionar —</option>' +
    fornecedores
      .map((f) => {
        const label = (f.empresa || 'Fornecedor') + (f.email ? ' (' + f.email + ')' : ' — sem email');
        return (
          '<option value="' +
          f.fornecedor_id +
          '"' +
          (f.email ? '' : ' data-sem-email="1"') +
          '>' +
          escapeHtml(label) +
          '</option>'
        );
      })
      .join('');
  if (cur) sel.value = cur;
}

function openPedModal(ing) {
  document.getElementById('pedIngId').value = ing.ingrediente_id;
  document.getElementById('pedIngNome').textContent = ing ? ing.nome : '';
  document.getElementById('pedQ').value = '';
  document.getElementById('pedNotas').value = '';
  document.getElementById('pedFornModo').value = 'registado';
  document.getElementById('pedFornecedorId').value = '';
  document.getElementById('pedEmailFornecedor').value = '';
  fillPedFornecedorSelect();
  syncPedFornModo();
  document.getElementById('pedModal').classList.add('active');
}

async function load() {
  try {
    const [ing, ped, forn] = await Promise.all([
      API.getIngredientes(),
      API.getPedidosIngrediente(),
      API.getFornecedores().catch(() => [])
    ]);
    fornecedores = Array.isArray(forn) ? forn : [];
    ingredientes = Array.isArray(ing) ? ing : [];
    pedidos = Array.isArray(ped) ? ped : [];
    renderIng(ingredientes);
    renderPed(pedidos);
    renderPedidosPendentesBar();
    const low = ingredientes.filter(
      (i) =>
        parseFloat(i.quantidade_minima) > 0 &&
        parseFloat(i.quantidade_atual) <= parseFloat(i.quantidade_minima)
    );
    const b = document.getElementById('alertBanner');
    if (low.length) {
      b.textContent =
        'Atenção: ' +
        low.map((x) => x.nome + ' em stock baixo').join(', ') +
        '.';
      b.style.display = 'block';
    } else {
      b.style.display = 'none';
    }
  } catch (e) {
    showToast(e.message || 'Erro', 'warning');
  }
}

function renderIng(list) {
  const tb = document.querySelector('#tblIng tbody');
  tb.innerHTML = list
    .map((i) => {
      const low =
        parseFloat(i.quantidade_minima) > 0 &&
        parseFloat(i.quantidade_atual) <= parseFloat(i.quantidade_minima);
      return (
        '<tr' +
        (low ? ' class="row-warn"' : '') +
        '><td>' +
        escapeHtml(i.nome) +
        '</td><td><input type="number" step="0.0001" class="qty-input" data-atual="' +
        i.ingrediente_id +
        '" value="' +
        i.quantidade_atual +
        '"></td><td><input type="number" step="0.0001" class="qty-input" data-min="' +
        i.ingrediente_id +
        '" value="' +
        i.quantidade_minima +
        '"></td><td>' +
        escapeHtml(i.unidade || '') +
        '</td><td class="col-actions"><div class="actions-group">' +
        '<button type="button" class="btn btn-success btn-sm" data-save="' +
        i.ingrediente_id +
        '">Guardar</button>' +
        '<button type="button" class="btn btn-info btn-sm" data-ped="' +
        i.ingrediente_id +
        '">Pedir</button>' +
        '<button type="button" class="btn btn-danger btn-sm" data-del="' +
        i.ingrediente_id +
        '">Apagar</button></div></td></tr>'
      );
    })
    .join('');

  tb.querySelectorAll('[data-save]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-save');
      const row = btn.closest('tr');
      const atual = parseFloat(row.querySelector('[data-atual="' + id + '"]').value);
      const min = parseFloat(row.querySelector('[data-min="' + id + '"]').value);
      const ing = ingredientes.find((x) => String(x.ingrediente_id) === String(id));
      try {
        await API.updateIngrediente(id, {
          nome: ing.nome,
          quantidade_atual: atual,
          quantidade_minima: min,
          unidade: ing.unidade
        });
        showToast('Stock actualizado', 'success');
        load();
      } catch (e) {
        showToast(e.message, 'warning');
      }
    });
  });

  tb.querySelectorAll('[data-ped]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-ped');
      const ing = ingredientes.find((x) => String(x.ingrediente_id) === String(id));
      openPedModal(ing || { ingrediente_id: id, nome: '' });
    });
  });

  tb.querySelectorAll('[data-del]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-del');
      const ing = ingredientes.find((x) => String(x.ingrediente_id) === String(id));
      const nome = ing ? ing.nome : 'este material';
      if (!confirm('Apagar «' + nome + '»? Os pedidos deste material também serão removidos.')) return;
      try {
        await API.deleteIngrediente(id);
        showToast('Material apagado', 'success');
        load();
      } catch (e) {
        showToast(e.message || 'Erro', 'warning');
      }
    });
  });
}

async function savePed() {
  const id = parseInt(document.getElementById('pedIngId').value, 10);
  const q = parseFloat(document.getElementById('pedQ').value);
  const notas = document.getElementById('pedNotas').value.trim();
  const modo = document.getElementById('pedFornModo').value;
  if (!id || !(q > 0)) {
    showToast('Quantidade inválida', 'warning');
    return;
  }
  const payload = { ingrediente_id: id, quantidade: q, notas: notas || null };
  if (modo === 'registado') {
    const fid = parseInt(document.getElementById('pedFornecedorId').value, 10);
    if (!fid) {
      showToast('Seleccione um fornecedor', 'warning');
      return;
    }
    const f = fornecedores.find((x) => x.fornecedor_id === fid);
    if (!f || !f.email) {
      showToast('Este fornecedor não tem email — use «Outro fornecedor» ou edite a ficha em Fornecedores', 'warning');
      return;
    }
    payload.fornecedor_id = fid;
  } else {
    const emailFornecedor = document.getElementById('pedEmailFornecedor').value.trim();
    if (!emailFornecedor) {
      showToast('Indique o email do fornecedor', 'warning');
      return;
    }
    payload.email_fornecedor = emailFornecedor;
  }
  try {
    const res = await API.createPedidoIngrediente(payload);
    showToast('Pedido registado', 'success');
    if (res && res.email_pedido && typeof showToast === 'function') {
      const ep = res.email_pedido;
      if (ep.fornecedor && ep.fornecedor.ok) showToast('Email ao fornecedor enviado.', 'success');
      else if (ep.fornecedor && ep.fornecedor.motivo && ep.fornecedor.motivo !== 'omitido') {
        showToast('Email fornecedor: ' + (ep.fornecedor.motivo === 'email_destino_invalido' ? 'email inválido' : ep.fornecedor.motivo), 'warning');
      }
      if (ep.admins && ep.admins.sent > 0) showToast('Aviso enviado a ' + ep.admins.sent + ' admin(s).', 'info');
      else if (ep.admins && ep.admins.last_result && ep.admins.last_result.motivo === 'sem_destinatarios') {
        showToast('Nenhum email de admin/gestor na base de dados para aviso.', 'warning');
      }
    }
    closePed();
    load();
  } catch (e) {
    showToast(e.message, 'warning');
  }
}

async function abrirFaturaPedido(p) {
  try {
    if (typeof API.openFaturacaoFicheiro === 'function') {
      await API.openFaturacaoFicheiro(p.ficheiro_id);
      return;
    }
    showToast('Sem PDF arquivado para este pedido', 'warning');
  } catch (e) {
    showToast(e.message || 'Erro ao abrir fatura', 'warning');
  }
}

function renderPed(list) {
  const tb = document.querySelector('#tblPed tbody');
  tb.innerHTML = list
    .map((p) => {
      const totalCell =
        p.estado === 'recebido' && p.valor_total != null && parseFloat(p.valor_total) > 0
          ? fmtEuro(p.valor_total)
          : '—';
      let faturaCell = '—';
      if (p.estado === 'recebido') {
        if (p.tem_ficheiro) {
          faturaCell =
            '<button type="button" class="btn btn-info btn-sm" data-abrir-fat="' +
            p.pedido_id +
            '">Abrir fatura</button>';
          if (p.num_fatura) {
            faturaCell += ' <span class="muted">' + escapeHtml(p.num_fatura) + '</span>';
          }
        } else {
          faturaCell =
            '<span class="muted">Sem PDF</span>' +
            (p.num_fatura ? ' · ' + escapeHtml(p.num_fatura) : '');
        }
      }
      return (
        '<tr><td>' +
        p.pedido_id +
        '</td><td>' +
        escapeHtml(p.ingrediente_nome || '') +
        '</td><td>' +
        p.quantidade +
        '</td><td>' +
        escapeHtml(p.email_fornecedor || '—') +
        '</td><td>' +
        escapeHtml(p.estado) +
        '</td><td>' +
        totalCell +
        '</td><td>' +
        faturaCell +
        '</td><td>' +
        escapeHtml((p.criado_em || '').substring(0, 16)) +
        '</td><td class="col-actions"><div class="actions-group">' +
        (p.estado === 'pendente'
          ? '<button class="btn btn-success btn-sm" data-rec="' +
            p.pedido_id +
            '">Receber</button><button class="btn btn-secondary btn-sm" data-can="' +
            p.pedido_id +
            '">Cancelar</button>'
          : '<span class="muted">—</span>') +
        '</div></td></tr>'
      );
    })
    .join('');

  tb.querySelectorAll('[data-rec]').forEach((b) =>
    b.addEventListener('click', () => {
      const pid = b.getAttribute('data-rec');
      const ped = list.find((x) => String(x.pedido_id) === String(pid));
      resetRecModal();
      document.getElementById('recPedId').value = pid;
      document.getElementById('recPedInfo').textContent = ped
        ? ped.ingrediente_nome + ' — ' + ped.quantidade + ' ' + (ped.unidade || '')
        : '';
      document.getElementById('recValorTotal').value = '';
      document.getElementById('recFatura').value = '';
      const pdfEl = document.getElementById('recPdf');
      if (pdfEl) pdfEl.value = '';
      document.getElementById('recModal').classList.add('active');
    })
  );
  tb.querySelectorAll('[data-abrir-fat]').forEach((b) => {
    b.addEventListener('click', () => {
      const ped = list.find((x) => String(x.pedido_id) === b.getAttribute('data-abrir-fat'));
      if (ped) abrirFaturaPedido(ped);
    });
  });
  tb.querySelectorAll('[data-can]').forEach((b) =>
    b.addEventListener('click', async () => {
      if (!confirm('Cancelar este pedido?')) return;
      try {
        await API.updatePedidoIngrediente(b.getAttribute('data-can'), { estado: 'cancelado' });
        showToast('Pedido cancelado', 'success');
        load();
      } catch (e) {
        showToast(e.message, 'warning');
      }
    })
  );
}

async function saveRec() {
  const id = document.getElementById('recPedId').value;
  const vt = parseFloat(document.getElementById('recValorTotal').value);
  const num_fatura = document.getElementById('recFatura').value.trim();
  const pdfInput = document.getElementById('recPdf');
  const pdfFile = pdfInput && pdfInput.files && pdfInput.files[0] ? pdfInput.files[0] : null;
  if (Number.isNaN(vt) || vt <= 0) {
    showToast('Indique o valor total pago (€)', 'warning');
    return;
  }
  if (!pdfFile) {
    showToast('Anexe o PDF da fatura do fornecedor', 'warning');
    return;
  }
  const payload = { estado: 'recebido', valor_total: vt };
  if (num_fatura) payload.num_fatura = num_fatura;
  try {
    const res = await API.updatePedidoIngredienteRecebido(id, payload, pdfFile);
    showToast('Pedido recebido — compra e PDF arquivados', 'success');
    if (res.ficheiro_id) {
      await API.openFaturacaoFicheiro(res.ficheiro_id);
    }
    document.querySelector('#recModal .modal-actions').style.display = 'none';
    document.getElementById('recLucroWrap').style.display = 'block';
    await load();
  } catch (e) {
    showToast(e.message, 'warning');
  }
}

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}
