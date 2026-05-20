// API Configuration: em localhost usa o path do XAMPP; no servidor usa /index.php
const API_BASE_URL = (function () {
  if (typeof window.API_BASE_URL !== 'undefined') return window.API_BASE_URL;
  var isLocal = /^localhost$|^127\.0\.0\.1$/i.test(window.location.hostname);
  return isLocal
    ? window.location.origin + '/pap_flutter/sweet_cakes_api/public/index.php'
    : window.location.origin + '/index.php';
})();

// Helper function to make API requests
async function apiRequest(endpoint, method = 'GET', data = null) {
    const url = `${API_BASE_URL}/${endpoint}`;
    const headers = {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
    };

    // Anexa token de sessao do painel. Neste backend/Vercel a sessao duradoura
    // e validada por X-Session-ID; Authorization fica como compatibilidade com
    // outras copias da API.
    const sessionId = localStorage.getItem('apiSessionId');
    const token = localStorage.getItem('adminToken') || sessionId;
    if (sessionId) {
        headers['X-Session-ID'] = sessionId;
    }
    if (token) {
        headers['Authorization'] = 'Bearer ' + token;
    }

    const options = { method, headers };

    if (data && (method === 'POST' || method === 'PUT')) {
        options.body = JSON.stringify(data);
    }

    try {
        console.log(`🌐 [API] ${method} ${url}`, data ? { data } : '');
        const response = await fetch(url, options);
        
        // Get response text first to debug
        const responseText = await response.text();
        console.log(`📥 [API] Status: ${response.status} ${response.statusText}`);
        console.log(`📥 [API] Response body:`, responseText);
        
        // Try to parse JSON response
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (e) {
            // If response is not JSON, log the actual response
            console.error('❌ [API] Resposta não é JSON válido!');
            console.error('❌ [API] Status:', response.status, response.statusText);
            console.error('❌ [API] Response Text (primeiros 500 chars):', responseText.substring(0, 500));
            console.error('❌ [API] Response Text (completo):', responseText);
            console.error('❌ [API] Erro ao fazer parse:', e);
            
            // Create error with actual response text
            const error = new Error(`Resposta do servidor não é JSON válido. Status: ${response.status}. Resposta: ${responseText.substring(0, 200)}`);
            error.status = response.status;
            error.response = { 
                message: 'Erro ao processar resposta do servidor',
                raw: responseText,
                status: response.status,
                statusText: response.statusText
            };
            throw error;
        }
        
        if (!response.ok) {
            // Create error with message from API
            const error = new Error(result.message || `Erro ${response.status}: ${response.statusText}`);
            error.status = response.status;
            error.response = result;
            console.error('❌ [API] Erro na resposta:', result);
            throw error;
        }
        
        console.log('✅ [API] Sucesso:', result);
        return result;
    } catch (error) {
        // If it's already our custom error, re-throw it
        if (error.message && error.status) {
            throw error;
        }
        // Otherwise, wrap it
        console.error('❌ [API] Erro na requisição:', error);
        throw new Error(error.message || 'Erro ao comunicar com o servidor');
    }
}

