# azadi.wtf — Design System

Reference for building new pages. Every page must follow these rules.

## Navigation Bar

```
✊ azadi.wtf | Historical Movements | Protest Guide | ↓ PDF
```

**Exact HTML:**
```html
<nav class="top-nav">
  <div class="container">
    <a href="index.html" class="nav-logo">&#9994; azadi.wtf</a>
    <div class="nav-links">
      <a href="history.html">Historical Movements</a>
      <a href="guide.html">Protest Guide</a>
      <a href="Protest%20Safely.pdf" class="btn-pdf" download>&#8595; PDF</a>
    </div>
  </div>
</nav>
```

Active page gets `class="active"` on its link. PDF button always uses the `btn-pdf` class.

## CSS Variables (must be identical on every page)

```css
:root {
  --bg: #060606;
  --bg-card: #0d0d0d;
  --bg-elevated: #141414;
  --border: #1e1e1e;
  --text: #ededed;
  --text-dim: #7e7e7e;
  --text-muted: #484848;
  --accent: #dc4536;
  --d-critical: #e62e1e;
  --d-high: #e86930;
  --d-moderate: #d4a020;
  --d-low: #2ea85a;
  --font: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
```

## Container Width

All pages: `max-width: 880px` (exception: index.html map layout uses `max-width: 1100px` for map + panel)

## Typography

| Element | Size | Weight | Notes |
|---------|------|--------|-------|
| Page title (h1) | 2rem | 900 | letter-spacing: -0.02em |
| Section title (h2) | 1.35rem | 800 | letter-spacing: -0.01em |
| Subsection (h3) | 1rem | 700 | color: var(--accent) |
| Body text (p) | 0.88rem | 400 | color: var(--text-dim), line-height: 1.6 |
| Nav links | 0.82rem | 500 | color: var(--text-dim) |
| Small text | 0.72rem | 400 | color: var(--text-muted) |

## Colors — Danger Levels

| Level | Color | Usage |
|-------|-------|-------|
| Critical | `#e62e1e` | Red fill for critical states, red border-left on cards |
| High | `#e86930` | Orange |
| Moderate | `#d4a020` | Yellow/amber |
| Low | `#2ea85a` | Green |
| None / No data | `#1e1e1e` | Dark gray (NOT white) |

## States (no-protests / no-data)

- `no-protests`: `fill: #1e1e1e` (dark gray, NOT white)
- `no-data`: `fill: #1a1a1a` (slightly darker)

## Legend

```html
<span class="legend-item"><span class="legend-dot critical"></span>Critical</span>
<span class="legend-item"><span class="legend-dot high"></span>High</span>
<span class="legend-item"><span class="legend-dot moderate"></span>Moderate</span>
<span class="legend-item"><span class="legend-dot low"></span>Low</span>
<span class="legend-item"><span class="legend-dot none"></span>None</span>
```

Legend dot for "none" must be dark gray: `background: #1e1e1e; border: 1px solid #333`

## Footer

```html
<footer>
  <div class="container">
    <a href="index.html">India Map</a> &nbsp;·&nbsp;
    <a href="guide.html">Protest Guide</a> &nbsp;·&nbsp;
    azadi.wtf — Stay safe. Protect each other. &#9994;
  </div>
</footer>
```

## Checklist for New Pages

- [ ] Nav bar matches exactly (4 links: azadi.wtf, Historical Movements, Protest Guide, PDF)
- [ ] Container width is 880px
- [ ] CSS variables match the design system
- [ ] Dark background, no white states
- [ ] Footer present with links to map and guide
- [ ] No API keys or secrets in HTML
