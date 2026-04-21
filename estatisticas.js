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
        const [encomendasRes, clientesRes, produtosRes, funcionariosRes, detalhesRes] = await Promise.all([
            getEncomendasForStats(),
            API.getPessoas(),
            API.getProdutos(),
            API.getFuncionarios(),
            API.getEncomendaDetalhes('')
        ]);
        const encomendas = ensureArray(encomendasRes);
        const clientes = ensureArray(clientesRes);
        const produtos = ensureArray(produtosRes);
        const funcionarios = ensureArray(funcionariosRes);
        const detalhes = ensureArray(detalhesRes);

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

        // Rankings
        const clientesMap = Object.fromEntries(clientes.map(c => [String(c.pessoa_id), c]));
        const funcionariosMap = Object.fromEntries(funcionarios.map(f => [String(f.funcionario_id), f]));
        const produtosMap = Object.fromEntries(produtos.map(p => [String(p.produto_id), p]));

        const gastoPorCliente = {};
        const vendasPorFuncionario = {};
        const totalPorDia = {};
        encomendas.forEach(e => {
            const total = parseFloat((e && e.total) || 0) || 0;
            const clienteId = String((e && e.cliente_id) || '');
            const funcionarioId = String((e && e.funcionario_id) || '');
            const dia = String((e && e.data_criacao) || '').slice(0, 10);
            if (clienteId) gastoPorCliente[clienteId] = (gastoPorCliente[clienteId] || 0) + total;
            if (funcionarioId) vendasPorFuncionario[funcionarioId] = (vendasPorFuncionario[funcionarioId] || 0) + total;
            if (dia) totalPorDia[dia] = (totalPorDia[dia] || 0) + total;
        });

        const qtdPorProduto = {};
        detalhes.forEach(d => {
            const pid = String((d && d.produto_id) || '');
            const q = parseInt((d && d.quantidade) || 0, 10) || 0;
            if (pid) qtdPorProduto[pid] = (qtdPorProduto[pid] || 0) + q;
        });

        const topCliente = Object.entries(gastoPorCliente).sort((a, b) => b[1] - a[1])[0];
        const topFuncionario = Object.entries(vendasPorFuncionario).sort((a, b) => b[1] - a[1])[0];
        const topProduto = Object.entries(qtdPorProduto).sort((a, b) => b[1] - a[1])[0];
        const melhorDia = Object.entries(totalPorDia).sort((a, b) => b[1] - a[1])[0];

        const ticketMedio = encomendas.length ? (totalVendas / encomendas.length) : 0;
        const taxaEntrega = encomendas.length ? ((encomendasByStatus.entregue / encomendas.length) * 100) : 0;

        if (topCliente) {
            const c = clientesMap[topCliente[0]] || {};
            document.getElementById('topClienteNome').textContent = c.nome || ('Cliente #' + topCliente[0]);
            document.getElementById('topClienteInfo').textContent = `Total gasto: €${topCliente[1].toFixed(2)}`;
        }
        if (topFuncionario) {
            const f = funcionariosMap[topFuncionario[0]] || {};
            document.getElementById('topFuncionarioNome').textContent = (f.cargo ? `${f.cargo} ` : '') + `#${topFuncionario[0]}`;
            document.getElementById('topFuncionarioInfo').textContent = `Vendas geridas: €${topFuncionario[1].toFixed(2)}`;
        }
        if (topProduto) {
            const p = produtosMap[topProduto[0]] || {};
            document.getElementById('topProdutoNome').textContent = p.nome || ('Produto #' + topProduto[0]);
            document.getElementById('topProdutoInfo').textContent = `Unidades vendidas: ${topProduto[1]}`;
        }
        document.getElementById('ticketMedioValor').textContent = `€${ticketMedio.toFixed(2)}`;
        document.getElementById('taxaEntregaValor').textContent = `${taxaEntrega.toFixed(1)}%`;
        if (melhorDia) {
            document.getElementById('melhorDiaNome').textContent = melhorDia[0];
            document.getElementById('melhorDiaInfo').textContent = `Faturação: €${melhorDia[1].toFixed(2)}`;
        }

    } catch (error) {
        console.error('Error loading statistics:', error);
        if (typeof showToast === 'function') showToast('Erro ao carregar estatísticas: ' + error.message, 'error');
        else alert('Erro ao carregar estatísticas: ' + error.message);
    }
}

