# OnplayWallet — Stack Tecnológico y Conexiones

## 1. Stack Tecnológico

| Capa | Tecnología | Versión mínima |
|------|-----------|----------------|
| **Lenguaje Backend** | PHP | 7.4+ |
| **CMS** | WordPress | 6.4+ |
| **Framework E-commerce** | WooCommerce | 8.0+ |
| **Base de Datos** | MySQL/MariaDB (vía `$wpdb`) | — |
| **Frontend** | jQuery, WooCommerce Blocks (React/JSX) | — |
| **Tablas de datos** | jQuery DataTables + Responsive | — |
| **Selector de fechas** | daterangepicker | — |
| **Bloques de pago** | WooCommerce Blocks API (`AbstractPaymentMethodType`) | — |
| **API REST** | WordPress REST API + WooCommerce REST API v3 | — |
| **Autenticación API** | HMAC-SHA256, WordPress Nonces, API Keys | — |
| **Logging** | WC_Logger (canal `onplay-pos-connector`) | — |
| **Internacionalización** | WordPress i18n (text domain: `woo-wallet`) | — |
| **Exportación** | CSV nativo (PHP `fputcsv`) | — |

---

## 2. Arquitectura del Plugin

```
woo-wallet.php  (Entry point — Singleton)
 │
 ├── includes/class-woo-wallet.php             → Clase principal (Singleton)
 │    ├── class-woo-wallet-install.php          → Instalación y migraciones DB
 │    ├── class-woo-wallet-wallet.php           → Lógica de crédito/débito/balance
 │    ├── class-woo-wallet-cashback.php         → Motor de cashback
 │    ├── class-woo-wallet-payment-method.php   → Gateway de pago WooCommerce
 │    ├── class-woo-wallet-admin.php            → Panel de administración
 │    ├── class-woo-wallet-frontend.php         → Interfaz de cliente
 │    ├── class-woo-wallet-ajax.php             → Endpoints AJAX
 │    ├── class-woo-wallet-settings.php         → Configuración
 │    ├── class-onplay-pos-connector.php        → Conector saliente POS
 │    └── class-woo-wallet-actions.php          → Sistema de recompensas
 │
 ├── includes/api/
 │    ├── class-onplay-pos-rest-controller.php  → API entrante POS→WC
 │    └── class-wc-rest-woo-wallet-controller.php → API WooCommerce wallet
 │
 ├── includes/actions/                          → Acciones de recompensa
 ├── includes/marketplace/                      → Dokan, WCMp, WCFM
 ├── includes/multicurrency/                    → WOOCS, WPML
 ├── includes/emails/                           → Notificaciones por email
 ├── includes/export/                           → Exportación CSV
 │
 ├── templates/                                 → Vistas (PHP templates)
 ├── build/                                     → Assets compilados (JS/CSS)
 └── assets/jquery/                             → Librerías jQuery
```

---

## 3. Base de Datos

### Tablas personalizadas

| Tabla | Propósito |
|-------|----------|
| `{prefix}woo_wallet_transactions` | Transacciones de wallet (crédito/débito, monto, balance, moneda, fecha) |
| `{prefix}woo_wallet_transaction_meta` | Metadatos de transacciones (`_onplay_source`, `_pos_reference`, `_pos_sync_status`, etc.) |

### Metadata de WordPress utilizada

| Tipo | Clave | Descripción |
|------|-------|-------------|
| User Meta | `_current_woo_wallet_balance` | Saldo actual del usuario |
| User Meta | `_is_wallet_locked` | Estado de bloqueo de la wallet |
| User Meta | `_onplay_pos_customer` | Flag de cliente POS |
| Order Meta | `_wallet_cashback` | Monto de cashback |
| Order Meta | `_partial_pay_through_wallet_compleate` | Estado de pago parcial |
| Order Meta | `_pos_sync_status` | Estado de sincronización POS |
| Option | `_wallet_settings_general` | Configuración general |
| Option | `_wallet_settings_credit` | Configuración de cashback |
| Option | `_wallet_settings_pos` | Configuración OnplayPOS |
| Option | `_woo_wallet_recharge_product` | ID del producto de recarga |

---

## 4. Conexiones y APIs

### 4.1 API OnplayPOS — Saliente (WC → POS)

