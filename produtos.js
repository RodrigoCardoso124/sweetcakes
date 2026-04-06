// produtos.js

// Produtos management
let allProdutos = [];

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
    return `image.php?path=${encodeURIComponent(imagemPath.replace(/\\/g, '/'))}`;
}

document.addEventListener('DOMContentLoaded', () => {
    if (typeof initAdminShell === 'function') {
        if (initAdminShell() === false) return;
    } else if (typeof requireAdminPageAuth === 'function' && !requireAdminPageAuth()) return;

    loadProdutos();
    setupEventListeners();
});

function setupEventListeners() {
    document.getElementById('refreshBtn').addEventListener('click', loadProdutos);
    document.getElementById('addProdutoBtn').addEventListener('click', () => openProdutoModal());

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
                    <div class="product-actions">
                        <button class="btn btn-secondary" onclick="editProduto(${produto.produto_id})">Editar</button>
                        <button class="btn btn-danger" onclick="deleteProduto(${produto.produto_id})">Apagar</button>
                    </div>
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
        document.getElementById('produtoAlergenios').value = Array.isArray(produto.alergenios)
            ? produto.alergenios.join(', ')
            : '';
        
        if (produto.imagem) {
            const imgUrl = getProdutoImageUrl(produto.imagem);
            preview.innerHTML = `<img src="${imgUrl}" style="max-width: 200px; border-radius: 8px;">`;
        }
    } else {
        title.textContent = 'Novo Produto';
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
    
    const produtoId = document.getElementById('produtoId').value;
    const formData = {
        nome: document.getElementById('produtoNome').value,
        descricao: document.getElementById('produtoDescricao').value,
        preco: document.getElementById('produtoPreco').value,
        disponivel: document.getElementById('produtoDisponivel').value,
        alergenios: document.getElementById('produtoAlergenios').value
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