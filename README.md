# BCJ Finance

Finance PWA for Body by Coach Jeff: manual payment approvals, revenue reports, and Meta ad spend plus card charges. Live at https://bodybycoachjeff.com/finance/

## What it does
- **Approvals:** manual QR or bank payments (reservation, ebooks, pay-first coaching, onboarding receipts), each with its receipt, expected vs. paid amount, ref no., and Approve/Reject. This is the only approval surface. BCJ Coach no longer shows payment approvals (since v2.58.1). Telegram buttons still work.
- **Reports (owner):** today / week / month totals, revenue by month (new coaching, renewals, reservations, ebooks), and the renewals list.
- **Ads:** Meta spend by day and campaign, leads and chats, cost per lead, revenue ÷ ad spend, and card charges with Meta transaction IDs and a CSV download.
- **Log (owner):** every paid transaction, with search and CSV download.
- **Roles:** the owner (Jeff) sees everything. The partner, who finances the ads, sees only Approvals and Meta Ads. Their money view is limited to reservations, ebooks, ad spend and card charges. Coaching, renewal and onboarding payments are stripped server-side, the partner can't approve them, and their push notifications go to the owner only.
- **Logins** are separate from WordPress: password_hash, 30-day hashed sessions, rate-limited.
- **Push:** payload-less VAPID push (it reuses the BCJ Coach keys). The service worker reads `/bcj-fin/v1/peek`.
- **Branding:** BCJ Coach look (Anton/Inter, #921D2D/#C8283F, official dark logo). It shows an install popup when the app isn't installed.

## Layout
| Path | What |
|---|---|
| `wordpress/finance.php` | bcj-hq module (`wp-content/plugins/bcj-hq/modules/finance.php`). Contains REST `bcj-fin/v1`, serves `/finance/`, `sw.js` and `manifest.json`, and embeds `app/app.html`. |
| `app/app.html` | The app shell (vanilla JS), embedded into `finance.php` at build time. |
| `n8n/manual-payments.*.json` | n8n workflow i7FLUp5YumOIftqH: submit, status, quote, decide, list (one Sheets batchGet). |
| `n8n/ad-spend-sync.*.json` | n8n workflow IpzzhQ5ybwML5566: daily 06:17 PH. Pulls Meta insights, `/activities` billing charges and account totals into the Coaching Center tabs `Ad Spend` and `Ad Billing`. |

## Secrets (not in repo)
Replace these placeholders when deploying:
- `__BCJFIN_NOTIFY_KEY__`: the `x-bcj-fin` header sent by n8n to `/notify`.
- `__BCJ_PAY_KEY__`: the `x-bcj-pay` header for n8n `bcj-pay-*` webhooks.
- In the n8n JSON: `__SECRET__`, `__META_CAPI_TOKEN__`, `__GA4_SECRET__`.

The Meta ads token lives in the n8n credential "Meta Ads read (BCJ Finance)": BCJ Reports system user, `ads_read` + `business_management`.

## Deploy
1. Inline `app/app.html` into the `bcjfin_html()` heredoc in `finance.php`, then put the secrets back.
2. Write the module with `POST /wp-json/bcj-hq/v1/mod_put {name:'finance.php', content}`, logged in as a WP admin. The previous version is kept as `finance.php.bak`.
3. n8n: `PUT /api/v1/workflows/{id}` with `{name, nodes, connections, settings}`. Check that `versionId == activeVersionId`.
4. Log the change in the BCJ Changelog sheet.

## Cost
No Gemini or Google Cloud is used: ₱0 hourly, daily or weekly.

## Rollback
Run `mod_put {name:'finance.php', op:'del'}` and deactivate n8n IpzzhQ5ybwML5566.

## Versions
- 1.0.0: Approvals, reports, log, push, separate logins.
- 1.1.0: Ads tab and Meta spend sync. 1.1.1: partner sees Approvals + Ads only. 1.1.2: install popup.
- 1.2.0: BCJ branding. 1.2.1: card charges from Meta activities.
- 1.3.0: partner money scope (reservations + ebooks + ads only).
