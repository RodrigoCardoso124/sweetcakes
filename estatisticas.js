// Estatisticas dashboard
document.addEventListener('DOMContentLoaded', () => {
    if (typeof initAdminShell === 'function') {
        if (initAdminShell() === false) return;
    } else if (typeof requireAdminPageAuth === 'function' && !requireAdminPageAuth()) return;

    loadEstatisticas();
    setupEventListeners();
    
    setInterval(loadEstatisticas, 60000);
});

function setupEventListeners() {
    // Refresh
    document.getElementById('refreshBtn').addEventListener('click', loadEstatisticas);
}

function ensureArray(value) {
    if (Array.isArray(value)) return value;
    if (value && Array.isArray(value.data)) return value.data;
    return [];
}

async function getEncomendasForStats() {
    if (API && typeof API.getAllEncomendas === 'function') {
        return ensureArray(await API.getAllEncomendas());
    }
    if (API && typeof API.getEncomendas === 'function') {
        return ensureArray(await API.getEncomendas());
    }
    return [];
}

async function loadEstatisticas() {
    try {
        // Load all data in parallel
        const [encomendasRes, clientesRes, produtosRes] = await Promise.all([
            getEncomendasForStats(),
            API.getPessoas(),
            API.getProdutos()
        ]);
        const encomendas = ensureArray(encomendasRes);
        const clientes = ensureArray(clientesRes);
        const produtos = ensureArray(produtosRes);

        // Calculate statistics
        let totalVendas = 0;
        encomendas.forEach((e) => {
            totalVendas += parseFloat((e && e.total) || 0) || 0;
        });
        
        // Encomendas by status
        const encomendasByStatus = {
            pendente: encomendas.filter(e => e.estado === 'pendente').length,
            aceite: encomendas.filter(e => e.estado === 'aceite').length,
            em_preparacao: encomendas.filter(e => e.estado === 'em_preparacao').length,
            pronta: encomendas.filter(e => e.estado === 'pronta').length,
            entregue: encomendas.filter(e => e.estado === 'entregue').length,
            cancelada: encomendas.filter(e => e.estado === 'cancelada').length
        };

        // Produtos stats
        const produtosDisponiveis = produtos.filter(p => p.disponivel == 1).length;
        const produtosIndisponiveis = produtos.filter(p => p.disponivel == 0).length;

        // Update UI
        document.getElementById('totalEncomendas').textContent = encomendas.length;
        document.getElementById('totalVendas').textContent = `€${totalVendas.toFixed(2)}`;
        document.getElementById('totalClientes').textContent = clientes.length;
        document.getElementById('totalProdutos').textContent = produtos.length;

        document.getElementById('encomendasPendentes').textContent = encomendasByStatus.pendente;
        document.getElementById('encomendasAceites').textContent = encomendasByStatus.aceite;
        document.getElementById('encomendasPreparacao').textContent = encomendasByStatus.em_preparacao;
        document.getElementById('encomendasProntas').textContent = encomendasByStatus.pronta;
        document.getElementById('encomendasEntregues').textContent = encomendasByStatus.entregue;
        document.getElementById('encomendasCanceladas').textContent = encomendasByStatus.cancelada;

        document.getElementById('produtosDisponiveis').textContent = produtosDisponiveis;
        document.getElementById('produtosIndisponiveis').textContent = produtosIndisponiveis;

    } catch (error) {
        console.error('Error loading statistics:', error);
        if (typeof showToast === 'function') showToast('Erro ao carregar estatísticas: ' + error.message, 'error');
        else alert('Erro ao carregar estatísticas: ' + error.message);
    }
}

