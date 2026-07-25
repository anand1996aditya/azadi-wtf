# azadi.wtf ✊ — v2.0

Interactive India protest map with real-time safety assessments. 28 states, 100+ protests, 10 historic movements. Know what's happening in every state before you go.

**[azadi.wtf](https://azadi.wtf)** is live. 28 states tracked. 100+ protests. Auto-updated every 4 hours.

---

## What It Does

- **Interactive India map** — Hover any state to preview protests. Click to lock — then click any protest for full details with risk assessments, police presence, medical access, and legal support. Delhi auto-selected on load.
- **State detail pages** — Each state has a dedicated page with all active protests, full danger assessments, multi-line risk analysis, and 5–10 latest news articles from The Hindu, BBC, CNN, Al Jazeera, NDTV, Guardian, and more.
- **Protest safety guide** — 9 sections: Before a Protest, What to Bring, During a Protest, Protection Against Weapons (tear gas, batons, pellet guns, water cannons), After a Protest, Protest Roles, Legal Resources in India, Tech Tools, Glossary.
- **Downloadable PDF** — Clean, print-formatted guide with page breaks. 1-click download from any page.
- **Auto-updating** — Cron job triggers Tavily web search + DeepSeek analysis every 4 hours. Fresh danger assessments without manual intervention.
- **Admin panel** — Password-protected dashboard at `/admin` for manual data editing.

## Architecture

```
Tavily Search API          DeepSeek API
      │                        │
      ▼                        ▼
 cron/update.php  ────  analyzes news, updates risk
      │
      ▼
 data/protests.json   ◄──  admin/index.php (manual edits)
      │
      ▼
 api/protests.php     ◄──  frontend fetches live data
      │
      ▼
 index.html · state.html · guide.html
```

The homepage (`index.html`) uses d3.js + TopoJSON from covid19india dataset for an accurate, interactive India map. State data is loaded from `data.js` with 100+ protests across 28 states.

### Data Loading Priority (Frontend)

1. **PHP API** — live data from cron-updated `protests.json`
2. **Static JSON file** — fallback for simple hosting
3. **Embedded data** — offline/local fallback

## File Structure

```
├── index.html              India map homepage (d3.js + TopoJSON)
├── state.html              State detail page (?code=MH)
├── guide.html              Full protest guide with sidebar navigation
├── data.js                 State protest data (100+ protests, 28 states)
├── style.css               Design system for guide + state pages
├── guide-print.html        Print-optimized HTML (generates PDF)
├── Protest Safely.pdf      Downloadable guide PDF
├── .htaccess               Apache security rules + HTTPS redirect
├── SECURITY.md             Security & integrity assessment checklist
│
├── config.php              API keys & settings (EXCLUDED from git)
│
├── data/
│   ├── protests.json       City-level protest data (legacy, cron-updated)
│   ├── auth.json           Admin bcrypt hash (EXCLUDED from git)
│   └── .htaccess           Block direct web access
│
├── api/
│   └── protests.php        Public JSON API endpoint
│
├── admin/
│   └── index.php           Password-protected admin dashboard
│
└── cron/
    ├── update.php          Auto-updater: Tavily search → DeepSeek → JSON
    ├── push.php            File deployment endpoint
    └── .htaccess           Block web access (CLI-only)
```

## Setup

### 1. Clone

```bash
git clone https://github.com/anand1996aditya/azadi-wtf.git
cd azadi-wtf
```

### 2. Configure

Create `config.php`:

```php
<?php
define('DEEPSEEK_API_KEY', 'sk-your-key-here');
define('DEEPSEEK_API_URL', 'https://api.deepseek.com/v1/chat/completions');
define('DEEPSEEK_MODEL', 'deepseek-v4-pro');

define('TAVILY_API_KEY', 'tvly-your-key-here');
define('TAVILY_API_URL', 'https://api.tavily.com/search');

define('INTERVAL_CRITICAL', 3600);
define('INTERVAL_HIGH',     10800);
define('INTERVAL_MODERATE', 43200);
define('INTERVAL_LOW',      86400);

define('CRON_SECRET', 'your-random-64-char-string');

define('DATA_FILE', __DIR__ . '/data/protests.json');
define('LOG_FILE',  __DIR__ . '/data/update.log');
```

### 3. Deploy

Upload to any PHP-capable host (Hostinger, Apache/Nginx + PHP 7.4+). Set `data/` directory to writable (`chmod 755`).

### 4. Enable Auto-Updates

Add a cron job (Hostinger cPanel → Cron Jobs):

```
0 */4 * * * /usr/bin/php /home/your-user/domains/azadi.wtf/public_html/cron/update.php
```

Runs every 4 hours. Updates all protests with fresh Tavily search + DeepSeek analysis.

### 5. Secure

- Delete `cron/sync.php` if it exists (temporary deployment tool)
- Log into `/admin` and change the default password immediately
- Enable SSL (Let's Encrypt) — `.htaccess` auto-redirects HTTP to HTTPS

## GitHub Actions Deploy

Push to `main` and changed files auto-deploy to azadi.wtf via GitHub Actions. Set `AZADI_CRON_SECRET` in repository secrets.

## Updating Content

**Option A — Admin panel:** Go to `/admin`, log in, edit the JSON directly, save.

**Option B — Edit the JSON:** Modify `data/protests.json` and re-upload.

## License

Free to share, adapt, and translate with attribution. Inspired by [protest.wtf](https://protest.wtf).

---

Stay safe. Protect each other. ✊
