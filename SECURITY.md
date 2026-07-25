# azadi.wtf — Security & Integrity Assessment

Run this checklist before every deployment. Automated via `./check.sh` (future).

---

## 1. API Key Exposure

| # | Check | Method | Expected |
|---|-------|--------|----------|
| 1.1 | `config.php` not web-accessible | `curl -I https://azadi.wtf/config.php` | 403 or empty |
| 1.2 | `data/auth.json` not web-accessible | `curl -I https://azadi.wtf/data/auth.json` | 403 |
| 1.3 | `data/protests.json` not web-accessible | `curl -I https://azadi.wtf/data/protests.json` | 403 |
| 1.4 | No hardcoded keys in `data.js` | `grep -E 'sk-|tvly-' data.js` | No matches |
| 1.5 | No hardcoded keys in `index.html` | `grep -E 'sk-|tvly-' index.html` | No matches |
| 1.6 | No hardcoded keys in `state.html` | `grep -E 'sk-|tvly-' state.html` | No matches |
| 1.7 | `.gitignore` excludes `config.php` | `cat .gitignore` | Contains `config.php` |
| 1.8 | `.gitignore` excludes `auth.json` | `cat .gitignore` | Contains `data/auth.json` |
| 1.9 | GitHub repo has no keys in history | Check GitHub | No keys visible |

## 2. Page Availability

| # | Check | URL | Expected |
|---|-------|-----|----------|
| 2.1 | Homepage loads | `https://azadi.wtf/` | 200, India map visible |
| 2.2 | State page loads | `https://azadi.wtf/state.html?code=DL` | 200 |
| 2.3 | Guide page loads | `https://azadi.wtf/guide.html` | 200, all 9 sections |
| 2.4 | API endpoint works | `https://azadi.wtf/api/protests.php` | 200, valid JSON |
| 2.5 | PDF downloadable | `https://azadi.wtf/Protest%20Safely.pdf` | 200, application/pdf |
| 2.6 | Admin panel accessible | `https://azadi.wtf/admin/index.php` | 200, login form |
| 2.7 | HTTPS enforced | `curl -I http://azadi.wtf/` | 301 → HTTPS |

## 3. Admin Security

| # | Check | Method | Expected |
|---|-------|--------|----------|
| 3.1 | Login page shows no default password | View `/admin/index.php` | No "protest2026" text |
| 3.2 | Admin uses bcrypt hashing | Check admin code | `password_verify()` used |
| 3.3 | Admin has session protection | Check admin code | `session_start()` present |

## 4. Cron Updater

| # | Check | Method | Expected |
|---|-------|--------|----------|
| 4.1 | `cron/update.php` not web-accessible without secret | `curl https://azadi.wtf/cron/update.php` | 403 or "Unauthorized" |
| 4.2 | Cron works with valid secret | `curl https://azadi.wtf/cron/update.php?secret=...` | "Done. Updated: ..." |
| 4.3 | `cron/.htaccess` blocks web access | `curl -I https://azadi.wtf/cron/.htaccess` | 403 |
| 4.4 | Hostinger cron job active | Check cPanel → Cron Jobs | Entry exists, path correct |

## 5. Content Integrity

| # | Check | Method | Expected |
|---|-------|--------|----------|
| 5.1 | Guide has all 9 sections | View `guide.html` | Before, Bring, During, Protection, After, Roles, Legal, Tech, Glossary |
| 5.2 | Homepage has guide chunks | View `https://azadi.wtf/` | 8 guide cards visible below map |
| 5.3 | State page has rich details | `state.html?code=DL` | cause, status, dangerReason, details array |
| 5.4 | State page has back link | View `state.html` | "Back to India Map" link present |
| 5.5 | PDF has all sections | Download PDF | 8+ pages, all guide sections |

## 6. Server Health

| # | Check | Method | Expected |
|---|-------|--------|----------|
| 6.1 | `data/` directory writable | PHP write test | Returns success |
| 6.2 | `update.log` exists | FTP listing | File present in `data/` |
| 6.3 | SSL certificate valid | Browser check | Padlock icon, no warnings |
| 6.4 | No directory listing enabled | `curl https://azadi.wtf/data/` | 403, not a file listing |

## 7. GitHub Sync

| # | Check | Method | Expected |
|---|-------|--------|----------|
| 7.1 | Latest commit pushed | `git status` | Clean, up to date |
| 7.2 | No sensitive files in repo | Check GitHub file list | No `config.php`, no `auth.json` |
| 7.3 | README is current | View README.md | Reflects current architecture |

---

## Run Log

| Date | Run By | Passes | Failures | Notes |
|------|--------|--------|----------|-------|
| | | | | |
