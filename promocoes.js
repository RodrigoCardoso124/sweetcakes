// Gestao de promocoes (admin).
let allPromocoes = [];

document.addEventListener('DOMContentLoaded', () => {
    if (typeof initAdminShell === 'function') {
        if (!initAdminShell()) return;
    } else {
        if (localStorage.getItem('adminLoggedIn') !== 'true' || !localStorage.getItem('adminFuncionarioId')) {
            window.location.href = 'login.html';
            return;
        }
    }
    loadPromocoes();
    setupEventListeners();
});

function setupEventListeners() {
    document.getElementById('refreshBtn').addEventListener('click', loadPromocoes);
    document.getElementById('addPromocaoBtn').addEventListener('click', () => openModal());

    document.getElementById('searchInput').addEventListener('input', applyFilters);
    document.getElementById('statusFilter').addEventListener('change', applyFilters);

    const modal = document.getElementById('promocaoModal');
    modal.querySelector('.close').addEventListener('click', closeModal);
    document.getElementById('cancelPromocaoBtn').addEventListener('click', closeModal);
    window.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    document.getElementById('promoTipo').addEventListener('change', updateTipoFields);
    document.getElementById('promocaoForm').addEventListener('submit', savePromocao);
}

function closeModal() {
    document.getElementById('promocaoModal').classList.remove('active');
}

async function loadPromocoes() {
    const grid = document.getElementById('promocoesGrid');
    grid.innerHTML = '<div class="loading">A carregar promoções...</div>';
    try {
        allPromocoes = await API.getPromocoesAll();
        applyFilters();
        updateStats();
    } catch (err) {
        grid.innerHTML = `<div class="error-container">Erro a carregar: ${escapeHtml(err.message || String(err))}</div>`;
    }
}

function applyFilters() {
    const grid = document.getElementById('promocoesGrid');
    const term = (document.getElementById('searchInput').value || '').toLowerCase().trim();
    const status = document.getElementById('statusFilter').value;
    const now = Date.now();

    let list = allPromocoes.slice();
    if (term) list = list.filter(p => (p.titulo || '').toLowerCase().includes(term));
    if (status) list = list.filter(p => promocaoStatus(p, now) === status);

    if (!list.length) {
        grid.innerHTML = '<div class="loading">Sem promoções nesta vista.</div>';
        return;
    }

    grid.innerHTML = list.map(p => renderCard(p, now)).join('');
}

function promocaoStatus(p, nowMs) {
    const ini = Date.parse(p.data_inicio);
    const fim = Date.parse(p.data_fim);
    if (isFinite(ini) && nowMs < ini) return 'future';
    if (isFinite(fim) && nowMs > fim) return 'expired';
    return 'active';
}

function renderCard(p, nowMs) {
    const status = promocaoStatus(p, nowMs);
    const badge = status === 'active'
        ? '<span class="promo-badge badge-active">Activa</span>'
        : status === 'future'
            ? '<span class="promo-badge badge-future">Programada</span>'
            : '<span class="promo-badge badge-expired">Expirada</span>';

    const cardClass = `promo-card ${status === 'active' ? '' : status === 'future' ? 'future' : 'expired'}`;

    return `
        <div class="${cardClass}">
            <div class="promo-card-header">
                <div>
                    <div class="promo-card-title">${escapeHtml(p.titulo || 'Sem título')}</div>
                    ${p.subtitulo ? `<div class="promo-card-subtitle">${escapeHtml(p.subtitulo)}</div>` : ''}
                </div>
                ${badge}
            </div>
            <div class="promo-meta">
                <div>Tipo: <strong>${tipoLabel(p)}</strong></div>
                <div>Valor: <strong>${valorLabel(p)}</strong></div>
                <div>Mín. compra: <strong>${formatEur(p.min_compra)}</strong></div>
                <div>Uso único: <strong>${p.uso_unico == 1 ? 'Sim' : 'Não'}</strong></div>
                <div style="grid-column: span 2;">Período: <strong>${formatDate(p.data_inicio)} → ${formatDate(p.data_fim)}</strong></div>
            </div>
            <div class="promo-actions">
                <button class="btn btn-secondary" onclick="editPromocao(${p.promocao_id})">Editar</button>
                <button class="btn btn-danger" onclick="deletePromocao(${p.promocao_id})">Apagar</button>
            </div>
        </div>
    `;
}

function tipoLabel(p) {
    switch (p.tipo) {
        case 'percentual': return 'Percentagem';
        case 'valor_fixo': return 'Valor fixo';
        case 'oferta': return 'Oferta';
        case 'leve_pague': return 'Leve / Pague';
        default: return p.tipo || '—';
    }
}

function valorLabel(p) {
    switch (p.tipo) {
        case 'percentual': return `${parseFloat(p.valor_percentual || 0).toFixed(2)}% OFF`;
        case 'valor_fixo': return `${formatEur(p.valor_fixo)} OFF`;
        case 'oferta': return p.mensagem_oferta || 'Mensagem';
        case 'leve_pague': return `Leve ${p.leve_qtd} pague ${p.pague_qtd}`;
        default: return '—';
    }
}

function formatEur(value) {
    const n = parseFloat(value || 0);
    return `€${n.toFixed(2)}`;
}

