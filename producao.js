/* global API, initAdminShell, showToast */

function isElevatedPainel() {
  return localStorage.getItem('adminIsAdmin') === 'true';
}

async function refreshRoleFromServer() {
  if (typeof API === 'undefined' || !API.getSessionInfo) return;
  try {
    const s = await API.getSessionInfo();
    if (s && s.logged_in) {
      localStorage.setItem('adminIsAdmin', s.is_admin ? 'true' : 'false');
      localStorage.setItem('adminFuncionarioId', String(s.funcionario_id || ''));
    }
  } catch (e) {}
}

function applyProducaoIntroAndSections() {
  const intro = document.getElementById('producaoIntro');
  const elevated = isElevatedPainel();
  if (intro) {
    intro.textContent = elevated
      ? 'Executa receitas em lote (desconta matérias e aumenta stock do produto) ou, se precisares, regista manualmente unidades extra (só admin).'
      : 'Executa uma receita em lote: o sistema desconta as matérias-primas e aumenta o stock do produto automaticamente. A tabela de stock em cima é só para consulta.';
  }
  const noteEmp = document.getElementById('producaoStockNoteEmp');
  const noteAdm = document.getElementById('producaoStockNoteAdmin');
  if (noteEmp) noteEmp.style.display = elevated ? 'none' : 'block';
  if (noteAdm) noteAdm.style.display = elevated ? 'block' : 'none';
}

document.addEventListener('DOMContentLoaded', async () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  }
  await refreshRoleFromServer();
  applyProducaoIntroAndSections();
  document.getElementById('refreshBtn').addEventListener('click', loadAll);
  loadAll();
});

async function loadAll() {
  const banner = document.getElementById('alertBanner');
  banner.style.display = 'none';
  banner.textContent = '';

  try {
    const d = await API.getProducaoResumo();
    renderAlerts(d);
    renderProdutos(d.produtos || []);
    renderReceitas(d.receitas || []);
  } catch (e) {
    showToast('Erro ao carregar produção: ' + (e.message || e), 'warning');
    if (String(e.message || '').indexOf('404') !== -1 || String(e.message || '').indexOf('500') !== -1) {
      banner.textContent =
        'Se acabou de fazer deploy, execute na Vercel o script de migração: /api/migrate_007_stock.php';
      banner.style.display = 'block';
    }
  }
}

function renderAlerts(d) {
  const banner = document.getElementById('alertBanner');
  const pa = d.alertas_produto || [];
  const ia = d.alertas_ingrediente || [];
  if (pa.length === 0 && ia.length === 0) {
    return;
  }
  const parts = [];
  if (pa.length) {
    parts.push(
      'Produtos com stock baixo: ' +
        pa.map((x) => x.nome + ' (' + x.stock_atual + '/' + x.stock_minimo + ')').join(', ')
    );
  }
  if (ia.length) {
    parts.push(
      'Matérias baixas (avisar admin): ' +
        ia.map((x) => x.nome + ' (' + x.quantidade_atual + x.unidade + ')').join(', ')
    );
  }
  banner.textContent = parts.join(' | ');
  banner.style.display = 'block';
}

function renderProdutos(list) {
  const tb = document.querySelector('#tblProdutos tbody');
  const headRow = document.getElementById('tblProdutosHead');
  if (!tb || !headRow) return;
  const elevated = isElevatedPainel();
  headRow.innerHTML = elevated
    ? '<th>Produto</th><th>Stock</th><th>Mín.</th><th>+ Unidades</th><th></th>'
    : '<th>Produto</th><th>Stock actual</th><th>Stock mínimo</th>';

  tb.innerHTML = list
    .map((p) => {
      const id = p.produto_id;
      const low =
        parseInt(p.stock_minimo, 10) > 0 &&
        parseInt(p.stock_atual, 10) <= parseInt(p.stock_minimo, 10);
      const base =
        '<tr' +
        (low ? ' class="row-warn"' : '') +
        '><td>' +
        escapeHtml(p.nome || '') +
        '</td><td>' +
        (p.stock_atual ?? 0) +
        '</td><td>' +
        (p.stock_minimo ?? 0) +
        '</td>';
      if (!elevated) {
        return base + '</tr>';
      }
      return (
        base +
        '<td><input type="number" min="1" step="1" value="1" id="add-' +
        id +
        '" class="qty-input"></td><td><button type="button" class="btn btn-success btn-sm" data-prod="' +
        id +
        '">Registar</button></td></tr>'
      );
    })
    .join('');

  if (!elevated) return;

  tb.querySelectorAll('button[data-prod]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const pid = parseInt(btn.getAttribute('data-prod'), 10);
      const inp = document.getElementById('add-' + pid);
      const q = parseInt(inp && inp.value, 10) || 0;
      if (q <= 0) {
        showToast('Indique uma quantidade válida', 'warning');
        return;
      }
      try {
        await API.postProducao({ action: 'incrementar_produto', produto_id: pid, quantidade: q });
        showToast('Stock actualizado', 'success');
        loadAll();
      } catch (e) {
        showToast(e.message || 'Erro', 'warning');
      }
    });
  });
}

function renderReceitas(list) {
  const el = document.getElementById('receitasCards');
  if (!list.length) {
    el.innerHTML = '<p class="muted">Nenhuma receita activa. O admin pode criar em «Receitas».</p>';
    return;
  }
  el.innerHTML = list
    .map((r) => {
      const ings = (r.ingredientes || [])
        .map((x) => '<li>' + escapeHtml(x.ingrediente_nome) + ': ' + x.quantidade + ' ' + escapeHtml(x.unidade || '') + '</li>')
        .join('');
      return (
        '<div class="info-card">' +
        '<h4>' +
        escapeHtml(r.nome) +
        '</h4>' +
        '<p class="muted">Produto: <strong>' +
        escapeHtml(r.produto_nome || '') +
        '</strong> · Rendimento: <strong>' +
        r.rendimento +
        '</strong> unidades por execução</p>' +
        '<ul class="recipe-ing-list">' +
        ings +
        '</ul>' +
        '<div class="recipe-actions">' +
        '<label>Vezes <input type="number" min="1" max="500" value="1" id="rx-' +
        r.receita_id +
        '" class="qty-input"></label> ' +
        '<button type="button" class="btn btn-success" data-rx="' +
        r.receita_id +
        '">Executar receita</button>' +
        '</div></div>'
      );
    })
    .join('');

  el.querySelectorAll('button[data-rx]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const rid = parseInt(btn.getAttribute('data-rx'), 10);
      const inp = document.getElementById('rx-' + rid);
      const vezes = Math.max(1, parseInt(inp && inp.value, 10) || 1);
      if (!confirm('Confirmar execução da receita ' + vezes + ' vez(es)? As matérias serão descontadas.')) return;
      try {
        await API.postProducao({ action: 'executar_receita', receita_id: rid, vezes: vezes });
        showToast('Receita executada com sucesso', 'success');
        loadAll();
      } catch (e) {
        showToast(e.message || 'Erro', 'warning');
      }
    });
  });
}

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}
