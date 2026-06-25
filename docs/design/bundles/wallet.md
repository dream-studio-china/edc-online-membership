# Wallet Bundle Design

> The Wallet bundle (`src/Wallet/`) provides user wallets, transactions, atomic
> wallet-to-wallet transfers with deadlock prevention and idempotency,
> **system deposits**, **balance verification**, and **reconciliation**.

---

## 1. Overview

Wallet is a financial module for managing user balances:

- **Wallets** per user per currency with balance in cents, optimistic locking, and freeze capability
- **WalletTransactions** with UUID, type classification, status tracking, and idempotency via `referenceId`
- **TransferService** with atomic from-wallet-to-wallet transfers, deadlock prevention, and rollback recovery
- **Deposit** endpoint for system-injected funding with audit trail
- **Balance verification** — `GET /api/v1/manage/wallets/balance` checks invariant: `SUM(wallets) == SUM(deposits + adjustments)`
- **Reconciliation** — `POST /api/v1/manage/wallets/reconcile` fixes per-wallet gaps

### 1.1 Accounting Model

```
deposit (TYPE_DEPOSIT)     → injects money from system (one-sided credit)
adjustment (TYPE_ADJUSTMENT) → reconciliation fix (one-sided credit)
transfer (TYPE_TRANSFER)   → zero-sum between wallets (debit + credit)
```

**Invariant**: `SUM(all wallet balances) == SUM(all deposit + adjustment transactions)` at all times.

### 1.2 Entities

