// Clientes management
let allClientes = [];
let currentClientesPage = 1;
const CLIENTES_PER_PAGE = 10;
let clientesFiltrados = [];

document.addEventListener('DOMContentLoaded', () => {
    if (typeof initAdminShell === 'function') {
        if (initAdminShell() === false) return;
    } else if (typeof requireAdminPageAuth === 'function' && !requireAdminPageAuth()) return;

    loadClientes();
    setupEventListeners();
});

function setupEventListeners() {
    // Refresh
    document.getElementById('refreshBtn').addEventListener('click', loadClientes);

    // Add button
    document.getElementById('addClienteBtn').addEventListener('click', () => openClienteModal());

    // Search
    document.getElementById('searchInput').addEventListener('input', filterClientes);

    // Modal
    const modal = document.getElementById('clienteModal');
    const closeBtn = modal.querySelector('.close');
    const cancelBtn = document.getElementById('cancelClienteBtn');
    
    closeBtn.addEventListener('click', () => modal.classList.remove('active'));
    cancelBtn.addEventListener('click', () => modal.classList.remove('active'));
    
    window.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('active');
    });

    // Form submit
    document.getElementById('clienteForm').addEventListener('submit', saveCliente);
}

async function loadClientes() {
    const tbody = document.getElementById('clientesTableBody');
    tbody.innerHTML = '<tr><td colspan="7" class="loading">Carregando clientes...</td></tr>';

    try {
        allClientes = await API.getPessoas();
        clientesFiltrados = allClientes.slice();
        renderClientes(clientesFiltrados);
        updateStats(allClientes);
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="7" class="error-container">Erro ao carregar clientes: ${error.message}</td></tr>`;
    }
}

function renderClientes(clientes) {
    const tbody = document.getElementById('clientesTableBody');
    const pagination = document.getElementById('clientesPagination');
    
    if (clientes.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="loading">Nenhum cliente encontrado</td></tr>';
        if (pagination) pagination.innerHTML = '';
        return;
    }

    const totalPages = Math.max(1, Math.ceil(clientes.length / CLIENTES_PER_PAGE));
    if (currentClientesPage > totalPages) currentClientesPage = totalPages;
    const start = (currentClientesPage - 1) * CLIENTES_PER_PAGE;
    const pageItems = clientes.slice(start, start + CLIENTES_PER_PAGE);

    tbody.innerHTML = pageItems.map(cliente => {
        const dataRegisto = cliente.data_registo 
            ? new Date(cliente.data_registo).toLocaleDateString('pt-PT')
            : 'N/A';
        
        return `
            <tr>
                <td><strong>#${cliente.pessoa_id}</strong></td>
                <td>${escapeHtml(cliente.nome || 'N/A')}</td>
                <td>${escapeHtml(cliente.email || 'N/A')}</td>
                <td>${escapeHtml(cliente.telemovel || 'N/A')}</td>
                <td>${escapeHtml(cliente.morada || 'N/A')}</td>
                <td>${dataRegisto}</td>
                <td class="actions-cell">
                    <button class="action-btn edit" onclick="editCliente(${cliente.pessoa_id})">Editar</button>
                    <button class="action-btn delete" onclick="deleteCliente(${cliente.pessoa_id})" style="background: var(--danger);">Apagar</button>
                </td>
            </tr>
        `;
    }).join('');

    renderPagination(pagination, currentClientesPage, totalPages, 'goToClientesPage');
}

function filterClientes(resetPage = true) {
    const search = document.getElementById('searchInput').value.toLowerCase();
    
    if (!search) {
        if (resetPage) currentClientesPage = 1;
        clientesFiltrados = allClientes.slice();
        renderClientes(clientesFiltrados);
        return;
    }

    clientesFiltrados = allClientes.filter(c => 
        (c.nome && c.nome.toLowerCase().includes(search)) ||
        (c.email && c.email.toLowerCase().includes(search)) ||
        (c.telemovel && c.telemovel.includes(search))
    );

    if (resetPage) currentClientesPage = 1;
    renderClientes(clientesFiltrados);
}
function renderPagination(container, current, total, handlerName) {
    if (!container) return;
    const pages = [];
    const start = Math.max(1, current - 2);
    const end = Math.min(total, current + 2);
    for (let p = start; p <= end; p++) pages.push(p);
    container.innerHTML = `
        <span class="pagination-info">Página ${current} de ${total}</span>
        <button class="pagination-btn" ${current <= 1 ? 'disabled' : ''} onclick="${handlerName}(${current - 1})">‹</button>
        ${pages.map(p => `<button class="pagination-btn ${p === current ? 'active' : ''}" onclick="${handlerName}(${p})">${p}</button>`).join('')}
        <button class="pagination-btn" ${current >= total ? 'disabled' : ''} onclick="${handlerName}(${current + 1})">›</button>
    `;
}

function updateStats(clientes) {
    document.getElementById('totalClientes').textContent = clientes.length;
}

function openClienteModal(cliente = null) {
    const modal = document.getElementById('clienteModal');
    const form = document.getElementById('clienteForm');
    const title = document.getElementById('modalTitle');
    
    form.reset();
    document.getElementById('clienteId').value = '';
    
    if (cliente) {
        title.textContent = 'Editar Cliente';
        document.getElementById('clienteId').value = cliente.pessoa_id;
        document.getElementById('nome').value = cliente.nome || '';
        document.getElementById('email').value = cliente.email || '';
        document.getElementById('telemovel').value = cliente.telemovel || '';
        document.getElementById('morada').value = cliente.morada || '';
    } else {
        title.textContent = 'Novo Cliente';
    }
    
    modal.classList.add('active');
}

async function saveCliente(e) {
    e.preventDefault();
    
    const formData = {
        nome: document.getElementById('nome').value,
        email: document.getElementById('email').value,
        telemovel: document.getElementById('telemovel').value,
        morada: document.getElementById('morada').value
    };
    
    const clienteId = document.getElementById('clienteId').value;
    const submitBtn = e.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'A guardar...';

    try {
        if (clienteId) {
            await API.updateCliente(clienteId, formData);
        } else {
            await API.createCliente(formData);
        }
        
        document.getElementById('clienteModal').classList.remove('active');
        loadClientes();
        alert('Cliente guardado com sucesso!');
    } catch (error) {
        alert('Erro ao guardar cliente: ' + error.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Guardar';
    }
}

async function deleteCliente(id) {
    if (!confirm('Tem certeza que deseja apagar este cliente?')) return;

    try {
        await API.deleteCliente(id);
        loadClientes();
        alert('Cliente apagado com sucesso!');
    } catch (error) {
        alert('Erro ao apagar cliente: ' + error.message);
    }
}

async function editCliente(id) {
    const cliente = allClientes.find(c => c.pessoa_id == id);
    if (cliente) {
        openClienteModal(cliente);
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Make functions available globally
window.editCliente = editCliente;
window.deleteCliente = deleteCliente;
window.goToClientesPage = function(page) {
    currentClientesPage = Math.max(1, page);
    filterClientes(false);
};

