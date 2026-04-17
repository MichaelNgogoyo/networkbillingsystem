# Hostel Hotspot Portal

This folder contains the captive portal page that should live on the MikroTik hotspot, not inside the PHPNuxBill backend.

## What it does

- Collects a phone number from the guest.
- Lets the guest choose a package before sign-up.
- Posts registration directly to PHPNuxBill.
- Lets existing users sign in from the same captive portal.
- Carries the router session values `nux-mac`, `nux-ip`, `nux-router`, and `nux-hostname` into PHPNuxBill.

## Files

- `login.html` - replace the MikroTik hotspot `login.html` with this file.

## Before you use it

- Replace `__BILLING_URL__` with your real PHPNuxBill URL.
- Keep PHPNuxBill in `hs_auth_method = api` for the simplest setup.
- Make sure the MikroTik API user exists and has `api` policy.

## Package names used by the portal

Create matching Hotspot plans in PHPNuxBill with these names:

- `Trial 10 Minutes`
- `1 Hour Package`
- `4 Hours Package`
- `8 Hours Package`
- `12 Hours Package`
- `Daily Package`
- `Weekly Package`
- `Monthly Package`

## Recommended plan settings

Use these as a starting point in Services -> Hotspot:

- Trial: `price = 0`, `type = Hotspot`, `typebp = Limited`, `limit_type = Time_Limit`, `time_limit = 10`, `time_unit = Mins`, `validity = 10`, `validity_unit = Mins`, `shared_users = 1`
- 1 Hour: `price = 10`, `type = Hotspot`, `typebp = Limited`, `limit_type = Time_Limit`, `time_limit = 1`, `time_unit = Hrs`, `validity = 1`, `validity_unit = Days`, `shared_users = 1`
- 4 Hours: `price = 20`, `type = Hotspot`, `typebp = Limited`, `limit_type = Time_Limit`, `time_limit = 4`, `time_unit = Hrs`, `validity = 1`, `validity_unit = Days`, `shared_users = 1`
- 8 Hours: `price = 30`, `type = Hotspot`, `typebp = Limited`, `limit_type = Time_Limit`, `time_limit = 8`, `time_unit = Hrs`, `validity = 1`, `validity_unit = Days`, `shared_users = 1`
- 12 Hours: `price = 40`, `type = Hotspot`, `typebp = Limited`, `limit_type = Time_Limit`, `time_limit = 12`, `time_unit = Hrs`, `validity = 1`, `validity_unit = Days`, `shared_users = 1`
- Daily: `price = 100`, `type = Hotspot`, `typebp = Unlimited`, `validity = 1`, `validity_unit = Days`, `shared_users = 1`
- Weekly: `price = 500`, `type = Hotspot`, `typebp = Unlimited`, `validity = 7`, `validity_unit = Days`, `shared_users = 1`
- Monthly: `price = 1500`, `type = Hotspot`, `typebp = Unlimited`, `validity = 30`, `validity_unit = Days`, `shared_users = 1`

## Behavior to expect

- The trial card disappears from the user experience once the customer is online because the router replaces the portal with the active session state.
- When the 10-minute trial expires, MikroTik disconnects the client and the portal returns.
- Because `shared_users = 1`, the same account can only be active on one device at a time.
- If the customer logs out from one device, they can sign in on another device using the same credentials.

## Upload notes

- On MikroTik, replace the hotspot `login.html` file with this one.
- Keep your hotspot interface, DHCP, and NAT already configured.
- Make sure the router API user is reachable from the PHPNuxBill host on port `8728`.