// API Functions
const API = {
    // Admin Login (only for funcionarios)
    async adminLogin(email, password) {
        return apiRequest('admin/login', 'POST', { email, password });
    },

    // Regular Login (for app)
    async login(email, password) {
        return apiRequest('login', 'POST', { email, password });
    },

    async getSessionInfo() {
        return apiRequest('session');
    },

    async logout() {
        return apiRequest('logout', 'POST');
    },

    // Encomendas. O backend devolve um envelope paginado
    // ({data, page, per_page, total, total_pages}); aqui devolvemos sempre
    // um array para que o codigo do admin (slice, forEach, etc.) funcione.
    async getEncomendas() {
        const r = await apiRequest('encomendas');
        if (Array.isArray(r)) return r;
        if (r && Array.isArray(r.data)) return r.data;
        return [];
    },

    // Alias retrocompativel (app.js, estatisticas.js antigos chamavam este nome).
    async getAllEncomendas() {
        return this.getEncomendas();
    },

    async getEncomenda(id) {
        return apiRequest(`encomendas/${id}`);
    },

    async updateEncomenda(id, data) {
        return apiRequest(`encomendas/${id}`, 'PUT', data);
    },

    async deleteEncomenda(id) {
        return apiRequest(`encomendas/${id}`, 'DELETE');
    },

    // Encomenda Detalhes
    async getEncomendaDetalhes(encomendaId) {
        try {
            const url = `${API_BASE_URL}/encomenda_detalhes?encomenda_id=${encomendaId}`;
            const sessionId = localStorage.getItem('apiSessionId');
            const token = localStorage.getItem('adminToken') || sessionId;
            const headers = { 'Accept': 'application/json' };
            if (sessionId) headers['X-Session-ID'] = sessionId;
            if (token) headers['Authorization'] = 'Bearer ' + token;

            const response = await fetch(url, {
                method: 'GET',
                headers
            });
            
            console.log('📥 Resposta status:', response.status, response.statusText);
            
            if (!response.ok) {
                const errorText = await response.text();
                console.error('❌ Erro na resposta:', errorText);
                throw new Error(`Erro ao buscar detalhes: ${response.status} ${response.statusText}`);
            }
            
            const data = await response.json();
            console.log('✅ Detalhes recebidos:', data);
            return data;
        } catch (error) {
            console.error('❌ Error fetching detalhes:', error);
            // Fallback: try to get all and filter
            try {
                console.log('🔄 Tentando fallback: buscar todos os detalhes...');
                const allDetalhes = await apiRequest('encomenda_detalhes');
                if (Array.isArray(allDetalhes)) {
                    const filtered = allDetalhes.filter(d => d.encomenda_id == encomendaId);
                    console.log('✅ Fallback funcionou, detalhes filtrados:', filtered);
                    return filtered;
                }
            } catch (fallbackError) {
                console.error('❌ Fallback also failed:', fallbackError);
            }
            return [];
        }
    },

    // Pessoas (Clientes)
    async getPessoa(id) {
        return apiRequest(`pessoas/${id}`);
    },

    async getPessoas() {
        return apiRequest('pessoas');
    },

    // Produtos
    async getProduto(id) {
        return apiRequest(`produtos/${id}`);
    },

    async getProdutos() {
        return apiRequest('produtos');
    },

    async createProduto(data, imageFile = null) {
        if (imageFile) {
            const formData = new FormData();
            formData.append('nome', data.nome);
            formData.append('descricao', data.descricao);
            formData.append('preco', data.preco);
            formData.append('disponivel', data.disponivel || 1);
            formData.append('stock_atual', data.stock_atual != null ? String(data.stock_atual) : '0');
            formData.append('stock_minimo', data.stock_minimo != null ? String(data.stock_minimo) : '0');
            formData.append('imagem', imageFile);

            const sessionId = localStorage.getItem('apiSessionId');
            const token = localStorage.getItem('adminToken') || sessionId;
            const headers = {};
            if (sessionId) headers['X-Session-ID'] = sessionId;
            if (token) headers['Authorization'] = 'Bearer ' + token;

            const response = await fetch(`${API_BASE_URL}/produtos`, {
                method: 'POST',
                headers,
                body: formData
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Erro ao criar produto');
            }

            return await response.json();
        } else {
            return apiRequest('produtos', 'POST', data);
        }
    },

    async updateProduto(id, data, imageFile = null) {
        if (imageFile) {
            const formData = new FormData();
            if (data.nome) formData.append('nome', data.nome);
            if (data.descricao !== undefined && data.descricao !== null) {
                formData.append('descricao', data.descricao);
            }
            if (data.preco !== undefined && data.preco !== null && data.preco !== '') {
                formData.append('preco', String(data.preco));
            }
            if (data.disponivel !== undefined && data.disponivel !== null) {
                formData.append('disponivel', String(data.disponivel));
            }
            formData.append('stock_atual', String(data.stock_atual != null ? data.stock_atual : 0));
            formData.append('stock_minimo', String(data.stock_minimo != null ? data.stock_minimo : 0));
            if (data.alergenios !== undefined && data.alergenios !== null) {
                formData.append('alergenios', String(data.alergenios));
            }
            formData.append('imagem', imageFile);
            // PUT + multipart em muitos servidores não preenche $_POST; POST com _method=PUT sim.
            formData.append('_method', 'PUT');

            const sessionId = localStorage.getItem('apiSessionId');
            const token = localStorage.getItem('adminToken') || sessionId;
            const headers = {};
            if (sessionId) headers['X-Session-ID'] = sessionId;
            if (token) headers['Authorization'] = 'Bearer ' + token;

            const response = await fetch(`${API_BASE_URL}/produtos/${id}`, {
                method: 'POST',
                headers,
                body: formData
            });

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.message || 'Erro ao atualizar produto');
            }

            return await response.json();
        } else {
            return apiRequest(`produtos/${id}`, 'PUT', data);
        }
    },

    async deleteProduto(id) {
        return apiRequest(`produtos/${id}`, 'DELETE');
    },

    // Funcionários
    async getFuncionarios() {
        return apiRequest('funcionarios');
    },

    async getFuncionario(id) {
        return apiRequest(`funcionarios/${id}`);
    },

    async createFuncionario(data) {
        return apiRequest('funcionarios', 'POST', data);
    },

    async updateFuncionario(id, data) {
        return apiRequest(`funcionarios/${id}`, 'PUT', data);
    },

    async deleteFuncionario(id) {
        return apiRequest(`funcionarios/${id}`, 'DELETE');
    },

    // Clientes (Pessoas)
    async createCliente(data) {
        return apiRequest('pessoas', 'POST', data);
    },

    async updateCliente(id, data) {
        return apiRequest(`pessoas/${id}`, 'PUT', data);
    },

    async deleteCliente(id) {
        return apiRequest(`pessoas/${id}`, 'DELETE');
    },

    // Promoções (admin)
    async getPromocoes() {
        return apiRequest('promocoes');
    },

    async getPromocoesAll() {
        return apiRequest('promocoes?all=1');
    },

    async getPromocao(id) {
        return apiRequest(`promocoes/${id}`);
    },

    async createPromocao(data) {
        return apiRequest('promocoes', 'POST', data);
    },

    async updatePromocao(id, data) {
        return apiRequest(`promocoes/${id}`, 'PUT', data);
    },

    async deletePromocao(id) {
        return apiRequest(`promocoes/${id}`, 'DELETE');
    },

    async getIngredientes() {
        return apiRequest('ingredientes');
    },

    async createIngrediente(data) {
        return apiRequest('ingredientes', 'POST', data);
    },

    async updateIngrediente(id, data) {
        return apiRequest(`ingredientes/${id}`, 'PUT', data);
    },

    async getProducaoResumo() {
        return apiRequest('producao');
    },

    async postProducao(body) {
        return apiRequest('producao', 'POST', body);
    },

    async getReceitas() {
        return apiRequest('receitas');
    },

    async getReceita(id) {
        return apiRequest(`receitas/${id}`);
    },

    async createReceita(data) {
        return apiRequest('receitas', 'POST', data);
    },

    async updateReceita(id, data) {
        return apiRequest(`receitas/${id}`, 'PUT', data);
    },

    async deleteReceita(id) {
        return apiRequest(`receitas/${id}`, 'DELETE');
    },

    async getPedidosIngrediente(estado) {
        const q = estado ? `?estado=${encodeURIComponent(estado)}` : '';
        return apiRequest(`pedidos_ingrediente${q}`);
    },

    async createPedidoIngrediente(data) {
        return apiRequest('pedidos_ingrediente', 'POST', data);
    },

    async updatePedidoIngrediente(id, data) {
        return apiRequest(`pedidos_ingrediente/${id}`, 'PUT', data);
    },

    async updatePedidoIngredienteRecebido(id, data, pdfFile) {
        if (!pdfFile) {
            return this.updatePedidoIngrediente(id, data);
        }
        const url = `${API_BASE_URL}/pedidos_ingrediente/${id}`;
        const fd = new FormData();
        fd.append('_method', 'PUT');
        fd.append('estado', data.estado || 'recebido');
        Object.keys(data).forEach((k) => {
            if (k === 'estado') return;
            if (data[k] != null && data[k] !== '') fd.append(k, String(data[k]));
        });
        fd.append('documento', pdfFile);
        const headers = { Accept: 'application/json' };
        const sessionId = localStorage.getItem('apiSessionId');
        const token = localStorage.getItem('adminToken') || sessionId;
        if (sessionId) headers['X-Session-ID'] = sessionId;
        if (token) headers['Authorization'] = 'Bearer ' + token;
        const response = await fetch(url, { method: 'POST', headers, body: fd });
        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            throw new Error('Resposta inválida do servidor');
        }
        if (!response.ok) {
            const detail = result.error_detail || result.hint || '';
            throw new Error(
                (result.message || 'Erro ao receber pedido') + (detail ? ' — ' + detail : '')
            );
        }
        return result;
    },

    async getFinancasResumo(de, ate) {
        return apiRequest(`financas?de=${encodeURIComponent(de)}&ate=${encodeURIComponent(ate)}&view=resumo`);
    },

    async getFinancasProdutos(de, ate) {
        return apiRequest(`financas?de=${encodeURIComponent(de)}&ate=${encodeURIComponent(ate)}&view=produtos`);
    },

    async getFinancasCaixa(de, ate) {
        return apiRequest(`financas?de=${encodeURIComponent(de)}&ate=${encodeURIComponent(ate)}&view=caixa`);
    },

    async getFinancasMovimentos(de, ate) {
        return apiRequest(`financas?de=${encodeURIComponent(de)}&ate=${encodeURIComponent(ate)}&view=movimentos`);
    },

    async recalcularCustosProdutos() {
        return apiRequest('financas/recalcular-custos');
    },

    async getDespesas(de, ate) {
        return apiRequest(`despesas?de=${encodeURIComponent(de)}&ate=${encodeURIComponent(ate)}`);
    },

    async createDespesa(data) {
        return apiRequest('despesas', 'POST', data);
    },

    async deleteDespesa(id) {
        return apiRequest(`despesas/${id}`, 'DELETE');
    },

    async getDespesa(id) {
        return apiRequest(`despesas/${id}`);
    },

    async updateDespesa(id, data) {
        return apiRequest(`despesas/${id}`, 'PUT', data);
    },

    async getFornecedores() {
        return apiRequest('fornecedores');
    },

    async getFornecedor(id) {
        return apiRequest(`fornecedores/${id}`);
    },

    async createFornecedor(data) {
        return apiRequest('fornecedores', 'POST', data);
    },

    async updateFornecedor(id, data) {
        return apiRequest(`fornecedores/${id}`, 'PUT', data);
    },

    async deleteFornecedor(id) {
        return apiRequest(`fornecedores/${id}`, 'DELETE');
    },

    async getFaturacaoEncomendasPendentes() {
        return apiRequest('faturacao?view=encomendas-pendentes');
    },

    async getFaturacaoEmitidas(de, ate, estado) {
        let q = `faturacao?view=emitidas&de=${encodeURIComponent(de)}&ate=${encodeURIComponent(ate)}`;
        if (estado) q += `&estado=${encodeURIComponent(estado)}`;
        return apiRequest(q);
    },

    async getFaturacaoRecebidas(de, ate) {
        return apiRequest(
            `faturacao?view=recebidas&de=${encodeURIComponent(de)}&ate=${encodeURIComponent(ate)}`
        );
    },

    async getFaturacaoResumoIva(de, ate) {
        return apiRequest(
            `faturacao?view=resumo-iva&de=${encodeURIComponent(de)}&ate=${encodeURIComponent(ate)}`
        );
    },

    async getFaturacaoConfig() {
        return apiRequest('faturacao?view=config');
    },

    async saveFaturacaoConfig(data) {
        return apiRequest('faturacao', 'POST', data);
    },

    async getFatura(id) {
        return apiRequest(`faturacao/${id}`);
    },

    async getFaturacaoPreview(encomendaId, taxaIva) {
        let q = `faturacao?view=preview&encomenda_id=${encodeURIComponent(encomendaId)}`;
        if (taxaIva != null) q += `&taxa_iva_pct=${encodeURIComponent(taxaIva)}`;
        return apiRequest(q);
    },

    async emitirFatura(data) {
        return apiRequest('faturacao', 'POST', Object.assign({ action: 'emitir' }, data));
    },

    async anularFatura(id) {
        return apiRequest(`faturacao/${id}`, 'PUT', { action: 'anular' });
    },

    async getFaturacaoArquivo(de, ate, opts) {
        opts = opts || {};
        let q =
            'faturacao?view=arquivo&de=' +
            encodeURIComponent(de) +
            '&ate=' +
            encodeURIComponent(ate);
        if (opts.tipo) q += '&tipo=' + encodeURIComponent(opts.tipo);
        if (opts.q) q += '&q=' + encodeURIComponent(opts.q);
        if (opts.com_ficheiro === true) q += '&com_ficheiro=1';
        if (opts.com_ficheiro === false) q += '&com_ficheiro=0';
        return apiRequest(q);
    },

    async downloadFaturacaoFicheiro(ficheiroId, inline) {
        const sessionId = localStorage.getItem('apiSessionId') || '';
        const token = localStorage.getItem('adminToken') || sessionId;
        let url =
            `${API_BASE_URL}/faturacao?view=download&ficheiro_id=${encodeURIComponent(String(ficheiroId))}` +
            (inline ? '&inline=1' : '');
        if (sessionId) {
            url += '&access_token=' + encodeURIComponent(sessionId);
        }
        const headers = { Accept: 'application/pdf,*/*' };
        if (sessionId) headers['X-Session-ID'] = sessionId;
        if (token) headers['Authorization'] = 'Bearer ' + token;
        const response = await fetch(url, { method: 'GET', headers, credentials: 'include' });
        if (!response.ok) {
            const text = await response.text();
            let msg = 'Erro ao abrir ficheiro (sessão expirada?)';
            try {
                const j = JSON.parse(text);
                if (j.message) msg = j.message;
            } catch (e) {
                /* ignore */
            }
            const err = new Error(msg);
            err.status = response.status;
            throw err;
        }
        const blob = await response.blob();
        const disp = response.headers.get('Content-Disposition') || '';
        let nome = 'documento.pdf';
        const m = /filename\*?=(?:UTF-8'')?["']?([^"';]+)/i.exec(disp);
        if (m && m[1]) nome = decodeURIComponent(m[1].trim());
        const mime = blob.type && blob.type !== 'application/octet-stream' ? blob.type : 'application/pdf';
        const typed = blob.type === mime ? blob : new Blob([blob], { type: mime });
        return { blob: typed, nome, url: URL.createObjectURL(typed), externo: false };
    },

    async openFaturacaoFicheiro(ficheiroId) {
        const fid = parseInt(String(ficheiroId || ''), 10);
        if (!fid) {
            throw new Error('Sem ficheiro arquivado');
        }
        const sessionId = localStorage.getItem('apiSessionId') || '';
        const token = localStorage.getItem('adminToken') || sessionId;
        let url =
            `${API_BASE_URL}/faturacao?view=download&ficheiro_id=${encodeURIComponent(String(fid))}&inline=1`;
        if (sessionId) {
            url += '&access_token=' + encodeURIComponent(sessionId);
        }
        const w = window.open(url, '_blank', 'noopener,noreferrer');
        if (!w) {
            throw new Error('O browser bloqueou o popup. Permita popups para este site.');
        }
    },

    async _faturacaoMultipart(formData) {
        const url = `${API_BASE_URL}/faturacao`;
        const headers = { Accept: 'application/json' };
        const sessionId = localStorage.getItem('apiSessionId');
        const token = localStorage.getItem('adminToken') || sessionId;
        if (sessionId) headers['X-Session-ID'] = sessionId;
        if (token) headers['Authorization'] = 'Bearer ' + token;
        const response = await fetch(url, { method: 'POST', headers, body: formData });
        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (e) {
            throw new Error('Resposta inválida do servidor');
        }
        if (!response.ok) {
            throw new Error(result.message || 'Erro no pedido');
        }
        return result;
    },

    async uploadFaturacaoDocumento(tipoDocumento, documentoId, file) {
        const fd = new FormData();
        fd.append('action', 'upload');
        fd.append('tipo_documento', tipoDocumento);
        fd.append('documento_id', String(documentoId));
        fd.append('documento', file);
        return this._faturacaoMultipart(fd);
    },

    async createFaturaRecebida(data, pdfFile) {
        if (!pdfFile) {
            return apiRequest('faturacao', 'POST', Object.assign({ action: 'recebida' }, data));
        }
        const fd = new FormData();
        fd.append('action', 'recebida');
        Object.keys(data).forEach((k) => {
            if (data[k] != null && data[k] !== '') fd.append(k, String(data[k]));
        });
        fd.append('documento', pdfFile);
        return this._faturacaoMultipart(fd);
    },

    async deleteFaturaRecebida(id) {
        return apiRequest(`faturacao/${id}`, 'DELETE');
    }
};

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = API;
}

