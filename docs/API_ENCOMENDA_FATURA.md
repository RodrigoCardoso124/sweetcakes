# API — Encomenda com fatura (app cliente)

## Preços

Os preços de produtos e o `total` da encomenda são **com IVA incluído** (valor final pago pelo cliente).

## Criar encomenda `POST /encomendas`

Campos habituais: `cliente_id`, `funcionario_id`, `estado`, `total`, linhas/detalhes, etc.

### Fatura com contribuinte (opcional)

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `quer_fatura_contribuinte` | bool | `true` se o cliente quer fatura com NIF |
| `fatura_com_contribuinte` | bool | Alias aceite |
| `fatura_nif` | string | NIF para a fatura (obrigatório se `quer_fatura_contribuinte` e o cliente ainda não tiver NIF guardado) |
| `nif` | string | Alias aceite para `fatura_nif` |

Exemplo:

```json
{
  "cliente_id": 12,
  "funcionario_id": 1,
  "estado": "pendente",
  "total": 24.60,
  "quer_fatura_contribuinte": true,
  "fatura_nif": "123456789"
}
```

Se `quer_fatura_contribuinte` for `true` e não enviar NIF, a API usa o NIF já guardado em `pessoas.nif`. Se não existir, responde **400** com mensagem de NIF obrigatório.

O NIF enviado é também gravado na ficha do cliente (`pessoas.nif`).

## Cliente — NIF na ficha

- `POST /pessoas` — campo opcional `nif`
- `PUT /pessoas/{id}` — campo opcional `nif` (o próprio cliente pode atualizar o perfil)

## Migrações

1. `/api/migrate_009_faturacao.php`
2. `/api/migrate_010_encomenda_fatura.php`
3. `/api/migrate_011_backfill_nifs.php` — NIF em clientes existentes (dados de teste / integridade)
