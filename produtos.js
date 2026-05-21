// produtos.js

// Produtos management
let allProdutos = [];

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

function applyProdutosRoleUi() {
    const elevated = isElevatedPainel();
    const addBtn = document.getElementById('addProdutoBtn');
    if (addBtn) addBtn.style.display = elevated ? '' : 'none';
    const banner = document.getElementById('produtosReadOnlyBanner');
    if (banner) {
        if (elevated) {
            banner.style.display = 'none';
            banner.textContent = '';
        } else {
            banner.textContent =
                'Consulta: vês produtos e stock. Só administradores podem criar ou editar.';
            banner.style.display = 'block';
        }
    }
}
const ALERGENIOS_OPCOES = ['Glúten', 'Leite', 'Ovo', 'Frutos secos', 'Amendoim', 'Soja' , 'Sulfitos'];

// Placeholder SVG inline para produtos sem imagem
const PLACEHOLDER_SEM_IMAGEM = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='200'%3E%3Crect fill='%23e8e8e8' width='300' height='200'/%3E%3Ctext x='150' y='100' text-anchor='middle' fill='%23999' font-size='14'%3ESem Imagem%3C/text%3E%3C/svg%3E";
window.PLACEHOLDER_SEM_IMAGEM = PLACEHOLDER_SEM_IMAGEM;

// Constrói URL da imagem usando endpoint central da API.
function getProdutoImageUrl(imagemPath) {
    if (!imagemPath) return PLACEHOLDER_SEM_IMAGEM;
    if (/^https?:\/\//i.test(imagemPath)) return imagemPath;
    if (typeof window.getProdutoImageUrl === 'function' && window.getProdutoImageUrl !== getProdutoImageUrl) {
        return window.getProdutoImageUrl(imagemPath.replace(/\\/g, '/'));
    }
    return `api/image.php?path=${encodeURIComponent(imagemPath.replace(/\\/g, '/'))}`;
}

document.addEventListener('DOMContentLoaded', async () => {
    if (typeof initAdminShell === 'function') {
        if (initAdminShell() === false) return;
    } else if (typeof requireAdminPageAuth === 'function' && !requireAdminPageAuth()) return;

    await refreshRoleFromServer();
    applyProdutosRoleUi();
    loadProdutos();
    setupEventListeners();
});

function setupEventListeners() {
    document.getElementById('refreshBtn').addEventListener('click', loadProdutos);
    document.getElementById('addProdutoBtn').addEventListener('click', () => {
        if (!isElevatedPainel()) return;
        openProdutoModal();
    });

    document.getElementById('searchInput').addEventListener('input', filterProdutos);
    document.getElementById('disponibilidadeFilter').addEventListener('change', filterProdutos);

    const modal = document.getElementById('produtoModal');
    const closeBtn = modal.querySelector('.close');
    const cancelBtn = document.getElementById('cancelProdutoBtn');
    
    closeBtn.addEventListener('click', () => modal.classList.remove('active'));
    cancelBtn.addEventListener('click', () => modal.classList.remove('active'));
    
    window.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('active');
    });

    document.getElementById('produtoForm').addEventListener('submit', saveProduto);
    document.getElementById('produtoImagem').addEventListener('change', handleImagePreview);
    renderAlergeniosOptions();
}

function renderAlergeniosOptions() {
    const container = document.getElementById('produtoAlergeniosOptions');
    if (!container) return;
    container.innerHTML = ALERGENIOS_OPCOES.map((nome, i) => `
        <label class="allergen-option">
            <input type="checkbox" value="${nome}" id="alergOpt${i}">
            <span>${escapeHtml(nome)}</span>
        </label>
    `).join('');
}

function setAlergeniosSelecionados(values = []) {
    const set = new Set((values || []).map(v => String(v).trim().toLowerCase()));
    document.querySelectorAll('#produtoAlergeniosOptions input[type="checkbox"]').forEach(el => {
        el.checked = set.has(String(el.value).trim().toLowerCase());
    });
}

function getAlergeniosSelecionados() {
    return Array.from(document.querySelectorAll('#produtoAlergeniosOptions input[type="checkbox"]:checked'))
        .map(el => el.value);
}

async function loadProdutos() {
    const grid = document.getElementById('produtosGrid');
    grid.innerHTML = '<div class="loading">Carregando produtos...</div>';

    try {
        allProdutos = await API.getProdutos();
        renderProdutos(allProdutos);
        updateStats(allProdutos);
    } catch (error) {
        grid.innerHTML = `<div class="error-container">Erro ao carregar produtos: ${error.message}</div>`;
    }
}

