# Tuma STK Testing Guide (Hotspot)

This guide configures Tuma key/secret for hotspot paid packages, then auto-connects users only after callback success.

## 1. What is already implemented

The app now supports provider switch for paid hotspot checkout:

- `mpesa` (existing Daraja flow)
- `tuma` (new Tuma STK flow)

Paid-flow files:

- [system/hotspot-payment-api.php](system/hotspot-payment-api.php)
- [system/paymentgateway/tuma.php](system/paymentgateway/tuma.php)
- [system/tuma-callback.php](system/tuma-callback.php)
- [ui/ui/admin/paymentgateway/tuma.tpl](ui/ui/admin/paymentgateway/tuma.tpl)

## 2. Configure Tuma credentials in admin

Open:

- `Admin -> Payment Gateway -> tuma`

Set:

1. `Hotspot Payment Provider`: `tuma`
2. `Environment`: `sandbox` (for testing)
3. `API Base URL`: from Tuma docs (example `https://api.tuma.co.ke`)
4. `STK Path`: exact STK endpoint path from Tuma docs (example `/v1/payments/stk-push`)
5. `API Key`: your Tuma API key
6. `API Secret`: your Tuma API secret
7. `Shortcode/Till`: optional unless required by Tuma payload
8. `Callback URL`: `https://network.apperstar.dev/system/tuma-callback.php`

Save settings.

## 3. Configure callback on Tuma dashboard

In Tuma merchant developer settings, set webhook/callback URL to:

- `https://network.apperstar.dev/system/tuma-callback.php`

Ensure Tuma sends JSON with at least one identifier field like:

- `checkout_id` or `checkoutId` or `transaction_id` or `request_id`

And success/failure indicator such as:

- `status=success` (or equivalent)
- failure status/message on declined/cancelled transactions

## 4. Test flow (paid package)

1. Connect phone to hotspot and open login page.
2. Select paid package (e.g. 10 KES).
3. Enter phone number and submit.
4. Confirm STK PIN prompt on phone.
5. On callback success:
   - Session marked `completed` in `hotspot_payment_sessions`
   - credentials generated and returned by status polling
   - page auto-submits MikroTik login
6. On callback failure:
   - session marked `failed`
   - user is not provisioned for paid access
   - disconnect fallback runs if any account was created unexpectedly

## 5. Verify in database

Check payment sessions:

```sql
SELECT id,phone,plan,amount,checkout_id,status,failure_reason,hotspot_username,updated_at
FROM hotspot_payment_sessions
ORDER BY id DESC
LIMIT 20;
```

## 6. Debug checklist

If STK prompt not sent:

1. Confirm `hotspot_payment_provider=tuma` in `tbl_appconfig`
2. Confirm API base + path are correct for Tuma docs
3. Confirm API key/secret are saved correctly

If callback not received:

1. Confirm callback URL is publicly reachable
2. Confirm Tuma dashboard webhook points to exact URL
3. Check web server logs / PHP logs for `/system/tuma-callback.php`

If payment succeeds but no connect:

1. Check `hotspot_payment_sessions.status` is `completed`
2. Ensure response contains `hotspot_username` and `hotspot_password`
3. Confirm RADIUS path is healthy (`/radius monitor [find]` on MikroTik)

## 7. Important note about Tuma docs

Because the shared Tuma URL is a login page, exact endpoint/field names are not publicly readable by the assistant.

This integration intentionally makes endpoint and path configurable so you can plug in the exact values from your dashboard without code changes.

If Tuma provides a strict payload contract, share one sample request/response and we can finalize the payload mapping 1:1.
