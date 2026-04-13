// API: base dinâmica (XAMPP em subpastas ou raiz) + sessão (cookies + X-Session-Id para apps)
const API_DEBUG = false;

const API_BASE_URL = (function () {
  if (typeof window.API_BASE_URL !== 'undefined') return window.API_BASE_URL;
  return window.location.origin + '/api/index.php';
})();

const PUBLIC_BASE_URL = (function () {
  if (typeof window.PUBLIC_BASE_URL !== 'undefined') return window.PUBLIC_BASE_URL;
  return window.location.origin;
})();

function getProdutoImageUrl(imagemPath) {
  if (!imagemPath) return null;
  if (/^https?:\/\//i.test(imagemPath)) return imagemPath;
  var base = PUBLIC_BASE_URL.replace(/\/$/, '');
  return base + '/api/image.php?path=' + encodeURIComponent(imagemPath);
}

function apiSessionHeaders() {
  var sid = localStorage.getItem('apiSessionId');
  var h = {};
  if (sid) h['X-Session-Id'] = sid;
  return h;
}

function buildApiUrl(endpoint) {
  var e = (endpoint || '').replace(/^\//, '');
  var qPos = e.indexOf('?');
  var route = qPos === -1 ? e : e.substring(0, qPos);
  var query = qPos === -1 ? '' : e.substring(qPos + 1);
  var url = API_BASE_URL + '?route=' + encodeURIComponent(route);
  if (query) url += '&' + query;
  return url;
}

async function apiRequest(endpoint, method, data) {
  method = method || 'GET';
  if (window.location.protocol === 'file:') {
    throw new Error(
      'Aplicação aberta em file://. Abre pelo servidor web (ex.: http://localhost/.../admin/login.html).'
    );
  }
  var url = buildApiUrl(endpoint);
  var headers = Object.assign({ Accept: 'application/json' }, apiSessionHeaders());
  var options = {
    method: method,
    credentials: 'include',
    headers: headers
  };

  if (data !== undefined && data !== null && (method === 'POST' || method === 'PUT')) {
    headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(data);
  }

  if (API_DEBUG) console.log('[API]', method, url, data || '');

  var response = await fetch(url, options);
  var responseText = await response.text();
  var result;
  try {
    result = JSON.parse(responseText);
  } catch (e) {
    var err = new Error('Resposta inválida do servidor (não é JSON).');
    err.status = response.status;
    err.response = { raw: responseText };
    throw err;
  }

  if (response.status === 401 || response.status === 403) {
    if (typeof clearAdminSession === 'function') clearAdminSession();
    var path = window.location.pathname || '';
    if (!/login\.html$/i.test(path) && /\/admin\//i.test(path)) {
      window.location.href = 'login.html';
    }
  }

  if (!response.ok) {
    var error = new Error(result.message || 'Erro ' + response.status);
    error.status = response.status;
    error.response = result;
    throw error;
  }

  return result;
}

const API = {
  async logout() {
    return apiRequest('logout', 'POST', {});
  },

  async adminLogin(email, password) {
    return apiRequest('admin/login', 'POST', { email: email, password: password });
  },

  async login(email, password) {
    return apiRequest('login', 'POST', { email: email, password: password });
  },

  async getEncomendas(page, perPage) {
    page = page || 1;
    perPage = perPage || 50;
    return apiRequest('encomendas?page=' + encodeURIComponent(page) + '&per_page=' + encodeURIComponent(perPage), 'GET');
  },

  /** Lista todas as encomendas (várias páginas) — para estatísticas / export */
  async getAllEncomendas() {
    var all = [];
    var page = 1;
    var totalPages = 1;
    do {
      var res = await this.getEncomendas(page, 100);
      var chunk = res.data || [];
      all = all.concat(chunk);
      totalPages = res.total_pages || 1;
      page++;
    } while (page <= totalPages);
    return all;
  },

  async getEncomenda(id) {
    return apiRequest('encomendas/' + encodeURIComponent(id), 'GET');
  },

  async updateEncomenda(id, data) {
    return apiRequest('encomendas/' + encodeURIComponent(id), 'PUT', data);
  },

  async deleteEncomenda(id) {
    return apiRequest('encomendas/' + encodeURIComponent(id), 'DELETE');
  },

  async getEncomendaDetalhes(encomendaId) {
    var url = buildApiUrl('encomenda_detalhes?encomenda_id=' + encodeURIComponent(encomendaId));
    var response = await fetch(url, {
      method: 'GET',
      credentials: 'include',
      headers: Object.assign({ Accept: 'application/json' }, apiSessionHeaders())
    });
    if (response.status === 401 || response.status === 403) {
      if (typeof clearAdminSession === 'function') clearAdminSession();
      var p = window.location.pathname || '';
      if (!/login\.html$/i.test(p) && /\/admin\//i.test(p)) window.location.href = 'login.html';
    }
    if (!response.ok) {
      var t = await response.text();
      throw new Error('Erro ao buscar detalhes: ' + response.status + ' ' + t.substring(0, 120));
    }
    return response.json();
  },

  async getPessoa(id) {
    return apiRequest('pessoas/' + encodeURIComponent(id), 'GET');
  },

  async getPessoas() {
    return apiRequest('pessoas', 'GET');
  },

  async getProduto(id) {
    return apiRequest('produtos/' + encodeURIComponent(id), 'GET');
  },

  async getProdutos() {
    return apiRequest('produtos', 'GET');
  },

  async createProduto(data, imageFile) {
    if (imageFile) {
      var formData = new FormData();
      formData.append('nome', data.nome);
      formData.append('descricao', data.descricao);
      formData.append('preco', data.preco);
      formData.append('disponivel', data.disponivel || 1);
      formData.append('alergenios', data.alergenios || '');
      formData.append('imagem', imageFile);
      var response = await fetch(buildApiUrl('produtos'), {
        method: 'POST',
        credentials: 'include',
        headers: apiSessionHeaders(),
        body: formData
      });
      if (response.status === 401 || response.status === 403) {
        if (typeof clearAdminSession === 'function') clearAdminSession();
        var p1 = window.location.pathname || '';
        if (!/login\.html$/i.test(p1) && /\/admin\//i.test(p1)) window.location.href = 'login.html';
      }
      if (!response.ok) {
        var err = await response.json().catch(function () {
          return {};
        });
        throw new Error(err.message || 'Erro ao criar produto');
      }
      return response.json();
    }
    return apiRequest('produtos', 'POST', data);
  },

  async updateProduto(id, data, imageFile) {
    if (imageFile) {
      var formData = new FormData();
      if (data.nome) formData.append('nome', data.nome);
      if (data.descricao) formData.append('descricao', data.descricao);
      if (data.preco) formData.append('preco', data.preco);
      if (data.disponivel !== undefined) formData.append('disponivel', data.disponivel);
      if (data.alergenios !== undefined) formData.append('alergenios', data.alergenios);
      formData.append('_method', 'PUT');
      formData.append('imagem', imageFile);
      var response = await fetch(buildApiUrl('produtos/' + encodeURIComponent(id)), {
        method: 'POST',
        credentials: 'include',
        headers: apiSessionHeaders(),
        body: formData
      });
      if (response.status === 401 || response.status === 403) {
        if (typeof clearAdminSession === 'function') clearAdminSession();
        var p2 = window.location.pathname || '';
        if (!/login\.html$/i.test(p2) && /\/admin\//i.test(p2)) window.location.href = 'login.html';
      }
      if (!response.ok) {
        var err = await response.json().catch(function () {
          return {};
        });
        throw new Error(err.message || 'Erro ao atualizar produto');
      }
      return response.json();
    }
    return apiRequest('produtos/' + encodeURIComponent(id), 'PUT', data);
  },

  async deleteProduto(id) {
    return apiRequest('produtos/' + encodeURIComponent(id), 'DELETE');
  },

  async getFuncionarios() {
    return apiRequest('funcionarios', 'GET');
  },

  async getFuncionario(id) {
    return apiRequest('funcionarios/' + encodeURIComponent(id), 'GET');
  },

  async createFuncionario(data) {
    return apiRequest('funcionarios', 'POST', data);
  },

  async updateFuncionario(id, data) {
    return apiRequest('funcionarios/' + encodeURIComponent(id), 'PUT', data);
  },

  async deleteFuncionario(id) {
    return apiRequest('funcionarios/' + encodeURIComponent(id), 'DELETE');
  },

  async createCliente(data) {
    return apiRequest('pessoas', 'POST', data);
  },

  async updateCliente(id, data) {
    return apiRequest('pessoas/' + encodeURIComponent(id), 'PUT', data);
  },

  async deleteCliente(id) {
    return apiRequest('pessoas/' + encodeURIComponent(id), 'DELETE');
  }
};

if (typeof module !== 'undefined' && module.exports) {
  module.exports = API;
}
