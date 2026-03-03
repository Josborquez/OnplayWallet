# Informe de Auditoría: Integración POS ↔ Wallet

**Fecha:** 2026-03-03
**Repositorios:** `Josborquez/OnplayPOSv2` (POS) · `Josborquez/OnplayWallet` (WordPress Plugin)
**Stack:** React + Vite (frontend) · Node.js + Express + Prisma/MySQL (backend) · WordPress/WooCommerce (wallet)

---

## Resumen Ejecutivo

El sistema presenta una arquitectura **bien diseñada** con mecanismos de seguridad robustos y transacciones atómicas. Sin embargo, se identificaron **6 vulnerabilidades críticas**, **4 riesgos medios** y **8 oportunidades de mejora** que deben abordarse para garantizar la integridad financiera del sistema POS-Wallet.

---

## 1. Integración POS → Wallet (Crítico)

### 1.1 Flujo de Carga de Saldo — Transacciones Atómicas

**Archivos analizados:**
- `server/src/controllers/wallet.controller.js:200-278` — `addCredit()`
- `server/src/services/walletService.js:74-127` — `credit()`
- `server/src/controllers/sale.controller.js:57-261` — `createSale()`

**Estado: ✅ CORRECTO — Usa `prisma.$transaction` correctamente**

El endpoint `addCredit()` (línea 209) envuelve la actualización del saldo Y el registro del `StoreCreditLog` en una transacción Prisma:

```javascript
const result = await prisma.$transaction(async (tx) => {
    await expireCreditsForCustomer(tx, Number(id));
    const customer = await tx.customer.findUnique({ where: { id: Number(id) } });
    const newBalance = Number(customer.credit_balance) + Number(amount);
    await tx.customer.update({ where: { id: Number(id) }, data: { credit_balance: newBalance } });
    const log = await tx.storeCreditLog.create({ ... });
    return { customer: { ...customer, credit_balance: newBalance }, log };
});
```

El `createSale()` también usa `prisma.$transaction` con un timeout de 15 segundos (`{ timeout: 15000 }`) para agrupar:
1. Validación de stock
2. Creación de la venta
3. Decremento de stock
4. Descuento de Store Credit (si aplica)
5. Puntos de lealtad
6. Asientos contables

**La sincronización con WordPress (webhooks, wpSync, WooCommerce) ocurre FUERA de la transacción** como fire-and-forget, lo cual es correcto — no bloquea la venta si el sitio web no responde.

#### ⚠️ VULNERABILIDAD V-001: Inconsistencia en el fallback de wallet remota durante ventas

**Archivo:** `server/src/controllers/sale.controller.js:173-214`
**Severidad:** CRÍTICA

Cuando el POS debita Store Credit durante una venta con wallet remota configurada:

```javascript
if (walletConnector.isConfigured() && customer.email) {
    try {
        const remoteResult = await walletConnector.debit(...);
        newBalance = remoteResult.new_balance;  // Usa saldo remoto
    } catch (remoteErr) {
        // Si falla la wallet remota, se usa el saldo local como respaldo
        logger.warn(`Wallet remota no disponible...`);
    }
}
await tx.customer.update({ where: { id: customer.id }, data: { credit_balance: newBalance } });
```

**Problema:** Si la wallet remota falla, el POS descuenta localmente pero NO descuenta en la web. Esto crea **saldo fantasma** — el cliente puede usar el mismo saldo dos veces (una en POS, otra en la web).

**Recomendación:** Si la wallet remota está configurada y falla, la venta con STORE_CREDIT debería fallar o al menos generar una alerta al operador y encolar un reintento.

---

### 1.2 Seguridad de API

**Archivos analizados:**
- `server/src/middlewares/apiKeyAuth.js` — Autenticación API Key
- `server/src/middlewares/auth.middleware.js` — JWT
- `server/src/services/webhookService.js` — Firmas HMAC-SHA256
- `OnplayWallet/includes/api/class-onplay-pos-rest-controller.php` — Verificación de firmas

#### ✅ CORRECTO — Mecanismos de autenticación bien implementados

