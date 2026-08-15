# Naming Convention（命名契约）

> 平台级命名规则，防止模块前缀与类名再度混乱。所有模块、实体、服务、控制器都必须遵守。

---

## 1. 核心原则

**命名空间承载模块，类名承载领域概念。** 类名一般不重复模块前缀——模块归属由
`App\<Module>\` 命名空间表达，类名只表达"这是什么领域概念"。

```php
// 命名空间已说明模块归属
namespace App\Trade\Entity;   class Order { ... }
namespace App\Store\Entity;   class StoreOrder { ... }   // 前缀仅为消歧，见 §3
```

---

## 2. 四条规则

| # | 规则 |
|---|------|
| N1 | **类名 = 领域概念**（裸名优先），模块归属由命名空间表达 |
| N2 | **加模块前缀仅两种情形**：(a) 跨模块重名/歧义；(b) 模块内基础设施（outbox / registry / gateway / provider 等，前缀辅助发现） |
| N3 | **Service / Controller / Repository / Event / Exception 严格镜像实体名**：`{Entity}Service`、`{Entity}Controller`、`{Entity}Repository`。前缀决定在实体上，其余全部跟实体走 |
| N4 | **同一模块内前缀必须一致**：要么全前缀，要么全裸名，不得半前缀半裸名 |

---

## 3. 前缀判定表

| 情形 | 做法 | 例子 |
|---|---|---|
| 领域概念在平台内唯一 | **裸名** | `Order`、`Invoice`、`Product`、`User`、`Material`、`Media`、`Category` |
| 跨模块同名 / 歧义 | **加模块前缀** | `StoreOrder`（vs Trade `Order`）、`WechatUser`（vs Identity `User`）、`TradeOutboxMessage` / `StoreOutboxMessage` / `InventoryOutboxMessage`、`StoreConsumedEvent` / `InventoryConsumedEvent` |
| 模块内基础设施 | 职责名 + 必要前缀 | `PaymentGatewayRegistry`、`ReservationRequestedMessage`、`DepositService` |
| 实体名 = 模块名 | 保持原名 | `Wallet`、`Store`、`Promotion` |

### 3.1 跨模块冲突组（保留前缀，不可去掉）

| 概念 | 保留类名 |
|---|---|
| Order | `Trade\Order`、`Store\StoreOrder` |
| User | `Identity\User`、`Wechat\WechatUser` |
| OutboxMessage | `Trade\TradeOutboxMessage`、`Store\StoreOutboxMessage`、`Inventory\InventoryOutboxMessage` |
| ConsumedEvent | `Store\StoreConsumedEvent`、`Inventory\InventoryConsumedEvent` |

> 这些组即使命名空间不同，若去前缀，任何同时引用两个模块的代码都要 `use X as Y` 别名，脆弱且到处要写。

---

## 4. 新增类的命名检查清单

新增一个类时，按顺序自问：

1. **它是实体吗？** → 命名领域概念；查 §3.1 冲突表决定是否加前缀。
2. **它是实体的 Service / Controller / Repository / Event / Exception 吗？** → **严格镜像实体名**（N3），不要自创前缀。
3. **它是模块内基础设施吗？** → 职责名；仅在会歧义或需要发现辅助时加模块前缀。
4. **它在既有模块里是否与邻类一致？** → 违反 N4（同模块前缀不一致）即视为命名违约。

**反例（禁止）**：
- 实体 `Transaction`，服务却叫 `WalletTransactionService`（N3：服务必须镜像实体名，前缀不能自创）
- 同模块内既有 `Stock` 又有 `InventoryStock`（N4：同模块前缀必须一致）
- 用 `Service` 后缀给非服务类（如 `StoreContextResolver` 保留原名，不加 `Service`）

---

## 5. 变更记录

- **2026-08-15 · 最小前缀重构**：全平台去除非必要模块前缀——
  `WalletTransaction→Transaction`、`WalletVoucher→Voucher`、`WalletPaymentDeduction→PaymentDeduction`、
  `WalletDepositService→DepositService`、`WalletReconciliationService→ReconciliationService`、
  `InventoryStock→Stock`、`InventoryReservation→Reservation`、`InventoryLedgerEntry→LedgerEntry`、
  `StoreMembership→Membership`。
  **表名 / URL / 路由名保持不变，零迁移、零 API 破坏。** 4 组跨模块冲突保留前缀（§3.1）。
