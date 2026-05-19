# Maileon PHP API Client — CLI Examples

A collection of runnable PHP scripts for exploring and testing the Maileon REST API from the command line.

## Setup

```bash
# 1. Install dependencies (requires composer)
composer install

# 2. Copy and fill in credentials
cp .env.example .env
# edit .env — set MAILEON_API_KEY and MAILEON_TEST_EMAIL at minimum
```

`.env` is loaded automatically by `examples/bootstrap.php`. Alternatively copy
`conf/config.php.default` to `conf/config.php` and fill it in (env vars take precedence).

## Running examples

```bash
# List all available commands with safety levels
php examples/run.php list

# Or call a script directly (same result)
php examples/run.php contacts:get --email someone@example.com
```

Every script can also be invoked directly:

```bash
php examples/contacts/get-contact.php --email someone@example.com
```

---

## Commands

### Contacts

| Command | Safety | Description |
|---|---|---|
| `contacts:get` | read-only | Fetch a contact by email address |
| `contacts:create` | write | Create a contact from a JSON file |
| `contacts:sync` | write | Synchronize one or multiple contacts |
| `contacts:delete` | destructive | Delete a contact by email |

```bash
# Read
php examples/run.php contacts:get --email alice@example.com

# Create (dry-run first, then confirm)
php examples/run.php contacts:create --data examples/data/contact-create.json --dry-run
php examples/run.php contacts:create --data examples/data/contact-create.json --confirm

# Synchronize (single contact or array)
php examples/run.php contacts:sync --data examples/data/contact-create.json --dry-run
php examples/run.php contacts:sync --data examples/data/contact-create.json --confirm

# Delete
php examples/run.php contacts:delete --email alice@example.com --confirm
```

**Data file format** (`examples/data/contact-create.json`):

```json
{
  "email": "alice@example.com",
  "external_id": "ext-001",
  "permission": "doi",
  "standard_fields": {
    "FIRSTNAME": "Alice",
    "LASTNAME": "Smith"
  },
  "custom_fields": {
    "MyCustomField": "some value"
  }
}
```

---

### Mailings

| Command | Safety | Description |
|---|---|---|
| `mailings:list` | read-only | List mailings, optionally filtered by type/state |
| `mailings:create` | write | Create a new mailing draft |

```bash
# List (defaults: type=regular, first 10)
php examples/run.php mailings:list
php examples/run.php mailings:list --type regular --state draft --limit 5

# Create a draft
php examples/run.php mailings:create --name "My Newsletter" --subject "Hello World" --dry-run
php examples/run.php mailings:create --name "My Newsletter" --subject "Hello World"
```

---

### Reports

| Command | Safety | Description |
|---|---|---|
| `reports:kpis` | read-only | Fetch KPI counts for a specific mailing |

```bash
php examples/run.php reports:kpis --mailing-id 123456
```

Outputs recipients, opens, unique opens, clicks, unique clicks, bounces, and computed rates.

---

### Transactions

| Command | Safety | Description |
|---|---|---|
| `transactions:create-type` | write | Create a transaction type with default order fields |
| `transactions:send` | send | Send a transaction to a contact |

```bash
# Create a transaction type (with built-in order fields)
php examples/run.php transactions:create-type --name my_order_type --dry-run
php examples/run.php transactions:create-type --name my_order_type

# Send a transaction
php examples/run.php transactions:send --data examples/data/transaction-order.json --dry-run
php examples/run.php transactions:send --data examples/data/transaction-order.json --confirm
```

**Data file format** (`examples/data/transaction-order.json`):

```json
{
  "type_name": "my_order_type",
  "contact_email": "alice@example.com",
  "attributes": {
    "order_id": "ORD-001",
    "order_total": 49.99,
    "currency": "EUR",
    "product_name": "Widget",
    "quantity": 2
  }
}
```

The `transactions:send` command resolves the type name to its numeric ID automatically before sending.

---

### Data Extensions

| Command | Safety | Description |
|---|---|---|
| `dataextensions:list` | read-only | List all data extensions (paginated) |
| `dataextensions:records` | read-only | List records for a specific extension |

```bash
# List extensions
php examples/run.php dataextensions:list
php examples/run.php dataextensions:list --page 2 --page-size 50

# Get records for extension ID 42
php examples/run.php dataextensions:records --id 42
php examples/run.php dataextensions:records --id 42 --page-size 50 --fields email,created_at
```

---

## Safety flags

| Flag | Meaning |
|---|---|
| `--dry-run` | Print what would be sent without making any API call |
| `--confirm` | Required for write/send/destructive operations — confirms intent |

Scripts that modify or delete data require `--confirm`. Without it they print a warning and exit. `--dry-run` (where supported) prints the payload and exits without any API call.

## Output format

All scripts produce JSON output to stdout, making them easy to pipe into `jq`:

```bash
php examples/run.php contacts:get --email alice@example.com | jq '.data.email'
php examples/run.php mailings:list | jq '.[].name'
```

Exit code `0` on success, non-zero on failure. Error messages go to stderr.