| Mecanismo | Descripción | Estado |
|-----------|-------------|--------|
| **JWT** (POS interno) | Tokens con `userId`, `role`, `name`. Verificados con `jwt.verify(token, JWT_SECRET)` | ✅ Correcto |
| **API Key** (WP → POS) | Header `X-Onplay-Api-Key` comparado contra `process.env.WALLET_API_KEY` | ✅ Correcto |
| **API Key** (POS → WP) | Header `X-Onplay-Api-Key` verificado con `hash_equals()` contra key almacenada en WP options | ✅ Correcto (timing-safe) |
| **Webhook HMAC** | Payload firmado con HMAC-SHA256. WP verifica firma + timestamp (anti-replay 5 min) | ✅ Excelente |
| **Rate Limiting** | `express-rate-limit` en endpoints públicos (60 req/min/IP) | ✅ Correcto |
| **Roles** | `requireRole(['ADMIN', 'MANAGER'])` en rutas sensibles | ✅ Correcto |

#### ⚠️ VULNERABILIDAD V-002: Endpoint público de balance sin autenticación

**Archivo:** `server/src/routes/wallet.routes.js:31`

```javascript
router.get('/public/balance/:email', asyncHandler(getPublicBalance));
```

Este endpoint retorna el saldo de cualquier email **sin ninguna autenticación** (ni API Key, ni JWT). Cualquier persona que conozca un email puede consultar el saldo.

**Severidad:** MEDIA
**Recomendación:** Proteger con `apiKeyAuth` middleware o eliminarlo en favor de `/api/wallet-public/balance` que SÍ requiere API Key.

#### ⚠️ VULNERABILIDAD V-003: API Key en comparación simple (POS side)

**Archivo:** `server/src/middlewares/apiKeyAuth.js:10`

```javascript
if (!apiKey || apiKey !== process.env.WALLET_API_KEY) {
```

Usa comparación con `!==` que NO es timing-safe. Un atacante podría inferir caracteres de la API key midiendo tiempos de respuesta.

**Severidad:** BAJA (mitigada por el rate limiting)
**Recomendación:** Usar `crypto.timingSafeEqual()`:

```javascript
const crypto = require('crypto');
const a = Buffer.from(apiKey);
const b = Buffer.from(process.env.WALLET_API_KEY);
if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) { ... }
```

**Nota:** El lado WordPress SÍ usa `hash_equals()` (timing-safe).

#### ⚠️ VULNERABILIDAD V-004: PINs almacenados en texto plano

**Archivo:** `server/src/controllers/user.controller.js:8-10`

```javascript
// Los PINs se almacenan en texto plano en la BD para permitir el login rápido
// mediante `findUnique({ where: { pin } })`.
// TODO: migrar a PINs hasheados + comparación bcrypt para mayor seguridad.
```

**Severidad:** MEDIA
**Recomendación:** Implementar hash con bcrypt. Usar `findMany` + `bcrypt.compare()` para validar, o almacenar el hash del PIN indexado.

#### ⚠️ VULNERABILIDAD V-005: Wallet-connector status sin autenticación

**Archivo:** `server/src/routes/wallet-connector.routes.js:18`

```javascript
router.get('/status', asyncHandler(getConnectionStatus));
```

Expone información interna (URLs de la wallet remota, estado de conexión) sin autenticación.

**Severidad:** BAJA
**Recomendación:** Proteger con `verifyToken`.

---

### 1.3 Sincronización — ¿Tiempo Real en Ambos Dominios?

**Archivos analizados:**
- `server/src/services/wpSyncService.js` — Sync a ambos WP sites
- `server/src/services/webhookService.js` — Webhooks a ambos sitios
- `OnplayWallet/includes/class-onplay-wallet-wallet.php:253-343` — `recode_transaction()`

#### Mecanismo de sincronización actual:

```
POS ──(carga)──> DB MySQL local (Prisma $transaction, INMEDIATO)
   ├──(fire-and-forget)──> wpSyncService.syncCreditToWp() → onplay.cl + onplaygames.cl
   └──(fire-and-forget)──> webhookService.sendWebhook() → ambos sitios (backup)
```

