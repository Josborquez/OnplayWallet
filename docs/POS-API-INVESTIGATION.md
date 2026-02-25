# OnplayPOS API Investigation

## Date: 2026-02-25

---

## 1. Context

OnplayWallet is a WordPress/WooCommerce plugin that provides a digital wallet system integrated with **OnplayPOS** — a separate Point of Sale system.

### Infrastructure Overview

| Component | URL | Platform | Hosting |
|-----------|-----|----------|---------|
| OnplayPOS | https://onplaypos.onplaygames.cl/ | React SPA | Hostinger |
| Onplay Store | https://onplay.cl/ | WordPress/WooCommerce | — |
| OnplayGames Store | https://onplaygames.cl/ | WordPress/WooCommerce | — |

### Business Flow

```
┌──────────────┐       Credits loaded       ┌─────────────────────┐
│  OnplayPOS   │ ─────────────────────────→  │  Customer Wallet    │
│  (React App) │                             │  (Shared Balance)   │
└──────────────┘                             └─────────┬───────────┘
                                                       │
                                          Balance used for purchases
                                                       │
                                          ┌────────────┴────────────┐
                                          │                         │
                                    ┌─────▼──────┐          ┌──────▼───────┐
                                    │ onplay.cl  │          │onplaygames.cl│
                                    │ WooCommerce│          │ WooCommerce  │
                                    └────────────┘          └──────────────┘
```

1. **Credits are loaded at the POS** — store operators use OnplayPOS to add credit to customer accounts
2. **Customers purchase on either WooCommerce site** — using their wallet balance from their account on onplay.cl or onplaygames.cl
3. **Balance is deducted** from the wallet when purchases are made

---

## 2. POS API Investigation Findings

### 2.1. OnplayPOS URL: `https://onplaypos.onplaygames.cl/`

- **Returns HTTP 403** on direct access (root URL, `/manifest.json`, `/robots.txt`)
- This confirms there IS an active server rejecting unauthorized requests
- The 403 suggests Hostinger-level protection, Cloudflare, or application-level auth

### 2.2. API Key / API Secret

**The owner does NOT have API Key or API Secret for OnplayPOS.**

This is critical because the current `OnplayPOS_Connector` class (`class-onplay-pos-connector.php`) was designed to make outbound HTTP calls to the POS using HMAC-SHA256 authentication with:
- `X-API-Key` header
- `X-Timestamp` header
- `X-Signature` header (HMAC-SHA256 of `api_key + timestamp`)

Without these credentials, **the outbound connector cannot authenticate with the POS**.

### 2.3. OnplayPOS Architecture

OnplayPOS is a **React Single Page Application (SPA)** deployed on Hostinger. This means:

- It is a frontend application — the actual data management happens via a backend (likely a Node.js API, Firebase, Supabase, or similar)
- The backend API endpoints, authentication scheme, and data model are **not publicly documented or accessible** to us
- We cannot reverse-engineer the POS API without access to its source code or documentation

### 2.4. Authentication Available

The owner has credentials for:
- **onplay.cl** — WordPress/WooCommerce admin
- **onplaygames.cl** — WordPress/WooCommerce admin

