=== OnplayWallet - Wallet for WooCommerce with POS Integration ===
Contributors: josborquez
Tags: onplaywallet, woocommerce wallet, digital wallet, pos integration, onplaypos, cashback, partial payment, qr payment
Requires PHP: 7.4
Requires at least: 6.4
Tested up to: 6.9.1
WC requires at least: 8.0
WC tested up to: 10.5.2
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Wallet digital para WooCommerce con integración completa al sistema OnplayPOS. Soporta pagos, pagos parciales, recargas, transferencias, cashback, pagos QR y sincronización bidireccional con punto de venta.

== Description ==

= OnplayWallet: Wallet Digital + POS para WooCommerce =

OnplayWallet es un sistema avanzado de wallet digital diseñado para WooCommerce con integración nativa al sistema OnplayPOS. Permite a tus clientes depositar fondos, transferir dinero, realizar pagos con saldo, y pagar en terminales POS mediante código QR.

= Características principales =

**Wallet Digital**
- Sistema de wallet que funciona como método de pago en WooCommerce.
- Los clientes pueden recargar saldo usando cualquier pasarela de pago habilitada.
- Transferencias entre usuarios con cargos configurables.
- Pagos parciales: usar el saldo del wallet como pago parcial en el checkout.
- Pagos completos: comprar directamente con el saldo del wallet.
- Sistema de cashback configurable (por carrito, producto o categoría).
- Notificaciones por email de transacciones y saldo bajo.
- Soporte para WooCommerce Blocks (checkout por bloques).

**Integración OnplayPOS**
- Conexión bidireccional con el sistema de punto de venta OnplayPOS.
- API REST dedicada para operaciones del POS (crédito, débito, consulta de saldo).
- Pagos QR: los clientes generan un código QR en su wallet para pagar en terminales POS.
- Webhooks para sincronización en tiempo real de transacciones.
- Búsqueda de clientes por email o teléfono desde el POS.
- Creación automática de clientes desde el POS.
- Panel de administración con estado de conexión y endpoints del POS.
- Protección contra transacciones duplicadas mediante referencias únicas.
- Logs detallados de la comunicación con el POS.

**Administración**
- Panel completo para gestionar balances de usuarios.
- Historial detallado de transacciones con filtros.
- Exportación CSV de transacciones.
- Bloqueo/desbloqueo de wallets por usuario.
- Widget de reporte de recargas en el dashboard de WooCommerce.
- Página dedicada de estado de OnplayPOS con todos los endpoints.

= API REST Endpoints para OnplayPOS =

| Endpoint | Método | Descripción |
| --- | --- | --- |
| `/wp-json/onplay/v1/pos/balance` | GET | Consultar saldo del wallet |
| `/wp-json/onplay/v1/pos/credit` | POST | Acreditar saldo al wallet |
| `/wp-json/onplay/v1/pos/debit` | POST | Debitar saldo del wallet (cobro POS) |
| `/wp-json/onplay/v1/pos/transactions` | GET | Historial de transacciones |
| `/wp-json/onplay/v1/pos/qr-pay` | POST | Procesar pago por código QR |
| `/wp-json/onplay/v1/pos/customer` | GET | Buscar cliente por email o teléfono |
| `/wp-json/onplay/v1/pos/webhook` | POST | Recibir eventos webhook del POS |
| `/wp-json/onplay/v1/pos/status` | GET | Estado de la conexión |

= Requisitos =
- WordPress 6.4 o superior
- WooCommerce 8.0 o superior
- PHP 7.4 o superior

== Installation ==

1. Subir la carpeta `OnplayWallet` al directorio `/wp-content/plugins/`.
2. Activar el plugin desde el menú 'Plugins' de WordPress.
3. Ir a OnplayWallet > Settings para configurar las opciones generales.
4. Para la integración POS: Ir a OnplayWallet > Settings > OnplayPOS y configurar la URL del API, API Key y API Secret.
5. Consultar OnplayWallet > OnplayPOS para ver los endpoints disponibles y probar la conexión.

== Changelog ==

= 1.0.0 =
* Initial release of OnplayWallet
* Complete wallet system for WooCommerce (based on proven wallet architecture)
* OnplayPOS integration with dedicated REST API
* QR code payment support for POS terminals
* Webhook support for bidirectional sync
* Customer lookup by email and phone
* POS admin dashboard with connection status
* Configurable sync direction (bidirectional, WC->POS, POS->WC)
* Duplicate transaction protection via reference IDs
* Comprehensive logging system
