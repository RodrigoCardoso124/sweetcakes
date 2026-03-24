# Painel de Administração - Sweet Cakes

Sistema web completo para gestão de encomendas do Sweet Cakes.

## 📁 Estrutura de Arquivos

```
admin/
├── index.html              # Dashboard principal com lista de encomendas
├── login.html              # Página de login
├── encomenda.html          # Página de detalhes da encomenda
├── styles.css              # Estilos CSS
├── api.js                  # Funções de comunicação com a API
├── login.js                # Lógica de login
├── app.js                  # Lógica do dashboard
└── encomenda-detail.js     # Lógica da página de detalhes
```

## 🚀 Como Usar

### 1. Acessar o Painel

Abra no navegador:
```
http://localhost/pap_flutter/sweet_cakes_api/public/admin/login.html
```

### 2. Login

Use as credenciais de um utilizador existente no sistema. O sistema verifica através do endpoint `/login` da API.

### 3. Dashboard Principal

Após o login, você verá:
- **Estatísticas**: Contadores de encomendas por estado
- **Filtros**: Filtrar por estado ou pesquisar por ID/cliente
- **Tabela de Encomendas**: Lista todas as encomendas com:
  - ID da encomenda
  - Informações do cliente
  - Total
  - Estado atual
  - Data
  - Ações (Ver detalhes / Alterar estado)

### 4. Detalhes da Encomenda

Clique em "Ver" para ver os detalhes completos:
- Informações da encomenda (estado, total, IDs)
- Informações do cliente (nome, email, telemóvel, morada)
- Lista de produtos da encomenda
- Opção para alterar o estado

### 5. Alterar Estado

Você pode alterar o estado de uma encomenda para:
- **Pendente**: Aguardando aprovação
- **Aceite**: Encomenda aceite pelo administrador
- **Em Preparação**: Sendo preparada
- **Pronta**: Pronta para entrega
- **Entregue**: Já entregue ao cliente
- **Cancelada**: Encomenda cancelada

## 🎨 Funcionalidades

✅ Login seguro com verificação de credenciais
✅ Dashboard com estatísticas em tempo real
✅ Filtros e pesquisa de encomendas
✅ Visualização detalhada de cada encomenda
✅ Alteração de estado das encomendas
✅ Interface responsiva (funciona em mobile)
✅ Auto-refresh a cada 30 segundos
✅ Design moderno e intuitivo

## 🔧 Configuração

O sistema está configurado para usar a API em:
```
http://localhost/pap_flutter/sweet_cakes_api/public/index.php
```

Se precisar alterar, edite o arquivo `api.js`:
```javascript
const API_BASE_URL = window.location.origin + '/pap_flutter/sweet_cakes_api/public/index.php';
```

## 📝 Notas

- O sistema usa `localStorage` para manter a sessão do administrador
- As encomendas são atualizadas automaticamente a cada 30 segundos
- Todos os estados são salvos na base de dados através da API
- O sistema funciona completamente offline após o carregamento inicial (exceto para atualizações)

## 🐛 Troubleshooting

### Erro ao fazer login
- Verifique se as credenciais estão corretas
- Verifique se a API está acessível
- Verifique o console do navegador para erros

### Encomendas não aparecem
- Verifique se há encomendas na base de dados
- Verifique se a API está retornando dados corretamente
- Verifique o console do navegador para erros de CORS

### Detalhes não carregam
- Verifique se existem detalhes (produtos) associados à encomenda
- Verifique se a API está retornando os detalhes corretamente

## 📱 Responsividade

O painel é totalmente responsivo e funciona em:
- Desktop
- Tablet
- Mobile

A sidebar colapsa automaticamente em telas menores.