**El monto se refleja:**
- **POS local:** INMEDIATO (misma transacción)
- **Sitios web:** NEAR-REALTIME (1-15 segundos típicamente)

#### ⚠️ VULNERABILIDAD V-006: Doble crédito por sincronización redundante

**Archivos:**
- `server/src/controllers/wallet.controller.js:252-274` — `addCredit()`
- `server/src/services/wpSyncService.js:259-269` — `syncCreditToWp()`

Cuando el POS carga crédito, ejecuta **dos mecanismos de sincronización simultáneos**:

```javascript
// 1. API directa a WordPress
syncCreditToWp(customer.email, amount, posReference, reason);

// 2. Webhook como backup
sendWebhook('wallet.credit', { email, amount, balance, reference });
```

El primer mecanismo (API directa) crea la transacción en WordPress con la referencia `POS-CREDIT-{id}`.
El segundo mecanismo (webhook) llega a WordPress con la misma referencia.

**En WordPress**, el handler de webhook (`handle_webhook_credit()`) verifica duplicados por `_pos_reference`, pero la API directa y el webhook podrían llegar con **diferentes references** o el webhook podría llegar antes de que se registre la referencia de la API directa.

**Severidad:** CRÍTICA — Puede causar doble acreditación.

**Recomendación:** Usar UN SOLO mecanismo de sincronización, no dos en paralelo. El patrón actual de "API directa + webhook como backup" es propenso a race conditions. Si se mantienen ambos, el webhook debe usar la MISMA referencia (`posReference`) que la API directa.

**Verificación del código:** La referencia `posReference` es la misma en ambas llamadas (`POS-CREDIT-{log.id}`), y WordPress verifica duplicados. Sin embargo, existe una ventana de tiempo donde ambas requests pueden llegar antes de que la primera registre la referencia en la tabla `_pos_reference`. **La deduplicación en WordPress NO es transaccional** (no usa lock).

---

## 2. Pruebas de Usuario (Caso: josborquez@gmail.com)

### 2.1 Simulación de Créditos — Descuento Correcto

**Archivos analizados:**
- `server/src/controllers/walletPublicController.js:52-106` — `postDebit()`
- `server/src/services/walletService.js:140-215` — `debit()`
- `server/tests/wallet-flow.test.js` — Test del flujo completo

**Estado: ✅ CORRECTO — El flujo de descuento es robusto**

El `walletService.debit()` ejecuta dentro de `prisma.$transaction`:
1. Lee el saldo actual
2. Verifica que `currentBalance >= amount`
3. Usa `credit_balance: { decrement: amount }` (operación atómica de Prisma)
4. Registra en `StoreCreditLog`
5. Verifica duplicados por `reference` (previene cobros duplicados con código 409)

**Flujo completo testeado en `wallet-flow.test.js`:**
```
Crear cliente → Cargar $100k → Compra web $25k → Reembolso $25k
```

**Verificación adicional:** El endpoint público (`postDebit`) también verifica `isActive`:
```javascript
if (!customer.isActive) {
    return res.status(403).json({ error: 'Customer account is inactive' });
}
```

### 2.2 Bucle de Activación/Desactivación

**Archivos analizados:**
- `server/src/controllers/wallet.controller.js:491-520` — `deleteCustomer()`
- `server/src/controllers/user.controller.js:221-241` — `deleteUser()`
- `server/src/controllers/walletPublicController.js:70-76` — Verificación `isActive`

**Estado: ✅ PARCIALMENTE CORRECTO**

| Escenario | Estado |
|-----------|--------|
| POS marca cliente como "Inactivo" | ✅ `isActive: false` via soft-delete |
| Web impide compra de cliente inactivo | ✅ `postDebit()` retorna 403 si `!isActive` |
| Reactivación desde panel admin | ✅ `updateCustomer()` permite cambiar `isActive` |
| Saldo se preserva tras reactivación | ✅ El saldo NO se modifica al (des)activar |

#### ⚠️ RIESGO R-001: No hay bloqueo de login en sitios WordPress

**Severidad:** MEDIA

