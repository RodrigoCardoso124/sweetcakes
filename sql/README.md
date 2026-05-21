# Migrações SQL

Os ficheiros `.sql` desta pasta aplicam-se **apenas no phpMyAdmin** (ou cliente MySQL), por um administrador.

**Não** existem scripts `/api/migrate_*.php` públicos no site (foram removidos por segurança).

Ordem sugerida: `005` → … → `013` (conforme o que ainda não tiver na BD de produção).

## Limpar TODOS os dados (começar do zero)

### Passo 1 — Apagar tudo

Ficheiro: **`014_limpar_dados_operacionais.sql`**

1. Faz **backup** no phpMyAdmin (Exportar).
2. Executa o SQL do ficheiro `014`.

**Apaga:** faturas, funcionários, clientes, produtos, encomendas, tudo.  
**Recria só:** série FT vazia + `faturacao_config` com nome «Sweet Cakes» (NIF/morada vazios).

### Passo 2 — Voltar a ter login no painel

Ficheiro: **`015_criar_admin_inicial.sql`**

Executa a seguir. Cria um admin temporário:

| Campo | Valor |
|--------|--------|
| Email | `claudia31cardoso@gmail.com` |
| Password | `Sweetcakes77.` |

### Passo 3 — Catálogo de bolos (preçário)

Ficheiro: **`016_importar_catalogo_sweet_cakes.sql`**

30 produtos (semifrios, bolos, tartes, tortas) com preços e alergénios. Executar **uma vez**. Imagens depois no painel Produtos.
