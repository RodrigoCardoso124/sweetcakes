// Funcionarios management
let allFuncionarios = [];
let allPessoas = [];
let currentFuncionariosPage = 1;
const FUNCIONARIOS_PER_PAGE = 10;

document.addEventListener('DOMContentLoaded', () => {
    if (typeof initAdminShell === 'function') {
        if (initAdminShell() === false) return;
    } else if (typeof requireAdminPageAuth === 'function' && !requireAdminPageAuth()) return;

    loadFuncionarios();
    loadPessoas();
    setupEventListeners();
});

function setupEventListeners() {
    // Refresh
    document.getElementById('refreshBtn').addEventListener('click', () => {
        loadFuncionarios();
        loadPessoas();
    });

    // Add button
    document.getElementById('addFuncionarioBtn').addEventListener('click', () => openFuncionarioModal());

    // Search
    document.getElementById('searchInput').addEventListener('input', filterFuncionarios);

    // Modal
    const modal = document.getElementById('funcionarioModal');
    const closeBtn = modal.querySelector('.close');
    const cancelBtn = document.getElementById('cancelFuncionarioBtn');
    
    closeBtn.addEventListener('click', () => modal.classList.remove('active'));
    cancelBtn.addEventListener('click', () => modal.classList.remove('active'));
    
    window.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('active');
    });

    // Form submit
    document.getElementById('funcionarioForm').addEventListener('submit', saveFuncionario);
}

async function loadPessoas() {
    try {
        allPessoas = await API.getPessoas();
        updatePessoaSelect();
    } catch (error) {
        console.error('Error loading pessoas:', error);
    }
}

function updatePessoaSelect() {
    const select = document.getElementById('pessoaSelect');
    select.innerHTML = '<option value="">Selecione um cliente...</option>';
    
    allPessoas.forEach(pessoa => {
        const option = document.createElement('option');
        option.value = pessoa.pessoa_id;
        option.textContent = `${pessoa.nome} (${pessoa.email})`;
        select.appendChild(option);
    });
}

