# ArionPay for Tutor LMS

Sell courses for crypto (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) with Tutor LMS's own checkout, through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

Tested on WordPress 7.1.4 with Tutor LMS 4.2.1 (native eCommerce) and PHP 8.3. The tests covered checkout, enrollment, a lost webhook, payment tolerance, Payment Radar, late payments, webhook checks and the payment methods settings page.

## Install

1. **Plugins > Add New > Upload Plugin**, choose `arionpay-for-tutor.zip`, install and activate.
2. **Tutor LMS > Settings > Monetization**: use Tutor's native eCommerce.
3. **Tutor LMS > Settings > Monetization > Payment Methods**: **ArionPay (crypto)** is listed. Open its settings (the sliders icon), fill in the fields below, switch it on and **Save Changes**.

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| Mode | Test (no real money) while you try it; Live for real payments | |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |
| ArionPay address | leave empty | |

Nothing to paste into ArionPay: each invoice tells ArionPay where to send its payment updates (Tutor's own webhook address with `?payment_method=arionpay`).

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## How it works

- At checkout the student chooses **ArionPay (crypto)**, presses **Pay Now** and goes to the ArionPay page. The Tutor order waits unpaid.
- When ArionPay confirms the full payment, the order is **Paid** and **Completed**, the ArionPay invoice id is its transaction id, and Tutor enrolls the student.
- When the student comes back to Tutor's "order placed" page, the plugin asks ArionPay directly, so a lost webhook is caught there too.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically; anything else you decide. If you accept it, the order is marked paid here too.
- A late payment still counts.
- An order is marked paid only by the invoice made for it: same amount and currency, and test or live like the setting. A paid order is never changed again.

## Good to know

- Subscriptions (Tutor LMS Pro) are not supported: crypto cannot charge automatically.
- Tutor LMS uses one site currency. It must be one ArionPay takes: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK, ZAR.
- Like Tutor's own gateways, the keys are stored in Tutor's settings, and its settings screen loads them for administrators.
- Refunds are made from your own wallet; then mark the order refunded in Tutor by hand.
- The plugin sends ArionPay the order number, amount and currency. It does not send the student's name or email.

## Build from source

The release zip is made with `python3 build.py`. It copies the [ArionPay PHP library](https://github.com/ArionPay-Dev/arionpay-php) into the plugin first, from a folder named `arionpay-php` next to this one:

```bash
git clone https://github.com/ArionPay-Dev/arionpay-php ../arionpay-php
python3 build.py
```
