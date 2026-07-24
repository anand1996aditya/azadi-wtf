/* ============================================
   PROTEST SAFETY PLATFORM — Main JavaScript
   ============================================ */

(function () {
  'use strict';

  // ==========================================
  // DATA LOADING
  // Embedded data (works offline, no server needed) takes priority.
  // In production, the admin panel updates protests.json and the
  // PHP API at api/protests.php serves fresh data.
  // ==========================================

  var DATA_URL = 'data/protests.json';

  function fetchData(callback) {
    // 1. Try PHP API — live data from server (updated by cron)
    var apiXhr = new XMLHttpRequest();
    apiXhr.open('GET', 'api/protests.php', true);
    apiXhr.timeout = 5000;
    apiXhr.onload = function () {
      if (apiXhr.status === 200) {
        try {
          callback(null, JSON.parse(apiXhr.responseText));
          return;
        } catch (e) { /* fall through to fallback */ }
      }
      tryStaticJson();
    };
    apiXhr.onerror = function () { tryStaticJson(); };
    apiXhr.ontimeout = function () { tryStaticJson(); };
    apiXhr.send();

    // 2. Fallback: static JSON file (for simple hosting without PHP)
    function tryStaticJson() {
      var xhr = new XMLHttpRequest();
      xhr.open('GET', DATA_URL, true);
      xhr.onload = function () {
        if (xhr.status === 200) {
          try { callback(null, JSON.parse(xhr.responseText)); return; }
          catch (e) { /* fall through */ }
        }
        useEmbeddedData();
      };
      xhr.onerror = function () { useEmbeddedData(); };
      xhr.send();
    }

    // 3. Final fallback: embedded data (offline, local files, no server)
    function useEmbeddedData() {
      if (window.__PROTEST_DATA__) {
        callback(null, window.__PROTEST_DATA__);
        return;
      }
      callback(new Error('All data sources failed'));
    }
  }

  // ==========================================
  // DANGER LEVEL HELPERS
  // ==========================================

  var DANGER_WEIGHT = { critical: 3, high: 2, moderate: 1, low: 0 };
  var DANGER_ORDER = ['critical', 'high', 'moderate', 'low'];

  function dangerClass(level) {
    var map = { critical: 'critical', high: 'high', moderate: 'moderate', low: 'low' };
    return map[level] || 'moderate';
  }

  function dangerLabel(level) {
    var map = { critical: 'CRITICAL', high: 'HIGH', moderate: 'MODERATE', low: 'LOW' };
    return map[level] || level.toUpperCase();
  }

  /**
   * Sort protests by severity — highest danger first.
   */
  function sortBySeverity(protests) {
    return protests.slice().sort(function (a, b) {
      var wA = DANGER_WEIGHT[a.dangerLevel] || 0;
      var wB = DANGER_WEIGHT[b.dangerLevel] || 0;
      return wB - wA;
    });
  }

  /**
   * Sort cities by overall danger — highest first.
   */
  function sortCitiesByDanger(cities) {
    var sorted = [];
    Object.keys(cities).forEach(function (slug) {
      sorted.push({ slug: slug, city: cities[slug] });
    });
    sorted.sort(function (a, b) {
      var wA = DANGER_WEIGHT[a.city.overallDanger] || 0;
      var wB = DANGER_WEIGHT[b.city.overallDanger] || 0;
      return wB - wA;
    });
    return sorted;
  }

  function formatDate(isoString) {
    if (!isoString) return 'Unknown';
    try {
      var d = new Date(isoString);
      return d.toLocaleDateString('en-IN', {
        day: 'numeric', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
      });
    } catch (e) {
      return isoString;
    }
  }

  // ==========================================
  // RENDER CITY CARDS (homepage)
  // ==========================================

  function renderCityCards(data) {
    var grid = document.getElementById('cityGrid');
    if (!grid) return;

    var cities = data.cities;
    var sorted = sortCitiesByDanger(cities);
    var html = '';

    sorted.forEach(function (entry) {
      var slug = entry.slug;
      var city = entry.city;
      var level = city.overallDanger;
      var protestCount = city.protests.length;
      var statusPreview = city.overallStatus.substring(0, 120) + '...';

      html +=
        '<a href="city.html#' + slug + '" class="city-card danger-' + dangerClass(level) + '">' +
          '<div class="city-card-header">' +
            '<h3>' + escapeHtml(city.name) + '</h3>' +
            '<span class="danger-tag ' + dangerClass(level) + '">' + dangerLabel(level) + '</span>' +
          '</div>' +
          '<p class="city-card-status">' + escapeHtml(statusPreview) + '</p>' +
          '<p class="city-card-protest-count">' + protestCount + ' active protest' + (protestCount !== 1 ? 's' : '') + '</p>' +
        '</a>';
    });

    grid.innerHTML = html;
  }

  // ==========================================
  // RENDER FULL CITY DETAILS (cities.html)
  // ==========================================

  function renderCityDetails(data) {
    var container = document.getElementById('citiesContainer');
    if (!container) return;

    var cities = data.cities;
    var sortedCities = sortCitiesByDanger(cities);
    var html = '';

    sortedCities.forEach(function (entry) {
      var slug = entry.slug;
      var city = entry.city;
      var level = city.overallDanger;
      var sortedProtests = sortBySeverity(city.protests);

      html +=
        '<section class="city-detail" id="' + slug + '">' +
          '<div class="city-detail-header">' +
            '<h2><a href="city.html#' + slug + '" style="color:inherit;text-decoration:none;">' + escapeHtml(city.name) + '</a></h2>' +
            '<span class="danger-tag ' + dangerClass(level) + '">' + dangerLabel(level) + '</span>' +
          '</div>' +
          '<p class="city-detail-overall">' + escapeHtml(city.overallStatus) + '</p>';

      sortedProtests.forEach(function (protest) {
        var pLevel = protest.dangerLevel;

        html +=
          '<div class="protest-card ' + dangerClass(pLevel) + '">' +
            '<div class="protest-card-header">' +
              '<h3>' + escapeHtml(protest.name) + '</h3>' +
              '<span class="danger-tag ' + dangerClass(pLevel) + '">' + dangerLabel(pLevel) + '</span>' +
            '</div>' +
            '<p class="protest-card-meta"><strong>Location:</strong> ' + escapeHtml(protest.location) + '</p>' +
            '<p class="protest-card-cause">' + escapeHtml(protest.cause) + '</p>' +
            '<p class="protest-card-status">' + escapeHtml(protest.status) + '</p>' +
            '<div class="protest-card-reason"><strong>Risk Assessment:</strong> ' + escapeHtml(protest.dangerReason) + '</div>' +
            '<ul class="protest-card-details">';

        (protest.details || []).forEach(function (detail) {
          html += '<li>' + escapeHtml(detail) + '</li>';
        });

        html +=
            '</ul>' +
            '<p class="protest-card-updated">Updated: ' + formatDate(protest.lastUpdated) + '</p>' +
          '</div>';
      });

      html += '</section>';
    });

    container.innerHTML = html;
  }

  // ==========================================
  // RENDER INDIVIDUAL CITY PAGE (city.html)
  // ==========================================

  function renderCityPage(data) {
    var container = document.getElementById('cityContent');
    if (!container) return;

    // Get city slug from URL hash: city.html#bangalore
    var slug = window.location.hash.replace('#', '');

    if (!slug || !data.cities[slug]) {
      // Unknown city — show city selector
      var cityList = '';
      var sorted = sortCitiesByDanger(data.cities);
      sorted.forEach(function (entry) {
        cityList += '<li><a href="city.html#' + entry.slug + '">' +
          escapeHtml(entry.city.name) +
          ' <span class="danger-tag ' + dangerClass(entry.city.overallDanger) + '">' + dangerLabel(entry.city.overallDanger) + '</span>' +
          '</a></li>';
      });

      container.innerHTML =
        '<div class="city-selector">' +
          '<h2>Select a City</h2>' +
          '<p class="section-desc">Click a city to see its active protests.</p>' +
          '<ul class="city-selector-list">' + cityList + '</ul>' +
        '</div>';
      return;
    }

    var city = data.cities[slug];
    var level = city.overallDanger;
    var sortedProtests = sortBySeverity(city.protests);

    // Update page title
    document.title = city.name + ' — Protest Assessment | Azadi.wtf';

    var html =
      '<header class="city-page-header">' +
        '<h1>' + escapeHtml(city.name) + '</h1>' +
        '<span class="danger-tag danger-tag-large ' + dangerClass(level) + '">' + dangerLabel(level) + ' — Overall</span>' +
      '</header>' +
      '<p class="city-page-overall">' + escapeHtml(city.overallStatus) + '</p>';

    // Protest cards sorted by severity
    sortedProtests.forEach(function (protest) {
      var pLevel = protest.dangerLevel;

      html +=
        '<div class="protest-card ' + dangerClass(pLevel) + '">' +
          '<div class="protest-card-header">' +
            '<h3>' + escapeHtml(protest.name) + '</h3>' +
            '<span class="danger-tag ' + dangerClass(pLevel) + '">' + dangerLabel(pLevel) + '</span>' +
          '</div>' +
          '<p class="protest-card-meta"><strong>Location:</strong> ' + escapeHtml(protest.location) + '</p>' +
          '<p class="protest-card-cause">' + escapeHtml(protest.cause) + '</p>' +
          '<p class="protest-card-status">' + escapeHtml(protest.status) + '</p>' +
          '<div class="protest-card-reason"><strong>Risk Assessment:</strong> ' + escapeHtml(protest.dangerReason) + '</div>' +
          '<ul class="protest-card-details">';

      (protest.details || []).forEach(function (detail) {
        html += '<li>' + escapeHtml(detail) + '</li>';
      });

      html +=
          '</ul>' +
          '<p class="protest-card-updated">Updated: ' + formatDate(protest.lastUpdated) + '</p>' +
        '</div>';
    });

    // News feed
    if (city.news && city.news.length > 0) {
      html += '<section class="news-feed">';
      html += '<h2>Latest News &mdash; ' + escapeHtml(city.name) + '</h2>';
      city.news.forEach(function (item) {
        var typeIcon = { article: '', video: '', social: '' };
        var icon = typeIcon[item.type] || '';
        html +=
          '<a href="' + escapeHtml(item.url) + '" class="news-item" target="_blank" rel="noopener">' +
            '<div class="news-item-source">' + escapeHtml(item.source) + ' <span class="news-item-type">' + (item.type === 'video' ? 'Video' : item.type === 'social' ? 'Social' : 'Article') + '</span></div>' +
            '<h4>' + escapeHtml(item.title) + '</h4>' +
            '<p>' + escapeHtml(item.snippet) + '</p>' +
          '</a>';
      });
      html += '</section>';
    }

    // Other cities nav
    html += '<div class="other-cities">';
    html += '<h3>Other Cities</h3>';
    html += '<div class="other-cities-grid">';
    var sorted = sortCitiesByDanger(data.cities);
    sorted.forEach(function (entry) {
      if (entry.slug === slug) return; // skip current
      html +=
        '<a href="city.html#' + entry.slug + '" class="other-city-chip danger-' + dangerClass(entry.city.overallDanger) + '">' +
          escapeHtml(entry.city.name) +
          ' <span class="danger-tag ' + dangerClass(entry.city.overallDanger) + '">' + dangerLabel(entry.city.overallDanger) + '</span>' +
        '</a>';
    });
    html += '</div></div>';

    container.innerHTML = html;
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // PDF download is a direct link to guide.pdf — no JS needed.

  // ==========================================
  // GUIDE NAVIGATION HIGHLIGHTING
  // ==========================================

  function setupGuideNav() {
    var nav = document.getElementById('guideNav');
    if (!nav) return;

    var links = nav.querySelectorAll('a');
    var sections = [];
    links.forEach(function (link) {
      var href = link.getAttribute('href');
      if (href && href.startsWith('#')) {
        var el = document.querySelector(href);
        if (el) sections.push({ link: link, el: el });
      }
    });

    function updateActive() {
      var scrollPos = window.scrollY + 100;
      var activeLink = null;

      sections.forEach(function (s) {
        if (s.el.offsetTop <= scrollPos) {
          activeLink = s.link;
        }
      });

      links.forEach(function (l) { l.classList.remove('active'); });
      if (activeLink) activeLink.classList.add('active');
    }

    window.addEventListener('scroll', updateActive, { passive: true });
    updateActive();
  }

  // ==========================================
  // LAST UPDATED
  // ==========================================

  function updateLastUpdated(data) {
    var els = document.querySelectorAll('#lastUpdated');
    els.forEach(function (el) {
      el.textContent = formatDate(data.lastUpdated);
    });
  }

  // ==========================================
  // INIT
  // ==========================================

  fetchData(function (err, data) {
    if (err) {
      console.error('Failed to load protest data:', err.message);
      showError('Failed to load data. Please refresh or try again later.');
      return;
    }

    updateLastUpdated(data);
    renderCityCards(data);
    renderCityDetails(data);
    renderCityPage(data);
  });

  function showError(msg) {
    var ids = ['cityGrid', 'citiesContainer', 'cityContent'];
    ids.forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.innerHTML = '<div class="error-state">' + msg + '</div>';
    });
  }

  // Run UI setup regardless of data loading
  setupGuideNav();

  console.log('%c\u270A Protest Safely platform loaded.%c Stay safe. Protect each other.',
    'font-weight:bold;color:#e64a3a;', '');
})();
