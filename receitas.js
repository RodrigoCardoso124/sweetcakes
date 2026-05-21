/* global API, initAdminShell, showToast */

let produtos = [];
let ingredientes = [];
let receitas = [];

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  }
  document.getElementById('refreshBtn').addEventListener('click', load);
  document.getElementById('addBtn').addEventListener('click', () => openModal(null));
  document.getElementById('modalClose').addEventListener('click', closeModal);
  document.getElementById('cancelBtn').addEventListener('click', closeModal);
  document.getElementById('addLinha').addEventListener('click', () => addLinhaRow());
  document.getElementById('form').addEventListener('submit', save);
  window.addEventListener('click', (e) => {
    if (e.target.id === 'modal') closeModal();
  });
  load();
});

function closeModal() {
  document.getElementById('modal').classList.remove('active');
}

function openModal(rec) {
  document.getElementById('modal').classList.add('active');
  document.getElementById('modalTitle').textContent = rec ? 'Editar receita' : 'Nova receita';
  document.getElementById('receitaId').value = rec ? rec.receita_id : '';
  document.getElementById('nome').value = rec ? rec.nome || '' : '';
  document.getElementById('produtoId').innerHTML = produtos
    .map((p) => '<option value="' + p.produto_id + '">' + escapeHtml(p.nome) + '</option>')
    .join('');
  if (rec) document.getElementById('produtoId').value = String(rec.produto_id);
  document.getElementById('rendimento').value = rec ? rec.rendimento || 1 : 1;
  document.getElementById('ativo').checked = rec ? !!parseInt(rec.ativo, 10) : true;
  document.getElementById('notas').value = rec && rec.notas ? rec.notas : '';
  const linhas = document.getElementById('linhas');
  linhas.innerHTML = '';
  if (rec && rec.ingredientes && rec.ingredientes.length) {
    rec.ingredientes.forEach((x) =>
      addLinhaRow(x.ingrediente_id, x.quantidade)
    );
  } else {
    addLinhaRow();
  }
}

function addLinhaRow(ingId, qtd) {
  const wrap = document.createElement('div');
  wrap.className = 'form-row-2 receita-linha';
  const opts = ingredientes
    .map(
      (i) =>
        '<option value="' +
        i.ingrediente_id +
        '"' +
        (ingId && String(i.ingrediente_id) === String(ingId) ? ' selected' : '') +
        '>' +
        escapeHtml(i.nome) +
        ' (' +
        escapeHtml(i.unidade || '') +
        ')</option>'
    )
    .join('');
  wrap.innerHTML =
    '<div class="form-group"><label>Ingrediente</label><select class="ing-sel">' +
    opts +
    '</select></div>' +
    '<div class="form-group"><label>Quantidade por execução</label><input type="number" class="ing-q" min="0.0001" step="0.0001" value="' +
    (qtd != null ? qtd : '') +
    '"></div>';
  document.getElementById('linhas').appendChild(wrap);
}

async function load() {
  try {
    const [r, p, ing] = await Promise.all([
      API.getReceitas(),
      API.getProdutos(),
      API.getIngredientes()
    ]);
    receitas = Array.isArray(r) ? r : [];
    produtos = Array.isArray(p) ? p : [];
    ingredientes = Array.isArray(ing) ? ing : [];
    renderTable();
  } catch (e) {
    showToast(e.message || 'Erro ao carregar', 'warning');
  }
}

function renderTable() {
  const tb = document.querySelector('#tbl tbody');
  tb.innerHTML = receitas
    .map((x) => {
      return (
        '<tr><td>' +
        escapeHtml(x.nome) +
        '</td><td>' +
        escapeHtml(x.produto_nome || '') +
        '</td><td>' +
        x.rendimento +
        '</td><td>' +
        (parseInt(x.ativo, 10) ? 'Sim' : 'Não') +
        '</td><td class="col-actions"><div class="actions-group"><button class="btn btn-warning btn-sm" data-edit="' +
        x.receita_id +
        '">Editar</button><button class="btn btn-danger btn-sm" data-del="' +
        x.receita_id +
        '">Apagar</button></div></td></tr>'
      );
    })
    .join('');

  tb.querySelectorAll('[data-edit]').forEach((b) =>
    b.addEventListener('click', async () => {
      const id = b.getAttribute('data-edit');
      try {
        const full = await API.getReceita(id);
        openModal(full);
      } catch (e) {
        showToast(e.message, 'warning');
      }
    })
  );
  tb.querySelectorAll('[data-del]').forEach((b) =>
    b.addEventListener('click', async () => {
      if (!confirm('Apagar esta receita?')) return;
      try {
        await API.deleteReceita(b.getAttribute('data-del'));
        showToast('Removida', 'success');
        load();
      } catch (e) {
        showToast(e.message, 'warning');
      }
    })
  );
}

async function save(e) {
  e.preventDefault();
  const id = document.getElementById('receitaId').value;
  const linhas = [];
  document.querySelectorAll('.receita-linha').forEach((row) => {
    const iid = parseInt(row.querySelector('.ing-sel').value, 10);
    const q = parseFloat(row.querySelector('.ing-q').value);
    if (iid > 0 && q > 0) linhas.push({ ingrediente_id: iid, quantidade: q });
  });
  const payload = {
    nome: document.getElementById('nome').value.trim(),
    produto_id: parseInt(document.getElementById('produtoId').value, 10),
    rendimento: parseInt(document.getElementById('rendimento').value, 10) || 1,
    ativo: document.getElementById('ativo').checked ? 1 : 0,
    notas: document.getElementById('notas').value.trim(),
    ingredientes: linhas
  };
  try {
    if (id) await API.updateReceita(id, payload);
    else await API.createReceita(payload);
    showToast('Guardado', 'success');
    closeModal();
    load();
  } catch (err) {
    showToast(err.message || 'Erro', 'warning');
  }
}

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}