async function loadFuncionarios() {
    const tbody = document.getElementById('funcionariosTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="loading">Carregando funcionários...</td></tr>';

    try {
        allFuncionarios = await API.getFuncionarios();
        
        // Load pessoa data for each funcionario
        await loadPessoasForFuncionarios();
        
        renderFuncionarios(allFuncionarios);
        updateStats(allFuncionarios);
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="6" class="error-container">Erro ao carregar funcionários: ${error.message}</td></tr>`;
    }
}

async function loadPessoasForFuncionarios() {
    for (let func of allFuncionarios) {
        try {
            const pessoa = await API.getPessoa(func.pessoas_pessoa_id);
            func.pessoa = pessoa;
        } catch (error) {
            console.error(`Error loading pessoa for funcionario ${func.funcionario_id}:`, error);
        }
    }
}

function renderFuncionarios(funcionarios) {
    const tbody = document.getElementById('funcionariosTableBody');
    const pagination = document.getElementById('funcionariosPagination');
    
    if (funcionarios.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="loading">Nenhum funcionário encontrado</td></tr>';
        if (pagination) pagination.innerHTML = '';
        return;
    }

    const totalPages = Math.max(1, Math.ceil(funcionarios.length / FUNCIONARIOS_PER_PAGE));
    if (currentFuncionariosPage > totalPages) currentFuncionariosPage = totalPages;
    const start = (currentFuncionariosPage - 1) * FUNCIONARIOS_PER_PAGE;
    const pageItems = funcionarios.slice(start, start + FUNCIONARIOS_PER_PAGE);

    tbody.innerHTML = pageItems.map(func => {
        const pessoa = func.pessoa || {};
        const dataEntrada = func.data_entrada 
            ? new Date(func.data_entrada).toLocaleDateString('pt-PT')
            : 'N/A';
        
        return `
            <tr>
                <td><strong>#${func.funcionario_id}</strong></td>
                <td>${escapeHtml(pessoa.nome || 'N/A')}</td>
                <td>${escapeHtml(pessoa.email || 'N/A')}</td>
                <td>${escapeHtml(func.cargo || 'N/A')}</td>
                <td>${dataEntrada}</td>
                <td class="actions-cell">
                    <button class="action-btn edit" onclick="editFuncionario(${func.funcionario_id})">Editar</button>
                    <button class="action-btn delete" onclick="deleteFuncionario(${func.funcionario_id})" style="background: var(--danger);">Apagar</button>
                </td>
            </tr>
        `;
    }).join('');

    if (pagination) {
        pagination.innerHTML = `
            <span class="pagination-info">Página ${currentFuncionariosPage} de ${totalPages}</span>
            <button class="btn btn-secondary" ${currentFuncionariosPage <= 1 ? 'disabled' : ''} onclick="changeFuncionariosPage(-1)">Anterior</button>
            <button class="btn btn-secondary" ${currentFuncionariosPage >= totalPages ? 'disabled' : ''} onclick="changeFuncionariosPage(1)">Seguinte</button>
        `;
    }
}

function filterFuncionarios(resetPage = true) {
    const search = document.getElementById('searchInput').value.toLowerCase();
    
    if (!search) {
        if (resetPage) currentFuncionariosPage = 1;
        renderFuncionarios(allFuncionarios);
        return;
    }

    const filtered = allFuncionarios.filter(f => {
        const pessoa = f.pessoa || {};
        return (pessoa.nome && pessoa.nome.toLowerCase().includes(search)) ||
               (pessoa.email && pessoa.email.toLowerCase().includes(search)) ||
               (f.cargo && f.cargo.toLowerCase().includes(search));
    });

    if (resetPage) currentFuncionariosPage = 1;
    renderFuncionarios(filtered);
}

function updateStats(funcionarios) {
    document.getElementById('totalFuncionarios').textContent = funcionarios.length;
}

function openFuncionarioModal(funcionario = null) {
    const modal = document.getElementById('funcionarioModal');
    const form = document.getElementById('funcionarioForm');
    const title = document.getElementById('modalTitle');
    
    form.reset();
    document.getElementById('funcionarioId').value = '';
    
    if (funcionario) {
        title.textContent = 'Editar Funcionário';
        document.getElementById('funcionarioId').value = funcionario.funcionario_id;
        document.getElementById('pessoaSelect').value = funcionario.pessoas_pessoa_id;
        document.getElementById('cargo').value = funcionario.cargo || '';
        document.getElementById('pessoaSelect').disabled = true; // Can't change pessoa
    } else {
        title.textContent = 'Novo Funcionário';
        document.getElementById('pessoaSelect').disabled = false;
    }
    
    modal.classList.add('active');
}

async function saveFuncionario(e) {
    e.preventDefault();
    
    const formData = {
        pessoa_id: document.getElementById('pessoaSelect').value,
        cargo: document.getElementById('cargo').value
    };
    
    const funcionarioId = document.getElementById('funcionarioId').value;
    const submitBtn = e.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'A guardar...';

    try {
        if (funcionarioId) {
            await API.updateFuncionario(funcionarioId, { cargo: formData.cargo });
        } else {
            await API.createFuncionario(formData);
        }
        
        document.getElementById('funcionarioModal').classList.remove('active');
        loadFuncionarios();
        alert('Funcionário guardado com sucesso!');
    } catch (error) {
        alert('Erro ao guardar funcionário: ' + error.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Guardar';
    }
}

async function deleteFuncionario(id) {
    if (!confirm('Tem certeza que deseja apagar este funcionário?')) return;

    try {
        await API.deleteFuncionario(id);
        loadFuncionarios();
        alert('Funcionário apagado com sucesso!');
    } catch (error) {
        alert('Erro ao apagar funcionário: ' + error.message);
    }
}

async function editFuncionario(id) {
    const funcionario = allFuncionarios.find(f => f.funcionario_id == id);
    if (funcionario) {
        openFuncionarioModal(funcionario);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Make functions available globally
window.editFuncionario = editFuncionario;
window.deleteFuncionario = deleteFuncionario;
window.changeFuncionariosPage = function(delta) {
    currentFuncionariosPage = Math.max(1, currentFuncionariosPage + delta);
    filterFuncionarios(false);
};

