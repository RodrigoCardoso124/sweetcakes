// Gestão de funcionários — criação directa (sem passar por clientes)
let allFuncionarios = [];
let currentFuncionariosPage = 1;
const FUNCIONARIOS_PER_PAGE = 10;
let funcionariosFiltrados = [];

document.addEventListener('DOMContentLoaded', () => {
    if (typeof initAdminShell === 'function') {
        if (initAdminShell() === false) return;
    } else if (typeof requireAdminPageAuth === 'function' && !requireAdminPageAuth()) return;

    loadFuncionarios();
    setupEventListeners();
});

function setupEventListeners() {
    document.getElementById('refreshBtn').addEventListener('click', loadFuncionarios);
    document.getElementById('addFuncionarioBtn').addEventListener('click', () => openFuncionarioModal());
    document.getElementById('searchInput').addEventListener('input', filterFuncionarios);

    const modal = document.getElementById('funcionarioModal');
    const closeBtn = modal.querySelector('.close');
    const cancelBtn = document.getElementById('cancelFuncionarioBtn');

    closeBtn.addEventListener('click', () => modal.classList.remove('active'));
    cancelBtn.addEventListener('click', () => modal.classList.remove('active'));

    window.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('active');
    });

    document.getElementById('funcionarioForm').addEventListener('submit', saveFuncionario);
}