**Archivo:** `includes/class-onplay-pos-connector.php`

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| `GET` | `api/ping` | Test de conexión |
| `GET` | `api/wallet/balance?identifier={email}` | Consultar saldo en POS |
| `POST` | `api/wallet/credit` | Acreditar wallet en POS |
| `POST` | `api/wallet/debit` | Debitar wallet en POS |
| `GET` | `api/wallet/transactions` | Historial de transacciones POS |
| `POST` | `api/wallet/qr-validate` | Validar pago QR |
| `POST` | `api/wallet/qr-pay` | Procesar pago QR |
| `POST` | `api/customers/register` | Registrar cliente en POS |

**Autenticación:** HMAC-SHA256 con headers `X-API-Key`, `X-Timestamp`, `X-Signature`
**Transporte:** `wp_remote_request()` (HTTPS, timeout 30s)

---

### 4.2 API OnplayPOS — Entrante (POS → WC)

**Archivo:** `includes/api/class-onplay-pos-rest-controller.php`
**Namespace:** `onplay/v1`

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/onplay/v1/pos/balance` | Consultar saldo por email/user_id |
| `POST` | `/onplay/v1/pos/credit` | Acreditar wallet desde POS |
| `POST` | `/onplay/v1/pos/debit` | Debitar wallet (venta POS) |
| `GET` | `/onplay/v1/pos/transactions` | Historial de transacciones |
| `POST` | `/onplay/v1/pos/qr-pay` | Pago con código QR |
| `GET` | `/onplay/v1/pos/customer` | Buscar cliente por email/teléfono |
| `POST` | `/onplay/v1/pos/webhook` | Recibir webhooks del POS |
| `GET` | `/onplay/v1/pos/status` | Health check |

**Autenticación:** Header `X-Onplay-Api-Key` o WooCommerce REST Auth
**Webhooks soportados:** `wallet.credit`, `wallet.debit`, `customer.created`, `ping`

---

### 4.3 API WooCommerce Wallet

**Archivos:** `includes/api/class-wc-rest-woo-wallet-controller.php`, `includes/api/Controllers/Version3/`
**Namespace:** `wc/v3`

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/wc/v3/wallet` | Obtener transacciones (por email) |
| `POST` | `/wc/v3/wallet` | Crear transacción |
| `GET` | `/wc/v3/wallet/balance` | Consultar saldo |

**Autenticación:** WooCommerce REST API (capability `manage_woocommerce`)

---

### 4.4 Endpoints AJAX internos

**Archivo:** `includes/class-woo-wallet-ajax.php`

| Action | Descripción |
|--------|-------------|
| `woo_wallet_order_refund` | Procesar reembolsos |
| `woo-wallet-user-search` | Autocompletado de usuarios |
| `woo_wallet_partial_payment_update_session` | Actualizar sesión de pago parcial |
| `onplaywallet_export_user_search` | Buscar usuarios para exportación |
| `onplaywallet_do_ajax_transaction_export` | Exportar transacciones CSV |
| `lock_unlock_onplaywallet` | Bloquear/desbloquear wallets |

---

## 5. Diagrama de Conexiones

