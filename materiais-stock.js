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
  window.addEventListener('click', (e) => {
    if (e.target.id === 'pedModal') closePed();
  });
  load();
});

function closePed() {
  document.getElementById('pedModal').classList.remove('active');
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
        '</td><td><button type="button" class="btn btn-primary btn-sm" data-save="' +
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
      document.getElementById('pedIngId').value = id;
      document.getElementById('pedIngNome').textContent = ing ? ing.nome : '';
      document.getElementById('pedQ').value = '';
      document.getElementById('pedNotas').value = '';
      document.getElementById('pedModal').classList.add('active');
    });
  });
}

async function savePed() {
  const id = parseInt(document.getElementById('pedIngId').value, 10);
  const q = parseFloat(document.getElementById('pedQ').value);
  const notas = document.getElementById('pedNotas').value.trim();
  if (!id || !(q > 0)) {
    showToast('Quantidade inválida', 'warning');
    return;
  }
  try {
    await API.createPedidoIngrediente({ ingrediente_id: id, quantidade: q, notas: notas || null });
    showToast('Pedido registado', 'success');
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
        escapeHtml(p.estado) +
        '</td><td>' +
        escapeHtml((p.criado_em || '').substring(0, 16)) +
        '</td><td>' +
        (p.estado === 'pendente'
          ? '<button class="btn btn-primary btn-sm" data-rec="' +
            p.pedido_id +
            '">Marcar recebido</button> <button class="btn btn-secondary btn-sm" data-can="' +
            p.pedido_id +
            '">Cancelar</button>'
          : '') +
        '</td></tr>'
      );
    })
    .join('');

  tb.querySelectorAll('[data-rec]').forEach((b) =>
    b.addEventListener('click', async () => {
      try {
        await API.updatePedidoIngrediente(b.getAttribute('data-rec'), { estado: 'recebido' });
        showToast('Stock aumentado com a quantidade pedida', 'success');
        load();
      } catch (e) {
        showToast(e.message, 'warning');
      }
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

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}