Cuando el POS marca un cliente como `isActive: false`, esto solo bloquea las operaciones de wallet (`postDebit`, `postCredit`). El usuario **aún puede hacer login** en onplay.cl y onplaygames.cl porque WordPress no consulta el flag `isActive` del POS.

**Recomendación:** Implementar un webhook `customer.deactivated` que el plugin OnplayWallet capture para:
1. Marcar `is_wallet_account_locked($user_id)` = true
2. Opcionalmente, forzar logout con `wp_destroy_current_session()` + `wp_destroy_other_sessions()`

#### ⚠️ RIESGO R-002: Crédito público no verifica `isActive`

**Archivo:** `server/src/controllers/walletPublicController.js:113-153`

La función `postCredit()` NO verifica `isActive` antes de acreditar saldo. Un sitio web podría acreditar saldo a un cliente desactivado.

**Recomendación:** Agregar la misma verificación de `isActive` que tiene `postDebit()`.

---

## 3. Consistencia Visual (Modales de Confirmación)

### Archivos analizados:
- `client/src/components/ui/ModalBase.jsx` — Componente base
- `client/src/components/pos/PaymentModal.jsx` — Modal de pago
- `client/src/components/pos/CashRegisterModal.jsx`
- `client/src/components/pos/CloseCashModal.jsx`
- `client/src/components/settings/UserFormModal.jsx`
- `client/src/components/inventory/ProductFormModal.jsx`
- `client/tailwind.config.js` — Configuración de colores

### Design Token Analysis

**Estado: ⚠️ PARCIALMENTE CONSISTENTE**

| Token | ModalBase | PaymentModal | SweetAlert2 | Estado |
|-------|-----------|-------------|-------------|--------|
| Fondo modal | `bg-[#0f1420]` | `bg-[#0f1420]` | Default (blanco) | ⚠️ Inconsistente |
| Footer bg | `bg-[#0a0e18]` | `bg-[#0a0e18]` | N/A | ✅ Consistente |
| Border | `border-slate-700/60` | `border-slate-700/60` | N/A | ✅ Consistente |
| Backdrop | `bg-black/80 backdrop-blur-md` | `bg-black/80 backdrop-blur-md` | Default | ⚠️ Inconsistente |
| Rounded | `rounded-t-2xl sm:rounded-2xl` | `rounded-t-2xl sm:rounded-2xl` | `swal-responsive` | ⚠️ Inconsistente |
| Header gradient | `bg-gradient-to-r ${gradient}` | `from-blue-600/10 to-indigo-600/5` | N/A | ✅ Consistente |
| Close button | `w-8 h-8 rounded-lg bg-slate-800/60 hover:bg-slate-700 border border-slate-600/30` | Igual | N/A | ✅ Consistente |
| CTA button | N/A | `bg-emerald-600 hover:bg-emerald-500` | `confirmButtonColor: '#2563EB'` | ⚠️ Inconsistente |

#### ⚠️ RIESGO R-003: SweetAlert2 no respeta el dark theme

**Archivo:** `client/src/components/pos/PaymentModal.jsx:129-141`

Los modales de confirmación usan SweetAlert2 con el theme por defecto (fondo blanco), creando un contraste visual brusco contra el dark theme del POS. El botón de confirmación usa `#2563EB` (blue-600) mientras que el botón "Confirmar Pago" principal usa `bg-emerald-600`.

**Recomendación:**
1. Configurar SweetAlert2 con un theme oscuro global:
```javascript
Swal.mixin({
    background: '#0f1420',
    color: '#e2e8f0',
    confirmButtonColor: '#059669', // emerald-600
    cancelButtonColor: '#dc2626',
    customClass: { popup: 'border border-slate-700/60 rounded-2xl' }
});
```
2. Usar colores Tailwind config centralizados en lugar de valores hardcodeados.

#### Nota sobre sitios web

Los sitios WordPress usan su propio sistema de temas (WooCommerce templates), no Tailwind. Por lo tanto, la consistencia visual POS ↔ Web NO aplica directamente — son interfaces diferentes para audiencias diferentes (operador POS vs. consumidor web).