They do NOT have:
- OnplayPOS API credentials
- OnplayPOS backend access
- OnplayPOS source code (it's a separate React app)

---

## 3. Architectural Analysis

### 3.1. Current Design vs Reality

The plugin currently has **two integration directions**:

| Direction | Component | Status |
|-----------|-----------|--------|
| **WC → POS** (Outbound) | `OnplayPOS_Connector` | **BLOCKED** — No API credentials |
| **POS → WC** (Inbound) | `OnplayPOS_REST_Controller` | **READY** — Fully functional |

#### Outbound (WC → POS) — `class-onplay-pos-connector.php`
Calls FROM WordPress TO the POS. Currently assumes endpoints like:
- `api/ping`
- `api/wallet/balance`
- `api/wallet/credit`
- `api/wallet/debit`
- `api/wallet/transactions`
- `api/wallet/qr-validate`
- `api/wallet/qr-pay`
- `api/customers/register`

**Status: Cannot function** without POS API Key/Secret.

#### Inbound (POS → WC) — `class-onplay-pos-rest-controller.php`
Receives calls FROM the POS TO WordPress. Exposes these endpoints:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/wp-json/onplay/v1/pos/balance` | GET | Get customer wallet balance |
| `/wp-json/onplay/v1/pos/credit` | POST | Credit customer wallet (POS loads credit) |
| `/wp-json/onplay/v1/pos/debit` | POST | Debit customer wallet (POS sale) |
| `/wp-json/onplay/v1/pos/transactions` | GET | Fetch transaction history |
| `/wp-json/onplay/v1/pos/qr-pay` | POST | Process QR code payment |
| `/wp-json/onplay/v1/pos/customer` | GET | Lookup customer |
| `/wp-json/onplay/v1/pos/webhook` | POST | Receive POS webhook events |
| `/wp-json/onplay/v1/pos/status` | GET | Health check |

**Status: Fully functional.** Authentication via `X-Onplay-Api-Key` header (configured in OnplayWallet settings).

### 3.2. Recommended Architecture

Given that we DO control the WordPress side but NOT the POS side, the **POS → WC direction is the viable integration path**.

```
┌──────────────────┐                      ┌──────────────────────────┐
│   OnplayPOS      │  HTTP calls to WP    │  WordPress/WooCommerce   │
│   (React App)    │ ───────────────────→  │  REST API                │
│                  │                       │  /wp-json/onplay/v1/pos/ │
│  Needs to be     │  ←───────────────────  │                          │
│  configured to   │  JSON responses       │  OnplayWallet Plugin     │
│  call WC API     │                       │                          │
└──────────────────┘                      └──────────────────────────┘
```

**The POS needs to be configured to:**
1. Call the WordPress REST API to credit/debit wallets
2. Use the `X-Onplay-Api-Key` header for authentication
3. The API key is generated/configured in OnplayWallet settings

---

## 4. What Needs to Happen

### 4.1. Immediate Actions (OnplayWallet Plugin Side)

1. **The inbound REST API is already built and ready** — no code changes needed for basic functionality
2. **Generate an API key** in OnplayWallet Settings → OnplayPOS tab
3. **The plugin settings should clarify** that:
   - `POS API URL` = only needed if WC needs to call OUT to POS (currently blocked)
   - `POS API Key` = the key that OnplayPOS will use to authenticate WITH the WordPress API
   - The current field labels may be confusing (they suggest the WC site calls the POS)

### 4.2. OnplayPOS Side (Requires POS Developer)

The OnplayPOS React application needs to be configured/modified to:

1. **When loading credits at POS:**
   ```
   POST https://onplay.cl/wp-json/onplay/v1/pos/credit
   Headers: { "X-Onplay-Api-Key": "<key-from-wallet-settings>" }
   Body: { "email": "customer@email.com", "amount": 10000, "reference": "POS-001" }
   ```

2. **When checking customer balance:**
   ```
   GET https://onplay.cl/wp-json/onplay/v1/pos/balance?email=customer@email.com
   Headers: { "X-Onplay-Api-Key": "<key-from-wallet-settings>" }
   ```

3. **When processing a POS sale (debit):**
   ```
   POST https://onplay.cl/wp-json/onplay/v1/pos/debit
   Headers: { "X-Onplay-Api-Key": "<key-from-wallet-settings>" }
   Body: { "email": "customer@email.com", "amount": 5000, "reference": "SALE-001" }
   ```

4. **For QR payments:**
   ```
   POST https://onplay.cl/wp-json/onplay/v1/pos/qr-pay
   Headers: { "X-Onplay-Api-Key": "<key-from-wallet-settings>" }
   Body: { "qr_data": "<scanned-qr-json>", "amount": 5000, "terminal": "POS-1" }
   ```

### 4.3. Multi-site Consideration

Since credits should work across **both** onplay.cl and onplaygames.cl:

**Option A: Shared Database**
- Both WordPress sites share the same database or use WordPress Multisite
- The wallet balance is inherently shared

**Option B: Single Source of Truth**
- One site (e.g., onplay.cl) is the "wallet master"
- The other site calls the master's API for balance checks and debits
- OnplayPOS only needs to connect to the master site

**Option C: Synchronized Wallets**
- Each site maintains its own wallet
- A sync mechanism keeps balances in sync
- More complex, higher risk of inconsistency

**Recommendation:** Option B is the simplest and most reliable.

---

## 5. Summary of Open Questions

| # | Question | Who Can Answer |
|---|----------|---------------|
| 1 | Does OnplayPOS have a backend API that accepts external calls? | POS Developer |
| 2 | What backend does OnplayPOS use (Node.js, Firebase, etc.)? | POS Developer |
| 3 | Can OnplayPOS be configured to call WordPress REST API? | POS Developer |
| 4 | Are onplay.cl and onplaygames.cl on the same database? | Site Owner |
| 5 | Which site should be the "wallet master"? | Site Owner |
| 6 | Is there a POS developer who can modify the React app? | Site Owner |

---

## 6. Next Steps

### Priority 1: Establish Communication Direction
- [ ] Confirm with POS developer if POS can make HTTP calls to WordPress REST API
- [ ] If yes, provide the API documentation (endpoints listed in section 4.2)
- [ ] Generate and share the `X-Onplay-Api-Key` from OnplayWallet settings

### Priority 2: Multi-site Strategy
- [ ] Determine if both WooCommerce sites share a database
- [ ] Decide on single source of truth vs synchronized wallets
- [ ] If Option B: configure the secondary site to proxy wallet operations to the master

### Priority 3: Plugin Adjustments
- [ ] Update POS settings labels to clarify the inbound vs outbound distinction
- [ ] Make the outbound connector (WC → POS) optional/secondary
- [ ] Emphasize the inbound API (POS → WC) as the primary integration

### Priority 4: Testing
- [ ] Test the REST API endpoints using curl or Postman
- [ ] Validate credit/debit/balance operations work correctly
- [ ] Test webhook signature validation
- [ ] Test QR payment flow end-to-end
