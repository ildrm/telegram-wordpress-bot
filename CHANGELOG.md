# Changelog

All notable changes to this project are documented here.

## [2.1.0] — Security hardening & feature completion

### Security
- Secrets moved out of source into gitignored `config.local.php` / env vars; the
  bot now **fails closed** without a bot token, admin allowlist, and webhook secret.
- Added Telegram webhook secret-token verification (`X-Telegram-Bot-Api-Secret-Token`).
- Replaced the optional single `ADMIN_ID` with a required `admin_ids` allowlist.
- Added SSRF validation on user-supplied site URLs (HTTPS-only, public hosts).
- `check_permission` now authenticates **before** any `switch_to_blog`, and only
  switches to an existing blog.
- Magic-login keys are now single-use and expire after 5 minutes (were never
  generated and had no expiry).
- The `?cron=` health endpoint now requires the webhook secret.

### Fixed
- Callback queries are now answered with the correct id (`$update['callback_query']['id']`).
- Plugin-action callbacks use a session index map instead of inline base64,
  staying within Telegram's 64-byte `callback_data` limit.
- Added null/ownership guards on site lookups and a guard for site-dependent callbacks.
- Added error handling/logging around cURL and PDO calls.

### Added
- Implemented previously stubbed REST handlers: stats, posts (incl. scheduling),
  delete post, media sideload, comments, updates (bulk), users, system, WooCommerce.
- Wired Telegram menus for Posts (compose: publish/draft/schedule), Comments
  (approve/spam/trash), Themes (list/switch), and System (flush cache, optimize DB,
  update-all, magic login).
- Real cron health monitoring that alerts owners when a site is unreachable.
- Destructive-action confirmation (plugin delete).
- Engineering hygiene: `composer.json`, PHPUnit tests, PHPStan config, GitHub Actions CI.