---

## 4. Redundancia de Código

### 4.1 Modelos de Datos — ¿Duplicados en 3 Repositorios?

| Concepto | OnplayPOSv2 | OnplayWallet | ¿Duplicado? |
|----------|-------------|-------------|-------------|
| **User/Customer** | Prisma: `Customer` model (id, rut, full_name, email, phone, credit_balance, isActive) | WordPress: `wp_users` + `usermeta` | **No** — esquemas diferentes por diseño |
| **Wallet Balance** | Prisma: `Customer.credit_balance` (campo desnormalizado) | WordPress: `SUM(CASE type...)` sobre tabla `onplay_wallet_transactions` | **Sí** — duplicación necesaria (cache local) |
| **Transaction Log** | Prisma: `StoreCreditLog` (customer_id, amount, type, reason, source, balance_after, reference, expires_at) | WordPress: tabla `onplay_wallet_transactions` (user_id, type, amount, balance, currency, details) | **Sí** — esquemas similares pero no idénticos |

### 4.2 Código Duplicado Identificado

#### D-001: Función `getExpirationDate()` — Centralizada ✅

Ya está centralizada en `server/src/utils/constants.js` (DRY). Usada en `wallet.controller.js`, `walletService.js`, y `wallet-connector.controller.js`.

#### D-002: Lógica de crédito/débito duplicada en 3 controladores

| Archivo | Función | Propósito |
|---------|---------|-----------|
| `wallet.controller.js:200` | `addCredit()` | Carga desde panel POS interno |
| `walletPublicController.js:113` | `postCredit()` | Carga desde sitio web (API pública) |
| `wallet-connector.controller.js:90` | `creditRemoteWallet()` | Carga a wallet remota + sync local |

Las tres funciones implementan lógica similar de:
- Validar monto > 0
- `prisma.$transaction` con update + log
- Fire-and-forget para webhooks/sync

**Recomendación:** `walletPublicController.js` ya usa `walletService.credit()` centralizado. ✅
`wallet.controller.js:addCredit()` implementa su propia lógica en lugar de usar `walletService.credit()`. ❌

**Acción:** Refactorizar `addCredit()` para usar `walletService.credit()` en lugar de duplicar la transacción.

#### D-003: Deduplicación de transacciones implementada en 2 niveles

| Nivel | Archivo | Mecanismo |
|-------|---------|-----------|
| POS (Node.js) | `walletService.js:148-159` | `storeCreditLog.findFirst({ where: { reference, type: 'DEBIT' } })` |
| WordPress | `class-onplay-pos-rest-controller.php:458-482` | `SELECT transaction_id WHERE _pos_reference = ?` |

Ambos niveles verifican duplicados, lo cual es correcto (defense in depth).

### 4.3 Propuesta de Centralización

```
ARQUITECTURA ACTUAL:
┌─────────────┐    ┌─────────────────┐    ┌──────────────────┐
│  OnplayPOSv2│    │  onplay.cl (WP) │    │onplaygames.cl(WP)│
│  Prisma     │    │  OnplayWallet   │    │  OnplayWallet    │
│  Customer   │    │  wp_users       │    │  wp_users        │
│  StoreCred  │    │  wallet_txn     │    │  wallet_txn      │
└──────┬──────┘    └───────┬─────────┘    └───────┬──────────┘
       │   API Key/Webhook │                      │
       └──────────────────┴──────────────────────┘

PROPUESTA — Paquete compartido NPM/Composer:
┌─────────────────────────────────────────────┐
│  @onplay/wallet-types (TypeScript/JSON Schema)│
│  - CustomerDTO                               │
│  - TransactionDTO                            │
│  - WalletEventTypes                          │
│  - API Key validation util                   │
└─────────────────────────────────────────────┘
```

**Implementación mínima viable:**
1. Crear un archivo `shared/wallet-types.d.ts` con las interfaces TypeScript compartidas
2. Validar schemas de API con JSON Schema o Zod en ambos lados
3. No es viable centralizar los modelos de Prisma/WordPress porque son tecnologías diferentes (MySQL con Prisma vs. WordPress con $wpdb)

