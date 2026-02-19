# Flux Wallet Transfer

Sistema de transferencias entre wallets con registro contable de doble partida, idempotencia y manejo de concurrencia.

## Stack

- Laravel 12
- PHP 8.4
- MySQL

## Requisitos

- PHP >= 8.4
- Composer
- MySQL

## Instalación
```bash
git clone https://github.com/tu-usuario/flux-wallet-transfer.git
cd flux-wallet-transfer
composer install
cp .env.example .env
php artisan key:generate
```

Configura tu base de datos en `.env`:
```env
DB_DATABASE=flux_wallet
DB_USERNAME=root
DB_PASSWORD=
```

Corre las migraciones y el seeder:
```bash
php artisan migrate
php artisan db:seed --class=WalletSeeder
```

## Correr los tests
```bash
php artisan test
```

## Endpoint

### POST /api/transfers

Crea una transferencia entre dos wallets.

**Headers**
```
Content-Type: application/json
Accept: application/json
```

**Body**
```json
{
    "idempotency_key": "uuid-unico-por-operacion",
    "source_wallet_id": 1,
    "destination_wallet_id": 2,
    "amount": "100.00",
    "currency": "USD",
    "description": "Pago de servicio"
}
```

**Respuestas**

| Status | Descripción |
|--------|-------------|
| 201 | Transferencia completada |
| 409 | Saldo insuficiente o conflicto de idempotencia |
| 422 | Error de validación |
| 500 | Error inesperado |

**Ejemplo de respuesta exitosa**
```json
{
    "message": "Transfer completed successfully",
    "data": {
        "id": 1,
        "idempotency_key": "uuid-unico-por-operacion",
        "source_wallet_id": 1,
        "destination_wallet_id": 2,
        "amount": "100.00",
        "currency": "USD",
        "description": "Pago de servicio",
        "status": "COMPLETED",
        "created_at": "2026-02-19T05:36:54.000000Z",
        "updated_at": "2026-02-19T05:36:54.000000Z"
    }
}
```

## Decisiones técnicas

**bcmath para precisión financiera** — PHP float acumula errores de punto flotante inaceptables en sistemas financieros. bcmath opera con strings y precisión exacta.

**lockForUpdate() con orden de IDs** — Las wallets se bloquean siempre en orden ascendente de ID para prevenir deadlocks cuando dos requests intentan transferir entre las mismas wallets simultáneamente.

**Idempotencia en 3 capas** — Consulta previa al transaction (fast path), unique constraint en DB y catch de duplicate entry para la race condition exacta.

**Transacción atómica** — Toda la operación ocurre en un DB::transaction(). Si algo falla, rollback automático. Nunca quedan balances a medias.

**Doble partida contable** — Cada transferencia genera un DEBIT en la wallet origen y un CREDIT en la wallet destino, garantizando que el dinero no se crea ni desaparece.

**decimal(18,2) en DB** — Consistente con bcmath. Nunca float para valores monetarios.
