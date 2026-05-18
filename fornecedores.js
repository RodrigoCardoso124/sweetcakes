/* global API, initAdminShell, showToast */

let fornecedores = [];

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  }
  document.getElementById('refreshBtn').addEventListener('click', loadFornecedores);
  document.getElementById('addFornecedorBtn').addEventListener('click', () => openModal());
  document.getElementById('cancelFornecedorBtn').addEventListener('click', closeModal);
  document.querySelector('#fornecedorModal .close').addEventListener('click', closeModal);
  document.getElementById('fornecedorForm').addEventListener('submit', saveFornecedor);
  loadFornecedores();
});

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}

async function loadFornecedores() {
  const tb = document.querySelector('#tblFornecedores tbody');
  tb.innerHTML = '<tr><td colspan="6" class="loading">A carregar...</td></tr>';
  try {
    fornecedores = await API.getFornecedores();
    if (!fornecedores.length) {
      tb.innerHTML = '<tr><td colspan="6" class="table-empty-msg">Nenhum fornecedor registado.</td></tr>';
      return;
    }
    tb.innerHTML = fornecedores
      .map((f) => {
        return (
          '<tr><td>#' +
          f.fornecedor_id +
          '</td><td><strong>' +
          escapeHtml(f.empresa) +
          '</strong></td><td>' +
          escapeHtml(f.contacto_nome || '—') +
          '</td><td>' +
          escapeHtml(f.email || '—') +
          '</td><td>' +
          escapeHtml(f.telemovel || '—') +
          '</td><td class="table-actions">' +
          '<button type="button" class="btn btn-secondary btn-sm" data-edit-forn="' +
          f.fornecedor_id +
          '">Editar</button> ' +
          '<button type="button" class="btn btn-danger btn-sm" data-del-forn="' +
          f.fornecedor_id +
          '">Apagar</button></td></tr>'
        );
      })
      .join('');
    tb.querySelectorAll('[data-edit-forn]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = parseInt(btn.getAttribute('data-edit-forn'), 10);
        const row = fornecedores.find((x) => x.fornecedor_id === id);
        if (row) openModal(row);
      });
    });
    tb.querySelectorAll('[data-del-forn]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('Apagar este fornecedor?')) return;
        try {
          await API.deleteFornecedor(btn.getAttribute('data-del-forn'));
          if (typeof showToast === 'function') showToast('Fornecedor removido', 'success');
          loadFornecedores();
        } catch (e) {
          if (typeof showToast === 'function') showToast(e.message, 'warning');
        }
      });
    });
  } catch (e) {
    tb.innerHTML = '<tr><td colspan="6" class="error-container">Erro: ' + escapeHtml(e.message) + '</td></tr>';
  }
}

function openModal(row) {
  document.getElementById('fornecedorModalTitle').textContent = row ? 'Editar fornecedor' : 'Novo fornecedor';
  document.getElementById('fornecedorId').value = row ? row.fornecedor_id : '';
  document.getElementById('fornEmpresa').value = row ? row.empresa || '' : '';
  document.getElementById('fornContacto').value = row ? row.contacto_nome || '' : '';
  document.getElementById('fornEmail').value = row ? row.email || '' : '';
  document.getElementById('fornTelemovel').value = row ? row.telemovel || '' : '';
  document.getElementById('fornecedorModal').classList.add('active');
}

function closeModal() {
  document.getElementById('fornecedorModal').classList.remove('active');
}

async function saveFornecedor(e) {
  e.preventDefault();
  const id = document.getElementById('fornecedorId').value;
  const payload = {
    empresa: document.getElementById('fornEmpresa').value.trim(),
    nome_contato: document.getElementById('fornContacto').value.trim(),
    email: document.getElementById('fornEmail').value.trim(),
    telemovel: document.getElementById('fornTelemovel').value.trim()
  };
  if (!payload.empresa) {
    if (typeof showToast === 'function') showToast('Empresa é obrigatória', 'warning');
    return;
  }
  try {
    if (id) {
      await API.updateFornecedor(id, payload);
      if (typeof showToast === 'function') showToast('Fornecedor actualizado', 'success');
    } else {
      await API.createFornecedor(payload);
      if (typeof showToast === 'function') showToast('Fornecedor criado', 'success');
    }
    closeModal();
    loadFornecedores();
  } catch (err) {
    if (typeof showToast === 'function') showToast(err.message, 'warning');
  }
}
