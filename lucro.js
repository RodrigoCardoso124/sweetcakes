/* global API, initAdminShell, showToast */

let produtosLucro = [];

function fmtEuro(n) {
  if (n == null || Number.isNaN(n)) return '—';
  return '€' + Number(n).toFixed(2);
}

function periodo() {
  return {
    de: document.getElementById('filtroDe').value,
    ate: document.getElementById('filtroAte').value
  };
}

function setDefaultDates() {
  const ate = new Date();
  const de = new Date(ate.getFullYear(), ate.getMonth(), 1);
  document.getElementById('filtroDe').value = de.toISOString().slice(0, 10);
  document.getElementById('filtroAte').value = ate.toISOString().slice(0, 10);
  document.getElementById('despData').value = ate.toISOString().slice(0, 10);
}

document.addEventListener('DOMContentLoaded', () => {
  if (typeof initAdminShell === 'function') {
    if (initAdminShell() === false) return;
  }
  setDefaultDates();
  document.getElementById('aplicarBtn').addEventListener('click', loadAll);
  document.getElementById('refreshBtn').addEventListener('click', loadAll);
  document.getElementById('recalcBtn').addEventListener('click', recalcular);
  document.getElementById('exportCsvBtn').addEventListener('click', exportCsv);
  document.getElementById('despAddBtn').addEventListener('click', addDespesa);
  loadAll();
});

async function recalcular() {
  try {
    await API.recalcularCustosProdutos();
    showToast('Custos dos produtos actualizados', 'success');
    loadAll();
  } catch (e) {
    showToast(e.message || 'Erro', 'warning');
  }
}

async function loadAll() {
  const { de, ate } = periodo();
  const banner = document.getElementById('migrateBanner');
  try {
    const [resumo, produtos, despesas] = await Promise.all([
      API.getFinancasResumo(de, ate),
      API.getFinancasProdutos(de, ate),
      API.getDespesas(de, ate)
    ]);
    banner.style.display = 'none';
    renderResumo(resumo);
    produtosLucro = produtos.produtos || [];
    renderProdutos(produtosLucro);
    renderDespesas(despesas);
  } catch (e) {
    const msg = e.message || String(e);
    if (msg.indexOf('500') !== -1 || msg.indexOf('preco_unitario') !== -1 || msg.indexOf('despesas') !== -1) {
      banner.textContent =
        'Execute a migração 008 no servidor: /api/migrate_008_financas.php';
      banner.style.display = 'block';
    }
    showToast('Erro ao carregar finanças: ' + msg, 'warning');
  }
}

function renderResumo(r) {
  document.getElementById('cardReceita').textContent = fmtEuro(r.receita && r.receita.total);
  document.getElementById('cardCustoVendido').textContent = fmtEuro(
    r.custos && r.custos.custo_estimado_vendido
  );
  document.getElementById('cardLucroBruto').textContent = fmtEuro(
    r.lucro && r.lucro.bruto_margem_vendido
  );
  document.getElementById('cardCaixa').textContent = fmtEuro(r.lucro && r.lucro.liquido_caixa);
  const n = r.notas || {};
  const parts = [];
  if (n.linhas_sem_custo > 0) {
    parts.push(n.linhas_sem_custo + ' linha(s) sem custo (falta receita ou preço material)');
  }
  if (n.linhas_sem_preco > 0) {
    parts.push(n.linhas_sem_preco + ' linha(s) com preço estimado do catálogo');
  }
  parts.push(
    'Compras materiais no período: ' +
      fmtEuro(r.custos && r.custos.compras_materiais_recebidas) +
      ' · Despesas: ' +
      fmtEuro(r.custos && r.custos.despesas_gerais)
  );
  document.getElementById('notasResumo').textContent = parts.join(' · ');
}

function renderProdutos(list) {
  const tb = document.querySelector('#tblProdutos tbody');
  if (!list.length) {
    tb.innerHTML =
      '<tr><td colspan="6" class="table-empty-msg">Sem vendas no período (encomendas entregues / balcão).</td></tr>';
    return;
  }
  tb.innerHTML = list
    .map((p) => {
      const marg = p.margem_percent != null ? p.margem_percent + '%' : '—';
      return (
        '<tr><td>' +
        escapeHtml(p.nome) +
        '</td><td>' +
        p.quantidade_vendida +
        '</td><td>' +
        fmtEuro(p.receita) +
        '</td><td>' +
        fmtEuro(p.custo_estimado) +
        '</td><td>' +
        fmtEuro(p.lucro_bruto) +
        '</td><td>' +
        marg +
        '</td></tr>'
      );
    })
    .join('');
}

function renderDespesas(list) {
  const tb = document.querySelector('#tblDespesas tbody');
  const arr = Array.isArray(list) ? list : [];
  if (!arr.length) {
    tb.innerHTML = '<tr><td colspan="5" class="table-empty-msg">Nenhuma despesa no período.</td></tr>';
    return;
  }
  tb.innerHTML = arr
    .map((d) => {
      return (
        '<tr><td>' +
        escapeHtml(d.data_despesa) +
        '</td><td>' +
        escapeHtml(d.tipo) +
        '</td><td>' +
        escapeHtml(d.descricao) +
        '</td><td>' +
        fmtEuro(d.valor) +
        '</td><td><button type="button" class="btn btn-danger btn-sm" data-del-desp="' +
        d.despesa_id +
        '">Apagar</button></td></tr>'
      );
    })
    .join('');
  tb.querySelectorAll('[data-del-desp]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!confirm('Apagar esta despesa?')) return;
      try {
        await API.deleteDespesa(btn.getAttribute('data-del-desp'));
        showToast('Despesa removida', 'success');
        loadAll();
      } catch (e) {
        showToast(e.message, 'warning');
      }
    });
  });
}

async function addDespesa() {
  const payload = {
    tipo: document.getElementById('despTipo').value,
    descricao: document.getElementById('despDesc').value.trim(),
    valor: parseFloat(document.getElementById('despValor').value),
    data_despesa: document.getElementById('despData').value,
    notas: document.getElementById('despNotas').value.trim() || null
  };
  if (!payload.descricao || !(payload.valor > 0)) {
    showToast('Descrição e valor são obrigatórios', 'warning');
    return;
  }
  try {
    await API.createDespesa(payload);
    showToast('Despesa registada', 'success');
    document.getElementById('despDesc').value = '';
    document.getElementById('despValor').value = '';
    document.getElementById('despNotas').value = '';
    loadAll();
  } catch (e) {
    showToast(e.message, 'warning');
  }
}

function exportCsv() {
  if (!produtosLucro.length) {
    showToast('Nada para exportar', 'warning');
    return;
  }
  const header = 'Produto;Qtd;Receita;Custo;Lucro;Margem%\n';
  const rows = produtosLucro
    .map((p) =>
      [
        p.nome,
        p.quantidade_vendida,
        p.receita,
        p.custo_estimado,
        p.lucro_bruto,
        p.margem_percent != null ? p.margem_percent : ''
      ].join(';')
    )
    .join('\n');
  const blob = new Blob([header + rows], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'lucro-produtos.csv';
  a.click();
  URL.revokeObjectURL(a.href);
}

function escapeHtml(t) {
  const d = document.createElement('div');
  d.textContent = t == null ? '' : String(t);
  return d.innerHTML;
}
