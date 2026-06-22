/* global API, initAdminShell, showToast */

let produtos = [];
let ingredientes = [];
let receitas = [];
let catalogLoading = null;

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

function ensureArray(value) {
  if (Array.isArray(value)) return value;
  if (value && Array.isArray(value.data)) return value.data;
  return [];
}

function closeModal() {
  document.getElementById('modal').classList.remove('active');
}

async function loadCatalog() {
  if (catalogLoading) return catalogLoading;
  catalogLoading = (async () => {
    const [pRes, ingRes] = await Promise.allSettled([
      API.getProdutos(),
      API.getIngredientes()
    ]);
    if (pRes.status === 'fulfilled') {
      produtos = ensureArray(pRes.value);
    } else {
      produtos = [];
      showToast(
        pRes.reason?.message || 'Não foi possível carregar os produtos.',
        'warning'
      );
    }
    if (ingRes.status === 'fulfilled') {
      ingredientes = ensureArray(ingRes.value);
    } else {
      ingredientes = [];
      showToast(
        ingRes.reason?.message || 'Não foi possível carregar os ingredientes.',
        'warning'
      );
    }
  })();
  try {
    await catalogLoading;
  } finally {
    catalogLoading = null;
  }
}

function fillProdutoSelect(selectedId) {
  const sel = document.getElementById('produtoId');
  if (!produtos.length) {
    sel.innerHTML =
      '<option value="">— Cria produtos em «Produtos» primeiro —</option>';
    sel.disabled = true;
    return;
  }
  sel.disabled = false;
  sel.innerHTML = produtos
    .map(
      (p) =>
        '<option value="' +
        p.produto_id +
        '">' +
        escapeHtml(p.nome) +
        '</option>'
    )
    .join('');
  if (selectedId) sel.value = String(selectedId);
}

function ingredientOptionsHtml(selectedId) {
  if (!ingredientes.length) {
    return '<option value="">— Regista matérias-primas em «Matérias-primas» —</option>';
  }
  return ingredientes
    .map(
      (i) =>
        '<option value="' +
        i.ingrediente_id +
        '"' +
        (selectedId && String(i.ingrediente_id) === String(selectedId)
          ? ' selected'
          : '') +
        '>' +
        escapeHtml(i.nome) +
        ' (' +
        escapeHtml(i.unidade || '') +
        ')</option>'
    )
    .join('');
}

async function openModal(rec) {
  document.getElementById('modal').classList.add('active');
  document.getElementById('modalTitle').textContent = rec ? 'Editar receita' : 'Nova receita';
  document.getElementById('receitaId').value = rec ? rec.receita_id : '';
  document.getElementById('nome').value = rec ? rec.nome || '' : '';

  await loadCatalog();
  fillProdutoSelect(rec ? rec.produto_id : null);

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
  const selDisabled = ingredientes.length ? '' : ' disabled';
  wrap.innerHTML =
    '<div class="form-group"><label>Ingrediente</label><select class="ing-sel form-select"' +
    selDisabled +
    '>' +
    ingredientOptionsHtml(ingId) +
    '</select></div>' +
    '<div class="form-group"><label>Quantidade por execução</label><input type="number" class="ing-q" min="0.0001" step="0.0001" value="' +
    (qtd != null ? qtd : '') +
    '"></div>';
  document.getElementById('linhas').appendChild(wrap);
}

async function load() {
  const [rRes, pRes, ingRes] = await Promise.allSettled([
    API.getReceitas(),
    API.getProdutos(),
    API.getIngredientes()
  ]);

  if (rRes.status === 'fulfilled') {
    receitas = ensureArray(rRes.value);
  } else {
    receitas = [];
    showToast(rRes.reason?.message || 'Erro ao carregar receitas', 'warning');
  }

  if (pRes.status === 'fulfilled') {
    produtos = ensureArray(pRes.value);
  } else {
    produtos = [];
  }

  if (ingRes.status === 'fulfilled') {
    ingredientes = ensureArray(ingRes.value);
  } else {
    ingredientes = [];
  }

  renderTable();
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
  if (!produtos.length) {
    showToast('Não há produtos no catálogo.', 'warning');
    return;
  }
  if (!ingredientes.length) {
    showToast('Regista matérias-primas antes de criar a receita.', 'warning');
    return;
  }
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
