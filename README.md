# azadi.wtf ✊

Real-time protest safety assessments for Indian cities. Know where to protest, what to bring, and how to stay safe — before you go.

**[azadi.wtf](https://azadi.wtf)** is live. 8 cities. 24 protests tracked. Updated every 4 hours.

---

## What It Does

- **City-by-city assessments** — Individual pages for Delhi, Mumbai, Bangalore, Pune, Hyderabad, Kolkata, Jaipur, and Bhopal. Each shows active protests sorted by danger level (critical → low) with police presence, recent incidents, medical access, and legal support details.
- **Latest news feed** — Every city page pulls 5–6 recent news links from The Hindu, BBC, CNN, Al Jazeera, Deccan Herald, NDTV, Times of India, and local sources. Powered by Tavily search.
- **Protest safety guide** — 8 sections: Before a Protest, What to Bring, During a Protest, Protection Against Weapons (tear gas, batons, pellet guns, water cannons), After a Protest, Protest Roles, Legal Resources in India, Glossary.
- **Downloadable PDF** — Clean, print-formatted guide with page breaks between sections. 1-click download from any page.
- **Auto-updating** — Cron jobs trigger Tavily web search + DeepSeek analysis every 4 hours. Fresh danger assessments, updated statuses, and new news links without manual intervention.
- **Admin panel** — Password-protected dashboard at `/admin` for manual JSON editing.

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
 index.html · city.html · guide.html
```

### Data Loading Priority (Frontend)

1. **PHP API** — live data from cron-updated `protests.json`
2. **Static JSON file** — fallback for simple hosting without PHP
3. **Embedded data** — offline/local fallback (works from `file://`)

## File Structure

```
├── index.html              Homepage: hero, city cards, guide chunks, PDF CTA
├── city.html               Individual city page (hash-routed: city.html#delhi)
├── cities.html             All-cities overview (legacy, not linked from nav)
├── guide.html              Full protest guide with sidebar navigation
├── style.css               Complete design system (responsive, dark theme)
├── script.js               Data loading, rendering, severity sorting
├── guide-print.html        Print-optimized HTML (generates PDF)
├── Protest Safely.pdf      Downloadable guide PDF
├── .htaccess               Apache security rules + HTTPS redirect
│
├── config.php              API keys & settings (EXCLUDED from git)
│
├── data/
│   ├── protests.json       All protest data — the source of truth
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
    └── .htaccess           Block web access (CLI-only)
```

## Setup

### 1. Clone

```bash
git clone https://github.com/anand1996aditya/azadi-wtf.git
cd azadi-wtf
```

### 2. Configure

Create `config.php` from the template:

```php
<?php
define('DEEPSEEK_API_KEY', 'sk-your-key-here');
define('DEEPSEEK_API_URL', 'https://api.deepseek.com/v1/chat/completions');
define('DEEPSEEK_MODEL', 'deepseek-chat');

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
0 */4 * * *  php /home/your-user/public_html/cron/update.php
```

This runs every 4 hours. The updater respects each protest's `lastUpdated` timestamp and only refreshes data whose interval has elapsed.

### 5. Secure

- Delete `cron/sync.php` and `cron/push.php` if they exist (temporary deployment tools)
- Log into `/admin` and change the default password immediately
- Enable SSL (Let's Encrypt) on your host — `.htaccess` auto-redirects HTTP to HTTPS

## Updating Content

**Option A — Admin panel:** Go to `/admin`, log in, edit the JSON directly, save. Changes are instant.

**Option B — Edit the JSON:** Modify `data/protests.json` and re-upload. Each protest entry follows this schema:

```json
{
  "id": "city-cause-001",
  "name": "Protest Name",
  "location": "Specific location in the city",
  "cause": "What the protest is about",
  "dangerLevel": "critical|high|moderate|low",
  "dangerReason": "Why this level was assigned",
  "status": "Current situation description",
  "details": ["Array of bullet points"],
  "lastUpdated": "ISO 8601 timestamp"
}
```

## License

This guide is free to share, adapt, and translate with attribution. The protest safety content is inspired by [protest.wtf](https://protest.wtf).

---

Stay safe. Protect each other. ✊