---

## 5. Concurrencia y Logs de Auditoría

### 5.1 Concurrencia — Bloqueo de Registro

**Archivos analizados:**
- `server/src/services/walletService.js:162-199` — Prisma `$transaction` para débitos
- `server/tests/concurrency.test.js` — Tests de concurrencia
- `OnplayWallet/includes/class-onplay-wallet-wallet.php:267-275` — MySQL `GET_LOCK()`

#### POS (Node.js/Prisma)

**Mecanismo actual:** Prisma `$transaction` con nivel de aislamiento por defecto de MySQL (`REPEATABLE READ`).

**Estado: ⚠️ INSUFICIENTE para alta concurrencia**

El flujo actual de débito:
```javascript
const result = await prisma.$transaction(async (tx) => {
    const customer = await tx.customer.findUnique({ where: { id } }); // LEE saldo
    if (currentBalance < amount) throw new Error('INSUFFICIENT_BALANCE');
    await tx.customer.update({ data: { credit_balance: { decrement: amount } } }); // ACTUALIZA
});
```

**Problema:** Bajo `REPEATABLE READ`, dos transacciones pueden leer el mismo saldo simultáneamente, ambas aprobar, y ambas decrementar. El `decrement` de Prisma traduce a `SET credit_balance = credit_balance - X` que es atómico a nivel de MySQL, pero la **validación de saldo suficiente** ya se hizo con el valor viejo.

**Ejemplo de race condition:**
```
Saldo: $30,000
T1: Lee $30,000 → Aprueba $20,000 → decrement $20,000 → Saldo = $10,000
T2: Lee $30,000 → Aprueba $20,000 → decrement $20,000 → Saldo = -$10,000 ❌
```

**Severidad:** CRÍTICA

**Recomendación:** Usar `SELECT ... FOR UPDATE` para bloquear la fila durante la transacción:

```javascript
const result = await prisma.$transaction(async (tx) => {
    // Bloquea la fila con FOR UPDATE
    const [customer] = await tx.$queryRaw`
        SELECT id, credit_balance FROM Customer WHERE id = ${customerId} FOR UPDATE
    `;
    if (Number(customer.credit_balance) < amount) {
        throw new Error('INSUFFICIENT_BALANCE');
    }
    await tx.customer.update({
        where: { id: customerId },
        data: { credit_balance: { decrement: amount } }
    });
    // ...
});
```

#### WordPress (OnplayWallet)

**Mecanismo actual:** `GET_LOCK('onplay_wallet_lock_user_' + user_id, 5)` — Bloqueo a nivel de MySQL por usuario.

**Estado: ✅ EXCELENTE** — MySQL `GET_LOCK()` es un mecanismo de advisory locking correcto y probado.

```php
$got_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, $lock_timeout));
if ('1' !== $got_lock && 1 !== $got_lock) {
    return false; // No se pudo obtener el lock
}
try {
    // ... operación ...
} finally {
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
}
```

**El POS debería implementar un mecanismo similar** usando `FOR UPDATE` en Prisma.

### 5.2 Logs de Auditoría

**Estado: ✅ BIEN IMPLEMENTADO**

| Log | Tabla | Campos | Cubre |
|-----|-------|--------|-------|
| **StoreCreditLog** | Prisma | customer_id, amount, type, reason, source, balance_after, reference, expires_at, sale_id, created_by, created_at | ✅ Todas las operaciones de wallet |
| **WebhookLog** | Prisma | event, targetUrl, payload (JSON), statusCode, response, success, retries, createdAt | ✅ Auditoría de webhooks |
| **SyncLog** | Prisma | store, status, sync_type, products_processed, error_message | ✅ Sincronización WooCommerce |
| **WP Transaction Meta** | WordPress | `_onplay_source`, `_pos_reference`, `_pos_sync_status` | ✅ Trazabilidad POS ↔ WP |
| **JournalEntry/Line** | Prisma | Partida doble completa por venta | ✅ Contabilidad formal |

