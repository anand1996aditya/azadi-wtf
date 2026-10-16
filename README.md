# azadi.wtf ✊ — v3.0

Real-time protest-safety platform for India. Interactive map, per-state protest details, and a downloadable safety guide.

**[azadi.wtf](https://azadi.wtf)** is live. **37 states/UTs tracked, 95+ protests monitored**, 10 historic movements, auto-updated **every 12 hours**.

---

## What It Does

- **Interactive India map** — hover any state to preview, click to lock, click a protest for full details (risk assessment, police presence, medical access, legal support). All map assets are **self-hosted** for fast, reliable loads.
- **State detail pages** — full danger assessment, risk analysis, and the latest news per protest.
- **Protest safety guide** — 9 sections + downloadable PDF.
- **Auto-updating** — a multi-source search fallback chain feeds DeepSeek analysis every 12 hours.
- **Admin panel** — password-protected dashboard at `/admin`.
- **Deterministic data-quality engine** — deduplication, staleness demotion, anti-hallucination, and URL hygiene (see below).

## Architecture

```
Search fallback chain          DeepSeek (deepseek-chat)
  TinyFish → Firecrawl →          │
  SerpAI → Tavily → Parallel      ▼
        │                   cron/update.php ── analyzes news, updates risk
        ▼                         │
  search.php (shared)             ▼
                              data/protests.json  ◄── admin/index.php (manual edits)
                                   │
                                   ▼
                              api/protests.php  ◄── frontend fetches live data
                                   │
                                   ▼
                              index.html · state.html · guide.html
```

The homepage uses d3.js + TopoJSON (both self-hosted in `assets/`) for the map. State data is served from `data.js`, synced from `protests.json`.

## Search Fallback Chain (cost-optimised)

Order: **TinyFish → Firecrawl → SerpAI → Tavily → Parallel** (Aditya's sequence, 24 Sep 2026).

| # | Tool | Cost (approx) |
|---|---|---|
| 1 | TinyFish | Free |
| 2 | Firecrawl | Free tier, then $16–83/mo |
| 3 | SerpAI | Free 250, then $75–150/mo |
| 4 | Tavily | Free tier, then ~$0.008/search |
| 5 | Parallel | Paid (Parallel Web Systems) |

The chain lives **only** in `cron/search.php` (`searchWeb()`). If one source fails or hits its quota, it automatically falls through to the next — verified live.

## Deterministic Data-Quality Checks

These run at the code level (not relying on the LLM), layered across the pipeline:

- **Pre-analysis (token savers)** — `lastChecked` re-check cadence; "no new info" short-circuit (skips DeepSeek when search results are empty/unchanged); headline dedup; discovery dedup (exact + fuzzy).
- **Post-analysis validation** — strict JSON schema validation + one retry; cross-field consistency (auto-downgrade on "calm"/"concluded" status vs. high/critical level); URL stripping (zero URLs allowed in prose).
- **Recency & staleness** — `recencyDays` + `confidence` from DeepSeek; recency demotion (critical/high with incident > 7d → moderate); staleness cap (no fresh analysis > 7d → moderate); dormant auto-archive (low + stale → removed).
- **Cross-source reliability** — level changes require 2 consecutive runs to commit (anti-flapping); every protest records its `source` (which search API supplied the data).
- **Data hygiene** — stable protest IDs (hash of name); per-protest staleness monitor.

## File Structure

```
├── index.html / state.html / guide.html / history.html / checklist.html
├── style.css / script.js / data.js         (frontend)
├── .htaccess                                (Apache security + HTTPS)
├── SECURITY.md
│
├── config.php                               (PHP API keys — GITIGNORED)
│
├── assets/                                  (self-hosted, no CDN)
│   ├── india.json                           (map TopoJSON)
│   ├── d3.v7.min.js
│   └── topojson-client.min.js
│
├── data/
│   ├── protests.json                        (live protest data)
│   ├── auth.json                            (admin bcrypt — GITIGNORED)
│   └── .htaccess
│
├── api/
│   ├── protests.php                         (public JSON endpoint)
│   └── test-serpai.php                      (search-source test)
│
├── admin/
│   └── index.php
│
└── cron/
    ├── run.php                              (master cron entrypoint)
    ├── update.php                           (auto-updater: search → DeepSeek → JSON)
    ├── search.php                           (shared fallback chain)
    ├── verify.php                           (political data verification)
    ├── verify_news.php / verify_news.js     (news-link verification)
    ├── healthcheck.js                       (full health check)
    ├── keys.json                            (JS API keys — GITIGNORED)
    ├── keys.example.json                    (template)
    └── push.php / sync.php / sync_datajs.php
```

## Setup

### 1. Clone

```bash
git clone https://github.com/anand1996aditya/azadi-wtf.git
cd azadi-wtf
```

### 2. Configure API keys

Two gitignored files hold the keys (never commit real keys):

**`config.php`** (PHP scripts — update.php, verify.php, verify_news.php):

```php
<?php
define('DEEPSEEK_API_KEY', 'sk-...');
define('DEEPSEEK_MODEL', 'deepseek-chat');
define('TAVILY_API_KEY', 'tvly-...');
define('SERPAI_API_KEY', '...');
define('FIRECRAWL_API_KEY', 'fc-...');
define('TINYFISH_API_KEY', 'sk-tinyfish-...');
define('CRON_SECRET', '...');
```

**`cron/keys.json`** (Node scripts — healthcheck.js, verify_news.js) — copy `keys.example.json` and fill in:

```json
{
  "TINYFISH": "sk-tinyfish-...",
  "FIRECRAWL": "fc-...",
  "SERPAI": "...",
  "TAVILY": "tvly-...",
  "DEEPSEEK": "sk-...",
  "CRON_SECRET": "..."
}
```

### 3. Deploy

Upload to any PHP-capable host (Hostinger, Apache/Nginx + PHP 8.x). Make `data/` writable.

### 4. Enable Auto-Updates

Add a cron job (Hostinger cPanel → Cron Jobs):

```
0 0,12 * * * /usr/bin/php /home/your-user/domains/azadi.wtf/public_html/cron/run.php
```

Runs at 00:00 and 12:00. `run.php` chains the protest update → news-link verification → political verification.

### 5. Secure

- Delete `cron/sync.php` if present.
- Log into `/admin` and change the default password.
- Enable SSL — `.htaccess` auto-redirects HTTP → HTTPS.
- Confirm `.gitignore` excludes `config.php`, `cron/keys.json`, and `data/auth.json`.

## GitHub Actions Deploy

Push to `main` and changed frontend files auto-deploy to azadi.wtf via GitHub Actions. Set `AZADI_CRON_SECRET` in repository secrets.

## Updating Content

- **Admin panel:** `/admin` → log in → edit → save.
- **JSON:** edit `data/protests.json` and re-upload.

## License

Free to share, adapt, and translate with attribution. Inspired by [protest.wtf](https://protest.wtf).

---

Stay safe. Protect each other. ✊