async function loadFuncionarios() {
    const tbody = document.getElementById('funcionariosTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="loading">A carregar funcionários…</td></tr>';

    try {
        const [funcionariosRes, pessoasRes] = await Promise.all([
            API.getFuncionarios(),
            API.getPessoas({ apenasClientes: false }),
        ]);
        allFuncionarios = Array.isArray(funcionariosRes) ? funcionariosRes : [];
        const allPessoas = Array.isArray(pessoasRes) ? pessoasRes : [];

        const pessoasMap = Object.create(null);
        allPessoas.forEach((p) => {
            pessoasMap[String(p.pessoa_id)] = p;
        });

        const missingIds = allFuncionarios
            .map((f) => f.pessoas_pessoa_id)
            .filter((id) => id && !pessoasMap[String(id)]);

        if (missingIds.length) {
            const extra = await Promise.all(
                missingIds.map((id) => API.getPessoa(id).catch(() => null))
            );
            extra.forEach((p) => {
                if (p && p.pessoa_id) pessoasMap[String(p.pessoa_id)] = p;
            });
        }

        allFuncionarios = allFuncionarios.map((func) => ({
            ...func,
            pessoa: pessoasMap[String(func.pessoas_pessoa_id)] || null,
        }));

        funcionariosFiltrados = allFuncionarios.slice();
        renderFuncionarios(funcionariosFiltrados);
        updateStats(allFuncionarios);
    } catch (error) {
        tbody.innerHTML =
            '<tr><td colspan="6" class="error-container">Erro ao carregar: ' +
            escapeHtml(error.message) +
            '</td></tr>';
    }
}

function renderFuncionarios(funcionarios) {
    const tbody = document.getElementById('funcionariosTableBody');
    const pagination = document.getElementById('funcionariosPagination');

    if (funcionarios.length === 0) {
        tbody.innerHTML =
            '<tr><td colspan="6" class="loading">Nenhum funcionário. Clica em «+ Novo Funcionário».</td></tr>';
        if (pagination) pagination.innerHTML = '';
        return;
    }

    const totalPages = Math.max(1, Math.ceil(funcionarios.length / FUNCIONARIOS_PER_PAGE));
    if (currentFuncionariosPage > totalPages) currentFuncionariosPage = totalPages;
    const start = (currentFuncionariosPage - 1) * FUNCIONARIOS_PER_PAGE;
    const pageItems = funcionarios.slice(start, start + FUNCIONARIOS_PER_PAGE);

    tbody.innerHTML = pageItems
        .map((func) => {
            const pessoa = func.pessoa || {};
            const dataEntrada = func.data_entrada
                ? new Date(func.data_entrada).toLocaleDateString('pt-PT')
                : '—';

            return (
                '<tr>' +
                '<td><strong>#' +
                func.funcionario_id +
                '</strong></td>' +
                '<td>' +
                escapeHtml(pessoa.nome || '—') +
                '</td>' +
                '<td>' +
                escapeHtml(pessoa.email || '—') +
                '</td>' +
                '<td>' +
                escapeHtml(func.cargo || '—') +
                '</td>' +
                '<td>' +
                dataEntrada +
                '</td>' +
                '<td class="col-actions"><div class="actions-group">' +
                '<button type="button" class="btn btn-warning btn-sm" onclick="editFuncionario(' +
                func.funcionario_id +
                ')">Editar</button>' +
                '<button type="button" class="btn btn-danger btn-sm" onclick="deleteFuncionario(' +
                func.funcionario_id +
                ')">Apagar</button>' +
                '</div></td>' +
                '</tr>'
            );
        })
        .join('');

    renderPagination(pagination, currentFuncionariosPage, totalPages, 'goToFuncionariosPage');
}

function filterFuncionarios(resetPage = true) {
    const search = document.getElementById('searchInput').value.toLowerCase();

    if (!search) {
        if (resetPage) currentFuncionariosPage = 1;
        funcionariosFiltrados = allFuncionarios.slice();
        renderFuncionarios(funcionariosFiltrados);
        return;
    }

    funcionariosFiltrados = allFuncionarios.filter((f) => {
        const pessoa = f.pessoa || {};
        return (
            (pessoa.nome && pessoa.nome.toLowerCase().includes(search)) ||
            (pessoa.email && pessoa.email.toLowerCase().includes(search)) ||
            (f.cargo && f.cargo.toLowerCase().includes(search))
        );
    });

    if (resetPage) currentFuncionariosPage = 1;
    renderFuncionarios(funcionariosFiltrados);
}

function renderPagination(container, current, total, handlerName) {
    if (!container) return;
    const pages = [];
    const start = Math.max(1, current - 2);
    const end = Math.min(total, current + 2);
    for (let p = start; p <= end; p++) pages.push(p);
    container.innerHTML =
        '<span class="pagination-info">Página ' +
        current +
        ' de ' +
        total +
        '</span>' +
        '<button class="pagination-btn" ' +
        (current <= 1 ? 'disabled' : '') +
        ' onclick="' +
        handlerName +
        '(' +
        (current - 1) +
        ')">‹</button>' +
        pages
            .map(
                (p) =>
                    '<button class="pagination-btn ' +
                    (p === current ? 'active' : '') +
                    '" onclick="' +
                    handlerName +
                    '(' +
                    p +
                    ')">' +
                    p +
                    '</button>'
            )
            .join('') +
        '<button class="pagination-btn" ' +
        (current >= total ? 'disabled' : '') +
        ' onclick="' +
        handlerName +
        '(' +
        (current + 1) +
        ')">›</button>';
}

function updateStats(funcionarios) {
    document.getElementById('totalFuncionarios').textContent = funcionarios.length;
}

function openFuncionarioModal(funcionario = null) {
    const modal = document.getElementById('funcionarioModal');
    const form = document.getElementById('funcionarioForm');
    const title = document.getElementById('modalTitle');
    const pwd = document.getElementById('funcPassword');
    const pwdHelp = document.getElementById('funcPasswordHelp');

    form.reset();
    document.getElementById('funcionarioId').value = '';

    if (funcionario) {
        title.textContent = 'Editar Funcionário';
        document.getElementById('funcionarioId').value = funcionario.funcionario_id;
        const p = funcionario.pessoa || {};
        document.getElementById('funcNome').value = p.nome || '';
        document.getElementById('funcEmail').value = p.email || '';
        document.getElementById('funcTelemovel').value =
            p.telemovel && p.telemovel !== '—' ? p.telemovel : '';
        document.getElementById('funcMorada').value =
            p.morada && p.morada !== '—' ? p.morada : '';
        document.getElementById('funcCargo').value = funcionario.cargo || '';
        pwd.required = false;
        pwd.value = '';
        pwdHelp.textContent =
            'Deixa em branco para manter a password actual. Mínimo 8 caracteres se alterares.';
    } else {
        title.textContent = 'Novo Funcionário';
        pwd.required = true;
        pwdHelp.textContent =
            'Mínimo 8 caracteres. Usada para entrar no painel administrativo.';
    }

    modal.classList.add('active');
}

async function saveFuncionario(e) {
    e.preventDefault();

    const funcionarioId = document.getElementById('funcionarioId').value;
    const payload = {
        nome: document.getElementById('funcNome').value.trim(),
        email: document.getElementById('funcEmail').value.trim(),
        telemovel: document.getElementById('funcTelemovel').value.trim() || '—',
        morada: document.getElementById('funcMorada').value.trim() || '—',
        cargo: document.getElementById('funcCargo').value.trim(),
    };

    const password = document.getElementById('funcPassword').value;
    if (password) payload.password = password;

    const submitBtn = e.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'A guardar…';

    try {
        if (funcionarioId) {
            await API.updateFuncionario(funcionarioId, payload);
            notify('Funcionário atualizado.', 'success');
        } else {
            if (!password) {
                notify('Indica a password de acesso ao painel.', 'warning');
                return;
            }
            await API.createFuncionario(payload);
            notify('Funcionário criado. Já pode entrar no painel com o email e password definidos.', 'success');
        }

        document.getElementById('funcionarioModal').classList.remove('active');
        loadFuncionarios();
    } catch (error) {
        notify(error.message || 'Erro ao guardar', 'warning');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Guardar';
    }
}

async function deleteFuncionario(id) {
    if (!confirm('Apagar este funcionário? Perde o acesso ao painel.')) return;

    try {
        const res = await API.deleteFuncionario(id);
        notify((res && res.message) || 'Funcionário removido.', 'success');
        loadFuncionarios();
    } catch (error) {
        notify(error.message || 'Erro ao apagar', 'warning');
    }
}

async function editFuncionario(id) {
    const local = allFuncionarios.find((f) => String(f.funcionario_id) === String(id));
    if (local && local.pessoa) {
        openFuncionarioModal(local);
        return;
    }
    try {
        const full = await API.getFuncionario(id);
        openFuncionarioModal(full);
    } catch (e) {
        notify(e.message || 'Erro ao carregar', 'warning');
    }
}

function notify(msg, type) {
    if (typeof showToast === 'function') showToast(msg, type || 'info');
    else alert(msg);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text == null ? '' : String(text);
    return div.innerHTML;
}

window.editFuncionario = editFuncionario;
window.deleteFuncionario = deleteFuncionario;
window.goToFuncionariosPage = function (page) {
    currentFuncionariosPage = Math.max(1, page);
    filterFuncionarios(false);
};
