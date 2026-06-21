# Wallet Bundle Design

> The Wallet bundle (`src/Wallet/`) provides user wallets, transactions, and atomic
> wallet-to-wallet transfers with deadlock prevention and idempotency.

---

## 1. Overview

Wallet is a financial module for managing user balances:

- **Wallets** per user per currency with balance in cents, optimistic locking, and freeze capability
- **WalletTransactions** with UUID, type classification, status tracking, and idempotency via `referenceId`
- **TransferService** with atomic from-wallet-to-wallet transfers, deadlock prevention, and rollback recovery

### 1.1 Entities

| Entity | Table | Purpose |
|--------|-------|---------|
| `Wallet` | `wallet` | User balance per currency (cents), freeze support, optimistic locking |
| `WalletTransaction` | `wallet_transaction` | Record of deposit/withdrawal/transfer/fee/refund |

---

## 2. File Structure

```
src/Wallet/
|-- Controller/Manage/
|   |-- TransactionController.php        # CRUD for transactions
|   |-- TransferController.php           # Transfer endpoint
|   |-- WalletController.php             # CRUD for wallets
|-- Entity/
|   |-- Wallet.php
|   |-- WalletTransaction.php
|-- Exception/
|   |-- InsufficientFundsException.php
|   |-- SameWalletTransferException.php
|   |-- WalletFrozenException.php
|-- Repository/
|   |-- WalletRepository.php
|   |-- WalletTransactionRepository.php
|-- Service/
    |-- TransactionService.php
    |-- TransferResult.php              # Transfer result DTO
    |-- TransferService.php             # Core transfer logic
    |-- TransferServiceInterface.php    # Transfer contract
    |-- WalletService.php
```

---

## 3. Entity Design

### 3.1 Wallet

| Field | Type | Detail |
|-------|------|--------|
| `id` | int | Auto-increment PK |
| `user` | ManyToOne -> User | Wallet owner |
| `currency` | string | Currency code (e.g., CNY, USD) |
| `balance` | int (bigint) | Balance in cents |
| `version` | int | `#[ORM\Version]` optimistic locking |
| `status` | string | `active` or `frozen` |
| `label` | string | Human-readable wallet name |

**Unique constraint**: `(user_id, currency)` -- one wallet per user per currency.

**Methods**: `isActive(): bool`, `isFrozen(): bool`

### 3.2 WalletTransaction

| Field | Type | Detail |
|-------|------|--------|
| `id` | int | Auto-increment PK |
| `uuid` | string(32) | UUID v4c for external reference |
| `fromWallet` | ManyToOne -> Wallet (nullable) | Source wallet |
| `toWallet` | ManyToOne -> Wallet (nullable) | Destination wallet |
| `amount` | int (bigint) | Amount in cents |
| `type` | string | `deposit`, `withdrawal`, `transfer`, `fee`, `refund` |
| `status` | string | `pending`, `completed`, `failed`, `reversed` |
| `referenceId` | string (unique) | Idempotency key |
| `description` | string | Human-readable note |
| `metadata` | JSON | Extensible data |

**Unique constraint**: `referenceId` -- prevents duplicate transactions.

**Methods**: `markCompleted()`, `markFailed()`

---

## 4. TransferService -- Atomic Transfer

### 4.1 Contract

```php
interface TransferServiceInterface
{
    /**
     * @param int|string $fromWalletId  Source wallet ID
     * @param int|string $toWalletId    Destination wallet ID
     * @param int        $amount         Amount in cents
     * @param string     $referenceId    Idempotency key (unique)
     * @param string     $description    Human-readable note
     * @return TransferResult
     * @throws InsufficientFundsException
     * @throws WalletFrozenException
     * @throws SameWalletTransferException
     */
    public function transfer(
        $fromWalletId,
        $toWalletId,
        int $amount,
        string $referenceId,
        string $description = ''
    ): TransferResult;
}
```

### 4.2 Transfer Algorithm

```
transfer(fromWalletId, toWalletId, amount, referenceId, description)
  |
  v
1. Idempotency Check
   -> Query existing transaction by referenceId
   -> If found and completed: return existing TransferResult
   -> If found and not completed: reject (duplicate in-flight)
  |
  v
2. Lock Wallets (Deadlock Prevention)
   -> Sort wallet IDs ascending
   -> SELECT ... FOR UPDATE on both wallets (in sorted order)
   -> This guarantees consistent lock ordering across concurrent transfers
  |
  v
3. Validation
   -> fromWallet != toWallet (SameWalletTransferException)
   -> fromWallet->isFrozen() || toWallet->isFrozen() (WalletFrozenException)
   -> fromWallet->getBalance() >= amount (InsufficientFundsException)
   -> Currency match: fromWallet->getCurrency() == toWallet->getCurrency()
  |
  v
4. Create Transaction Record
   -> type = 'transfer', status = 'pending'
   -> referenceId (idempotency key)
  |
  v
5. Execute Transfer (within DB transaction)
   -> beginTransaction()
   -> DQL UPDATE: fromWallet.balance -= amount
   -> DQL UPDATE: toWallet.balance += amount
   -> transaction->markCompleted()
   -> commit()
   |
   -> On failure: rollback(), transaction->markFailed()
   -> EM recovery: if !$em->isOpen(), recreate EM
  |
  v
6. Return TransferResult
   -> Transaction entity + post-transfer balances
```

### 4.3 TransferResult DTO

```php
class TransferResult
{
    public WalletTransaction $transaction;
    public int $fromWalletBalance;  // Post-transfer
    public int $toWalletBalance;    // Post-transfer
}
```

---

## 5. Exception Design

| Exception | When Thrown |
|-----------|-------------|
| `InsufficientFundsException` | Source wallet balance < transfer amount |
| `WalletFrozenException` | Either wallet status is `frozen` |
| `SameWalletTransferException` | fromWalletId == toWalletId |

All exceptions extend `\RuntimeException`.

---

## 6. API Endpoints

### 6.1 Manage (Admin, ROLE_ADMIN)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/manage/wallets` | List wallets |
| GET | `/api/v1/manage/wallets/{id}` | Wallet detail |
| POST | `/api/v1/manage/wallets` | Create wallet |
| PUT | `/api/v1/manage/wallets/{id}` | Update wallet (freeze/unfreeze) |
| DELETE | `/api/v1/manage/wallets/{id}` | Delete wallet |
| GET | `/api/v1/manage/transactions` | List transactions |
| POST | `/api/v1/manage/transfer` | Execute wallet-to-wallet transfer |

---

## 7. Optimistic Locking Contract

Wallets use `#[ORM\Version]` for optimistic concurrency control:

- On concurrent updates, Doctrine throws `OptimisticLockException`
- The caller should retry or report the conflict
- This protects against race conditions on balance updates outside TransferService

---

## 8. Money Handling Contract

Same contract as Trade module:

| Aspect | Rule |
|--------|------|
| Storage | `bigint` (cents) |
| PHP type | `int` |
| API boundary | Decimal (string/number) |
| Transfer amount | Integer cents (not decimal) |

---

## 9. Database Migration

**Version**: `Version20250517000000`

Creates `wallet` and `wallet_transaction` tables.

---

## 10. Testing

| Suite | Tests |
|-------|-------|
| `tests/Wallet/Entity/` | Wallet, WalletTransaction unit tests |
| `tests/Wallet/Integration/` | TransferService (happy path, insufficient funds, frozen, same-wallet, idempotency, deadlock ordering), wallet repository, transaction repository, API regression |