| Entity | Table | Purpose |
|--------|-------|---------|
| `Wallet` | `wallet` | User balance per currency (cents), freeze support, optimistic locking |
| `WalletTransaction` | `wallet_transaction` | Record of deposit/withdrawal/transfer/fee/refund/**adjustment** |

---

## 2. File Structure

```
src/Wallet/
|-- Controller/Manage/
|   |-- TransactionController.php        # CRUD for transactions
|   |-- TransferController.php           # Transfer + Deposit endpoints
|   |-- WalletController.php             # CRUD + Balance + Reconcile
|-- Entity/
|   |-- Wallet.php
|   |-- WalletTransaction.php
|-- Exception/
|   |-- InsufficientFundsException.php
|   |-- SameWalletTransferException.php
|   |-- WalletFrozenException.php
|-- Repository/
|   |-- WalletRepository.php             # + getTotalBalance()
|   |-- WalletTransactionRepository.php  # + getTotalDeposited(), getExpectedBalance()
|-- Service/
    |-- TransactionService.php
    |-- TransferResult.php               # Transfer result DTO
    |-- TransferService.php              # Core transfer + deposit logic
    |-- TransferServiceInterface.php     # Transfer + deposit contract
    |-- WalletService.php                # + verifyBalance(), reconcile()
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

**⚠️ No `setBalance()`** — Wallet balance can ONLY be altered through `TransferService` (transfer, deposit, reconcile). This prevents direct mutation that would bypass the audit trail.

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
| `type` | string | `deposit`, `withdrawal`, `transfer`, `fee`, `refund`, **`adjustment`** |
| `status` | string | `pending`, `completed`, `failed`, `reversed` |
| `referenceId` | string (unique) | Idempotency key |
| `description` | string | Human-readable note |
| `metadata` | JSON | Extensible data |

**Unique constraint**: `referenceId` -- prevents duplicate transactions.

**Methods**: `markCompleted()`, `markFailed()`

---

## 4. TransferService — Atomic Transfer + Deposit

### 4.1 Contract

```php
interface TransferServiceInterface
{
    public function transfer(int $fromWalletId, int $toWalletId, int $amount,
        ?string $referenceId = null, ?string $description = null): TransferResult;

    public function deposit(int $toWalletId, int $amount,
        ?string $referenceId = null, ?string $description = null): TransferResult;
}
```

### 4.2 deposit() — System Funding

```
deposit(toWalletId, amount, referenceId, description)
  |
  v
1. Idempotency Check (via referenceId)
  |
  v
2. Lock target wallet (SELECT ... FOR UPDATE)
  |
  v
3. Validation
   -> amount > 0
   -> wallet exists
   -> wallet not frozen
  |
  v
4. Execute (within DB transaction)
   -> beginTransaction()
   -> DQL UPDATE: toWallet.balance += amount
   -> Create WalletTransaction (type=deposit, fromWallet=null)
   -> commit()
   |
   -> On failure: rollback(), EM recovery
  |
  v
5. Return TransferResult (fromWalletBalance=0)
```

### 4.3 Transfer Algorithm

```
transfer(fromWalletId, toWalletId, amount, referenceId, description)
  |
  v
1. Idempotency Check
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
   -> fromWallet exists, toWallet exists
   -> fromWallet->isFrozen() || toWallet->isFrozen() (WalletFrozenException)
   -> fromWallet->getBalance() >= amount (InsufficientFundsException)
   -> Currency match: fromWallet->getCurrency() == toWallet->getCurrency()
  |
  v
4. Execute (within DB transaction)
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
5. Return TransferResult
   -> Transaction entity + post-transfer balances
```

### 4.4 TransferResult DTO

```php
class TransferResult
{
    public WalletTransaction $transaction;
    public int $fromWalletBalanceAfter;  // 0 for deposits
    public int $toWalletBalanceAfter;    // Post-operation
}
```

---

## 5. WalletService — Balance Verification + Reconciliation

### 5.1 verifyBalance()

Checks the accounting invariant:
```
SUM(all wallet balances) == SUM(all deposit + adjustment transactions)
```

Returns `{totalBalance, totalDeposited, discrepancy, matches, walletCount}`.

### 5.2 reconcile()

Per-wallet reconciliation:

1. For each wallet, compute `expected = SUM(credits) - SUM(debits)` from transaction history
2. Compare actual balance against expected
3. If `actual > expected`: create `TYPE_ADJUSTMENT` deposit (acknowledge legacy balance)
4. If `actual < expected`: report as `skipped_negative` (requires manual review)
5. **Does NOT touch wallet balances** — only creates adjustment transaction records
6. **Idempotent** — re-running when books are balanced produces 0 adjustments

Returns `{reconciled, adjustments[]}`.

---

## 6. Exception Design

| Exception | When Thrown |
|-----------|-------------|
| `InsufficientFundsException` | Source wallet balance < transfer amount |
| `WalletFrozenException` | Either wallet status is `frozen` |
| `SameWalletTransferException` | fromWalletId == toWalletId |

All exceptions extend `\RuntimeException`.

---

## 7. API Endpoints

### 7.1 Manage (Admin, ROLE_ADMIN)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/manage/wallets` | List wallets |
| GET | `/api/v1/manage/wallets/{id}` | Wallet detail |
| POST | `/api/v1/manage/wallets` | Create wallet (balance always starts at 0) |
| PUT | `/api/v1/manage/wallets/{id}` | Update wallet (freeze/unfreeze) |
| DELETE | `/api/v1/manage/wallets/{id}` | Delete wallet |
| **GET** | **`/api/v1/manage/wallets/balance`** | **Verify accounting invariant** |
| **POST** | **`/api/v1/manage/wallets/reconcile`** | **Per-wallet reconciliation** |
| GET | `/api/v1/manage/transactions` | List transactions |
| POST | `/api/v1/manage/transfers` | Execute wallet-to-wallet transfer |
| **POST** | **`/api/v1/manage/transfers/deposit`** | **System deposit with audit trail** |

---

## 8. Optimistic Locking Contract

Wallets use `#[ORM\Version]` for optimistic concurrency control:

- On concurrent updates, Doctrine throws `OptimisticLockException`
- The caller should retry or report the conflict
- This protects against race conditions on balance updates outside TransferService

---

## 9. Money Handling Contract

Same contract as Trade module:

| Aspect | Rule |
|--------|------|
| Storage | `bigint` (cents) |
| PHP type | `int` |
| API boundary | Decimal (string/number) |
| Transfer amount | Integer cents (not decimal) |

---

## 10. Database Migration

**Version**: `Version20250517000000`

Creates `wallet` and `wallet_transaction` tables.

---

## 11. Testing

| Suite | Tests |
|-------|-------|
| `tests/Wallet/Entity/` | Wallet, WalletTransaction unit tests |
| `tests/Wallet/Service/WalletServiceTest.php` | **11 unit tests**: verifyBalance (match/mismatch/zero), reconcile (empty/balanced/excess/negative/idempotent/multi/skip-non-wallet/skip-no-id) |
| `tests/Wallet/Service/TransferServiceTest.php` | **20 unit tests**: deposit (happy/wallet-not-found/frozen/idempotent/rollback/em-closed), transfer (happy/same-wallet/source-not-found/target-not-found/frozen/currency/insufficient/idempotent/deadlock/rollback/em-closed) |
| `tests/Wallet/Integration/` | TransferService, wallet repository, transaction repository, API regression |