function renderProdutos(produtos) {
    const grid = document.getElementById('produtosGrid');
    
    if (produtos.length === 0) {
        grid.innerHTML = '<div class="loading">Nenhum produto encontrado</div>';
        return;
    }

    grid.innerHTML = produtos.map(produto => {
        const stockAtual = parseInt(produto.stock_atual, 10) || 0;
        const stockMin = parseInt(produto.stock_minimo, 10) || 0;
        const stockLow = stockMin > 0 && stockAtual <= stockMin;
        const imagemUrl = produto.imagem_url
            ? getProdutoImageUrl(produto.imagem_url)
            : (produto.imagem ? getProdutoImageUrl(produto.imagem) : PLACEHOLDER_SEM_IMAGEM);
        const alergenios = Array.isArray(produto.alergenios) ? produto.alergenios : [];
        const alergeniosHtml = alergenios.length
            ? `<div class="allergen-tags">${alergenios.map(a => `<span class="allergen-tag">${escapeHtml(a)}</span>`).join('')}</div>`
            : '';
        
        return `
            <div class="product-card">
                <div class="product-image">
                    <img src="${imagemUrl}" alt="${escapeHtml(produto.nome)}"
                        onerror="if(window.PLACEHOLDER_SEM_IMAGEM && this.src!==window.PLACEHOLDER_SEM_IMAGEM){this.src=window.PLACEHOLDER_SEM_IMAGEM}else{this.style.display='none';}">
                    <span class="product-status ${produto.disponivel ? 'available' : 'unavailable'}">
                        ${produto.disponivel ? 'Disponível' : 'Indisponível'}
                    </span>
                </div>
                <div class="product-info">
                    <h3>${escapeHtml(produto.nome)}</h3>
                    <p class="product-description">${escapeHtml(produto.descricao || 'Sem descrição')}</p>
                    ${alergeniosHtml || ''}
                    <div class="product-price">€${parseFloat(produto.preco || 0).toFixed(2)}</div>
                    <div class="product-stock-row">
                        <span class="stock-pill ${stockLow ? 'stock-pill--warn' : ''}">Stock: <strong>${stockAtual}</strong></span>
                        <span class="stock-pill stock-pill--muted">Mín: ${stockMin}</span>
                    </div>
                    ${
                        isElevatedPainel()
                            ? `<div class="product-actions">
                        <button class="btn btn-warning btn-sm" onclick="editProduto(${produto.produto_id})">Editar</button>
                        <button class="btn btn-danger btn-sm" onclick="deleteProduto(${produto.produto_id})">Apagar</button>
                    </div>`
                            : ''
                    }
                </div>
            </div>
        `;
    }).join('');
}

function filterProdutos() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    const disponibilidade = document.getElementById('disponibilidadeFilter').value;
    
    let filtered = allProdutos;
    
    if (search) filtered = filtered.filter(p => p.nome && p.nome.toLowerCase().includes(search));
    if (disponibilidade !== '') filtered = filtered.filter(p => p.disponivel == disponibilidade);
    
    renderProdutos(filtered);
}

function updateStats(produtos) {
    document.getElementById('totalProdutos').textContent = produtos.length;
    document.getElementById('disponiveisCount').textContent = produtos.filter(p => p.disponivel == 1).length;
}

function openProdutoModal(produto = null) {
    if (!isElevatedPainel()) return;
    const modal = document.getElementById('produtoModal');
    const form = document.getElementById('produtoForm');
    const title = document.getElementById('modalTitle');
    const preview = document.getElementById('imagemPreview');
    
    form.reset();
    document.getElementById('produtoId').value = '';
    preview.innerHTML = '';
    
    if (produto) {
        title.textContent = 'Editar Produto';
        document.getElementById('produtoId').value = produto.produto_id;
        document.getElementById('produtoNome').value = produto.nome || '';
        document.getElementById('produtoDescricao').value = produto.descricao || '';
        document.getElementById('produtoPreco').value = produto.preco || '';
        document.getElementById('produtoDisponivel').value = produto.disponivel || 1;
        document.getElementById('produtoStockAtual').value = produto.stock_atual != null ? produto.stock_atual : 0;
        document.getElementById('produtoStockMinimo').value = produto.stock_minimo != null ? produto.stock_minimo : 0;
        setAlergeniosSelecionados(Array.isArray(produto.alergenios) ? produto.alergenios : []);
        
        if (produto.imagem) {
            const imgUrl = getProdutoImageUrl(produto.imagem);
            preview.innerHTML = `<img src="${imgUrl}" style="max-width: 200px; border-radius: 8px;">`;
        }
    } else {
        title.textContent = 'Novo Produto';
        document.getElementById('produtoStockAtual').value = '0';
        document.getElementById('produtoStockMinimo').value = '0';
        setAlergeniosSelecionados([]);
    }
    
    modal.classList.add('active');
}

function handleImagePreview(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('imagemPreview');
    
    if (file) {
        const reader = new FileReader();
        reader.onload = (e) => {
            preview.innerHTML = `<img src="${e.target.result}" style="max-width: 200px; border-radius: 8px;">`;
        };
        reader.readAsDataURL(file);
    }
}

async function saveProduto(e) {
    e.preventDefault();
    if (!isElevatedPainel()) return;

    const produtoId = document.getElementById('produtoId').value;
    const formData = {
        nome: document.getElementById('produtoNome').value,
        descricao: document.getElementById('produtoDescricao').value,
        preco: document.getElementById('produtoPreco').value,
        disponivel: document.getElementById('produtoDisponivel').value,
        stock_atual: parseInt(document.getElementById('produtoStockAtual').value, 10) || 0,
        stock_minimo: parseInt(document.getElementById('produtoStockMinimo').value, 10) || 0,
        alergenios: getAlergeniosSelecionados().join(', ')
    };
    
    const imageFile = document.getElementById('produtoImagem').files[0];
    const submitBtn = e.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'A guardar...';

    try {
        if (produtoId) await API.updateProduto(produtoId, formData, imageFile);
        else await API.createProduto(formData, imageFile);
        
        document.getElementById('produtoModal').classList.remove('active');
        loadProdutos();
        alert('Produto guardado com sucesso!');
    } catch (error) {
        alert('Erro ao guardar produto: ' + error.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Guardar';
    }
}

async function deleteProduto(id) {
    if (!isElevatedPainel()) return;
    if (!confirm('Tem certeza que deseja apagar este produto?')) return;

    try {
        await API.deleteProduto(id);
        loadProdutos();
        alert('Produto apagado com sucesso!');
    } catch (error) {
        alert('Erro ao apagar produto: ' + error.message);
    }
}

async function editProduto(id) {
    if (!isElevatedPainel()) return;
    const produto = allProdutos.find(p => p.produto_id == id);
    if (produto) openProdutoModal(produto);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Globais
window.editProduto = editProduto;
window.deleteProduto = deleteProduto;