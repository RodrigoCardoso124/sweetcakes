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

    // Anexa Bearer token se houver sessao admin guardada (necessario para
    // todos os endpoints protegidos: pessoas, encomendas, funcionarios, etc).
    const token = localStorage.getItem('adminToken');
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

    // Encomendas
    async getEncomendas() {
        return apiRequest('encomendas');
    },

    // Alias retrocompativel (app.js, estatisticas.js antigos chamavam este nome).
    async getAllEncomendas() {
        return apiRequest('encomendas');
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
            const token = localStorage.getItem('adminToken');
            const headers = { 'Accept': 'application/json' };
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
            formData.append('imagem', imageFile);

            const token = localStorage.getItem('adminToken');
            const headers = {};
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
            if (data.descricao) formData.append('descricao', data.descricao);
            if (data.preco) formData.append('preco', data.preco);
            if (data.disponivel !== undefined) formData.append('disponivel', data.disponivel);
            formData.append('imagem', imageFile);

            const token = localStorage.getItem('adminToken');
            const headers = {};
            if (token) headers['Authorization'] = 'Bearer ' + token;

            const response = await fetch(`${API_BASE_URL}/produtos/${id}`, {
                method: 'PUT',
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
    }
};

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = API;
}