function formatDate(value) {
    if (!value) return '—';
    const d = new Date(value.replace(' ', 'T'));
    if (isNaN(d.getTime())) return value;
    return d.toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' });
}

function updateStats() {
    const now = Date.now();
    let active = 0, future = 0, expired = 0;
    for (const p of allPromocoes) {
        const s = promocaoStatus(p, now);
        if (s === 'active') active++;
        else if (s === 'future') future++;
        else expired++;
    }
    document.getElementById('activeCount').textContent = active;
    document.getElementById('futureCount').textContent = future;
    document.getElementById('expiredCount').textContent = expired;
}

function updateTipoFields() {
    const tipo = document.getElementById('promoTipo').value;
    document.querySelectorAll('.type-fields').forEach(el => {
        el.classList.toggle('active', el.dataset.type === tipo);
    });
}

function openModal(promocao = null) {
    const modal = document.getElementById('promocaoModal');
    const form = document.getElementById('promocaoForm');
    form.reset();
    document.getElementById('promocaoId').value = '';

    const now = new Date();
    now.setSeconds(0, 0);
    const inOneMonth = new Date(now);
    inOneMonth.setMonth(inOneMonth.getMonth() + 1);

    document.getElementById('modalTitle').textContent = promocao ? 'Editar Promoção' : 'Nova Promoção';

    if (promocao) {
        document.getElementById('promocaoId').value = promocao.promocao_id;
        document.getElementById('promoTitulo').value = promocao.titulo || '';
        document.getElementById('promoSubtitulo').value = promocao.subtitulo || '';
        document.getElementById('promoTipo').value = promocao.tipo || 'percentual';
        document.getElementById('promoPercentual').value = promocao.valor_percentual || '';
        document.getElementById('promoValorFixo').value = promocao.valor_fixo || '';
        document.getElementById('promoLeveQtd').value = promocao.leve_qtd || '';
        document.getElementById('promoPagueQtd').value = promocao.pague_qtd || '';
        document.getElementById('promoMensagem').value = promocao.mensagem_oferta || '';
        document.getElementById('promoMinCompra').value = promocao.min_compra || 0;
        document.getElementById('promoUsoUnico').checked = promocao.uso_unico == 1;
        document.getElementById('promoDataInicio').value = toDatetimeLocal(promocao.data_inicio);
        document.getElementById('promoDataFim').value = toDatetimeLocal(promocao.data_fim);
    } else {
        document.getElementById('promoTipo').value = 'percentual';
        document.getElementById('promoMinCompra').value = 0;
        document.getElementById('promoDataInicio').value = toDatetimeLocal(now);
        document.getElementById('promoDataFim').value = toDatetimeLocal(inOneMonth);
    }

    updateTipoFields();
    modal.classList.add('active');
}

function toDatetimeLocal(value) {
    if (!value) return '';
    const d = value instanceof Date ? value : new Date(String(value).replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    const pad = (n) => n.toString().padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

async function savePromocao(e) {
    e.preventDefault();
    const id = document.getElementById('promocaoId').value;
    const tipo = document.getElementById('promoTipo').value;

    const payload = {
        titulo: document.getElementById('promoTitulo').value.trim(),
        subtitulo: document.getElementById('promoSubtitulo').value.trim() || null,
        tipo,
        min_compra: parseFloat(document.getElementById('promoMinCompra').value) || 0,
        uso_unico: document.getElementById('promoUsoUnico').checked ? 1 : 0,
        data_inicio: document.getElementById('promoDataInicio').value.replace('T', ' ') + ':00',
        data_fim: document.getElementById('promoDataFim').value.replace('T', ' ') + ':00',
    };

    if (tipo === 'percentual') {
        payload.valor_percentual = parseFloat(document.getElementById('promoPercentual').value);
    } else if (tipo === 'valor_fixo') {
        payload.valor_fixo = parseFloat(document.getElementById('promoValorFixo').value);
    } else if (tipo === 'oferta') {
        payload.mensagem_oferta = document.getElementById('promoMensagem').value.trim();
    } else if (tipo === 'leve_pague') {
        payload.leve_qtd = parseInt(document.getElementById('promoLeveQtd').value, 10);
        payload.pague_qtd = parseInt(document.getElementById('promoPagueQtd').value, 10);
    }

    const submitBtn = e.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'A guardar...';
    try {
        if (id) {
            await API.updatePromocao(id, payload);
        } else {
            await API.createPromocao(payload);
        }
        closeModal();
        await loadPromocoes();
        alert('Promoção guardada com sucesso!');
    } catch (err) {
        const fieldsMsg = err.response && err.response.fields
            ? '\n\nDetalhes:\n' + Object.entries(err.response.fields).map(([k, v]) => `• ${k}: ${v}`).join('\n')
            : '';
        alert('Erro a guardar promoção: ' + (err.message || err) + fieldsMsg);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Guardar';
    }
}

async function editPromocao(id) {
    const p = allPromocoes.find(x => x.promocao_id == id);
    if (p) openModal(p);
}

async function deletePromocao(id) {
    if (!confirm('Tem a certeza que quer apagar esta promoção?')) return;
    try {
        await API.deletePromocao(id);
        await loadPromocoes();
    } catch (err) {
        alert('Erro a apagar: ' + (err.message || err));
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}

window.editPromocao = editPromocao;
window.deletePromocao = deletePromocao;
