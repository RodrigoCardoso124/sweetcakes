/* global API, initAdminShell, showToast */

let ingredientes = [];

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  }
  document.getElementById('novoIngBtn').addEventListener('click', criarIngrediente);
  document.getElementById('refreshBtn').addEventListener('click', load);
  document.getElementById('pedClose').addEventListener('click', closePed);
  document.getElementById('pedCancel').addEventListener('click', closePed);
  document.getElementById('pedSave').addEventListener('click', savePed);
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
    const preco_unitario = parseFloat(document.getElementById('novoIngPreco').value) || 0;
    await API.createIngrediente({
      nome,
      unidade,
      quantidade_atual,
      quantidade_minima,
      preco_unitario
    });
    showToast('Material criado', 'success');
    document.getElementById('novoIngNome').value = '';
    document.getElementById('novoIngUnidade').value = '';
    document.getElementById('novoIngAtual').value = '0';
    document.getElementById('novoIngMin').value = '0';
    document.getElementById('novoIngPreco').value = '0';
    load();
  } catch (e) {
    showToast(e.message || 'Erro', 'warning');
  }
}

async function load() {
  try {
    const [ing, ped] = await Promise.all([
      API.getIngredientes(),
      API.getPedidosIngrediente()
    ]);
    ingredientes = Array.isArray(ing) ? ing : [];
    renderIng(ingredientes);
    renderPed(Array.isArray(ped) ? ped : []);
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
        '</td><td><input type="number" step="0.0001" min="0" class="qty-input" data-preco="' +
        i.ingrediente_id +
        '" value="' +
        (i.preco_unitario != null ? i.preco_unitario : 0) +
        '"></td><td><button type="button" class="btn btn-primary btn-sm" data-save="' +
        i.ingrediente_id +
        '">Guardar</button></td><td><button type="button" class="btn btn-secondary btn-sm" data-ped="' +
        i.ingrediente_id +
        '">Pedir</button></td></tr>'
      );
    })
    .join('');

  tb.querySelectorAll('[data-save]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-save');
      const row = btn.closest('tr');
      const atual = parseFloat(row.querySelector('[data-atual="' + id + '"]').value);
      const min = parseFloat(row.querySelector('[data-min="' + id + '"]').value);
      const precoEl = row.querySelector('[data-preco="' + id + '"]');
      const preco_unitario = precoEl ? parseFloat(precoEl.value) : 0;
      const ing = ingredientes.find((x) => String(x.ingrediente_id) === String(id));
      try {
        await API.updateIngrediente(id, {
          nome: ing.nome,
          quantidade_atual: atual,
          quantidade_minima: min,
          unidade: ing.unidade,
          preco_unitario: Number.isNaN(preco_unitario) ? 0 : preco_unitario
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
      document.getElementById('pedIngId').value = id;
      document.getElementById('pedIngNome').textContent = ing ? ing.nome : '';
      document.getElementById('pedQ').value = '';
      document.getElementById('pedNotas').value = '';
      document.getElementById('pedEmailFornecedor').value = '';
      document.getElementById('pedModal').classList.add('active');
    });
  });
}

async function savePed() {
  const id = parseInt(document.getElementById('pedIngId').value, 10);
  const q = parseFloat(document.getElementById('pedQ').value);
  const notas = document.getElementById('pedNotas').value.trim();
  const emailFornecedor = document.getElementById('pedEmailFornecedor').value.trim();
  if (!id || !(q > 0)) {
    showToast('Quantidade inválida', 'warning');
    return;
  }
  try {
    const payload = { ingrediente_id: id, quantidade: q, notas: notas || null };
    if (emailFornecedor) payload.email_fornecedor = emailFornecedor;
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

function renderPed(list) {
  const tb = document.querySelector('#tblPed tbody');
  tb.innerHTML = list
    .map((p) => {
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
        (p.valor_total != null ? '€' + Number(p.valor_total).toFixed(2) : '—') +
        '</td><td>' +
        escapeHtml((p.criado_em || '').substring(0, 16)) +
        '</td><td>' +
        (p.estado === 'pendente'
          ? '<button class="btn btn-primary btn-sm" data-rec="' +
            p.pedido_id +
            '">Receber</button> <button class="btn btn-secondary btn-sm" data-can="' +
            p.pedido_id +
            '">Cancelar</button>'
          : '') +
        '</td></tr>'
      );
    })
    .join('');

  tb.querySelectorAll('[data-rec]').forEach((b) =>
    b.addEventListener('click', () => {
      const pid = b.getAttribute('data-rec');
      const ped = list.find((x) => String(x.pedido_id) === String(pid));
      document.getElementById('recPedId').value = pid;
      document.getElementById('recPedInfo').textContent = ped
        ? ped.ingrediente_nome + ' — ' + ped.quantidade + ' ' + (ped.unidade || '')
        : '';
      document.getElementById('recPrecoUnit').value =
        ped && ped.preco_unitario_compra != null ? ped.preco_unitario_compra : '';
      document.getElementById('recValorTotal').value = '';
      document.getElementById('recFatura').value = '';
      document.getElementById('recModal').classList.add('active');
    })
  );
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
  const puc = parseFloat(document.getElementById('recPrecoUnit').value);
  const vtRaw = document.getElementById('recValorTotal').value;
  const vt = vtRaw !== '' ? parseFloat(vtRaw) : null;
  const num_fatura = document.getElementById('recFatura').value.trim();
  if (!(puc >= 0) || Number.isNaN(puc)) {
    showToast('Indique o preço unitário pago', 'warning');
    return;
  }
  const payload = { estado: 'recebido', preco_unitario_compra: puc };
  if (vt != null && vt > 0) payload.valor_total = vt;
  if (num_fatura) payload.num_fatura = num_fatura;
  try {
    await API.updatePedidoIngrediente(id, payload);
    showToast('Pedido recebido — stock e preço actualizados', 'success');
    closeRec();
    load();
  } catch (e) {
    showToast(e.message, 'warning');
  }
}

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}