```
┌─────────────────────────────────────────────────────────────────┐
│                        WORDPRESS + WOOCOMMERCE                   │
│                                                                   │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────────┐   │
│  │   Admin UI    │    │ Frontend UI  │    │ WooCommerce      │   │
│  │  (Dashboard)  │    │  (My Account)│    │ Checkout/Cart    │   │
│  └──────┬───────┘    └──────┬───────┘    └────────┬─────────┘   │
│         │                   │                      │              │
│         ▼                   ▼                      ▼              │
│  ┌─────────────────────────────────────────────────────────┐     │
│  │              ONPLAYWALLET CORE                           │     │
│  │                                                          │     │
│  │  ┌─────────┐  ┌──────────┐  ┌──────────┐  ┌────────┐  │     │
│  │  │ Wallet  │  │ Cashback │  │ Payment  │  │ Email  │  │     │
│  │  │ Engine  │  │ Engine   │  │ Gateway  │  │ System │  │     │
│  │  └────┬────┘  └──────────┘  └──────────┘  └────────┘  │     │
│  │       │                                                  │     │
│  │  ┌────▼────────────────┐  ┌──────────────────────────┐  │     │
│  │  │  MySQL Database     │  │   Rewards/Actions        │  │     │
│  │  │  - transactions     │  │  - Daily visits          │  │     │
│  │  │  - transaction_meta │  │  - Registration bonus    │  │     │
│  │  │  - user_meta        │  │  - Product reviews       │  │     │
│  │  │  - wp_options       │  │  - Referrals             │  │     │
│  │  └─────────────────────┘  └──────────────────────────┘  │     │
│  └─────────────────────────────────────────────────────────┘     │
│         │                                          │              │
│         ▼                                          ▼              │
│  ┌──────────────────┐                ┌──────────────────────┐    │
│  │ REST API (WC v3) │                │ REST API (onplay/v1) │    │
│  │ /wc/v3/wallet/*  │                │ /onplay/v1/pos/*     │    │
│  └────────┬─────────┘                └──────────┬───────────┘    │
│           │                                      │                │
└───────────┼──────────────────────────────────────┼────────────────┘
            │                                      │
            ▼                                      ▼
   ┌─────────────────┐               ┌──────────────────────┐
   │  Clientes API   │               │   ONPLAY POS SERVER  │
   │  (externos)     │◄─────────────►│                      │
   └─────────────────┘  Bidireccional│  - api/wallet/*      │
                        HMAC-SHA256  │  - api/customers/*   │
                                     │  - Webhooks          │
                                     └──────────────────────┘

┌───────────────────────────────────────────────────────────┐
│               INTEGRACIONES OPCIONALES                     │
│                                                            │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐ │
│  │  Dokan   │  │   WCMp   │  │  WCFM    │  │  WOOCS/  │ │
│  │(vendors) │  │(vendors) │  │(vendors) │  │  WPML    │ │
│  └──────────┘  └──────────┘  └──────────┘  │(currency)│ │
│                                             └──────────┘ │
└───────────────────────────────────────────────────────────┘
```

---

## 6. Seguridad

| Mecanismo | Uso |
|-----------|-----|
| **HMAC-SHA256** | Firma de peticiones POS salientes y validación de webhooks entrantes |
| **API Key** | Header `X-Onplay-Api-Key` para autenticación de endpoints POS→WC |
| **WordPress Nonces** | Protección contra CSRF en formularios y AJAX |
| **WooCommerce REST Auth** | Consumer key/secret para API `/wc/v3/wallet/*` |
| **Prepared Statements** | `$wpdb->prepare()` en todas las consultas SQL |
| **Sanitización** | `sanitize_email()`, `sanitize_text_field()`, `absint()` |
| **Permisos** | `current_user_can()`, `check_ajax_referer()` |
| **Protección de duplicados** | Verificación de `reference_id` en transacciones POS |

---

## 7. Configuración (wp_options)

```php
// OnplayPOS
_wallet_settings_pos => [
    'pos_enable'         => 'on/off',
    'pos_api_url'        => 'https://pos-api.example.com/',
    'pos_api_key'        => '...',
    'pos_api_secret'     => '...',
    'pos_webhook_secret' => '...',
    'pos_enable_qr'      => 'on/off',
    'pos_sync_direction' => 'pos_to_wc | both | wc_to_pos',
    'pos_auto_sync'      => 'on/off',
]

// General
_wallet_settings_general => [
    'product_title', 'min_topup_amount', 'max_topup_amount',
    'is_enable_wallet_partial_payment', 'gateway_charge_*', ...
]

// Cashback
_wallet_settings_credit => [
    'is_enable_cashback', 'cashback_rule', 'cashback_type',
    'cashback_amount', 'min_cart_amount', 'max_cashback_amount', ...
]
```

---

## 8. Resumen Ejecutivo

**OnplayWallet** es un plugin WordPress/WooCommerce escrito en **PHP** que implementa una billetera digital con:

- **Backend:** PHP 7.4+ sobre WordPress 6.4+ y WooCommerce 8.0+
- **Base de datos:** MySQL/MariaDB con 2 tablas propias + metadata de WordPress
- **Frontend:** jQuery + WooCommerce Blocks (React)
- **APIs:** 3 capas REST (OnplayPOS entrante, OnplayPOS saliente, WooCommerce v3)
- **Conexión externa principal:** Servidor OnplayPOS (bidireccional, HMAC-SHA256)
- **Sin dependencias externas npm/composer** — usa exclusivamente las APIs de WordPress y WooCommerce
- **Sin archivos .env** — toda la configuración vive en `wp_options`
- **103 archivos** (61 PHP, 12 JS, 14 CSS, 5 imágenes, documentación y traducciones)