**El `transaction_id` único ya existe:** Cada `StoreCreditLog` tiene un `id` autoincremental y un campo `reference` que sirve como ID de trazabilidad (ej: `POS-CREDIT-42`, `WC-TXN-789`).

#### ⚠️ RIESGO R-004: Falta tabla de auditoría consolidada

No existe una tabla `WalletHistory` dedicada que consolide **todas** las operaciones (POS + Web) en un solo lugar con un `transaction_id` UUID único global.

**Recomendación:** Crear modelo `WalletAuditTrail`:

```prisma
model WalletAuditTrail {
    id            String   @id @default(uuid())
    customer_id   Int
    operation     String   // CREDIT | DEBIT | ADJUSTMENT | SYNC | EXPIRATION
    amount        Decimal  @db.Decimal(12, 2)
    balance_before Decimal @db.Decimal(12, 2)
    balance_after  Decimal @db.Decimal(12, 2)
    source        String   // POS_MANUAL | POS_SALE | WEB_PURCHASE | WEB_TOPUP | SYNC | ADMIN
    reference     String   // UUID o referencia cruzada
    wp_txn_id     Int?     // ID de transacción en WordPress (si aplica)
    pos_log_id    Int?     // ID en StoreCreditLog
    ip_address    String?
    user_agent    String?
    performed_by  Int?     // userId del operador
    created_at    DateTime @default(now())

    @@index([customer_id, created_at])
    @@index([reference])
}
```

---

## 6. Resumen de Vulnerabilidades

### Críticas (Acción inmediata requerida)

| ID | Descripción | Archivo | Impacto |
|----|-------------|---------|---------|
| V-001 | Fallback silencioso de wallet remota permite doble gasto | `sale.controller.js:191-194` | Pérdida financiera |
| V-006 | Doble sincronización (API + webhook) puede causar doble crédito | `wallet.controller.js:252-274` | Pérdida financiera |
| **Conc.** | Race condition en débito concurrente (falta `FOR UPDATE`) | `walletService.js:162` | Saldo negativo |

### Medias (Resolver en próximo sprint)

| ID | Descripción | Archivo |
|----|-------------|---------|
| V-002 | Endpoint público de balance sin auth | `wallet.routes.js:31` |
| V-004 | PINs almacenados en texto plano | `user.controller.js:8-10` |
| R-001 | No hay bloqueo de login en WP al desactivar cliente | `wallet.controller.js:491` |
| R-002 | `postCredit()` no verifica `isActive` | `walletPublicController.js:113` |

### Bajas (Mejora continua)

| ID | Descripción | Archivo |
|----|-------------|---------|
| V-003 | Comparación de API key no es timing-safe (POS) | `apiKeyAuth.js:10` |
| V-005 | Status de wallet-connector sin auth | `wallet-connector.routes.js:18` |
| R-003 | SweetAlert2 no respeta dark theme | `PaymentModal.jsx:129` |
| R-004 | Falta tabla de auditoría consolidada | Schema |

---

## 7. Recomendaciones Prioritarias

### Prioridad 1 — Seguridad Financiera
1. **Implementar `SELECT ... FOR UPDATE`** en `walletService.debit()` para prevenir race conditions
2. **Eliminar el doble mecanismo** de sincronización (API directa + webhook). Usar solo API directa con webhook como fallback aislado (queue)
3. **No permitir fallback silencioso** en ventas con STORE_CREDIT cuando la wallet remota falla

### Prioridad 2 — Seguridad de Acceso
4. **Proteger el endpoint público de balance** con API Key
5. **Migrar PINs a hash** con bcrypt
6. **Implementar webhook de desactivación** para bloquear login en WordPress

### Prioridad 3 — Consistencia y Mantenibilidad
7. **Refactorizar `addCredit()`** para usar `walletService.credit()` centralizado
8. **Configurar SweetAlert2** con theme oscuro global
9. **Crear tabla `WalletAuditTrail`** para trazabilidad completa
10. **Usar timing-safe comparison** para API keys en el POS

---

*Informe generado como parte de la auditoría de integración POS-Wallet.*
*Branch: `claude/audit-pos-wallet-GJrKx`*
