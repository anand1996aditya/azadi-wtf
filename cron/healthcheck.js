#!/usr/bin/env node
/**
 * azadi.wtf — Health Check Script (v2)
 * Verifies all 4 search sources (TinyFish, Firecrawl, SerpAPI, Tavily)
 * + DeepSeek analysis, then pages, security, cron, and data integrity.
 *
 * Run: node cron/healthcheck.js
 */

var https = require('https');
var http = require('http');
var fs = require('fs');
var path = require('path');

// API keys — loaded from gitignored cron/keys.json (see keys.example.json)
var KEYS = {};
try { KEYS = JSON.parse(fs.readFileSync(path.join(__dirname, 'keys.json'), 'utf8')); } catch (e) {}

var BASE = 'https://azadi.wtf';
var SECRET = process.env.CRON_SECRET || KEYS.CRON_SECRET || '';

// ── API credentials ────────────────────────────────────────────────
var TINYFISH_KEY = process.env.TINYFISH_KEY || KEYS.TINYFISH || '';
var FIRECRAWL_KEY = process.env.FIRECRAWL_KEY || KEYS.FIRECRAWL || '';
var SERPAI_KEY = process.env.SERPAI_KEY || KEYS.SERPAI || '';
var TAVILY_KEY = process.env.TAVILY_KEY || KEYS.TAVILY || '';
var DEEPSEEK_KEY = process.env.DEEPSEEK_KEY || KEYS.DEEPSEEK || '';
var DEEPSEEK_MODEL = 'deepseek-chat';

// ── State ──────────────────────────────────────────────────────────
var results = { pass: 0, fail: 0, warn: 0 };
var tests = [];
var SEARCH = {};   // search source status map (for summary)

function test(name, fn) { tests.push({ name: name, fn: fn }); }

function pass(name, detail) { results.pass++; console.log('  ✓ ' + name + (detail ? ' — ' + detail : '')); }
function fail(name, detail) { results.fail++; console.log('  ✗ ' + name + (detail ? ' — ' + detail : '')); }
function warn(name, detail) { results.warn++; console.log('  ⚠ ' + name + (detail ? ' — ' + detail : '')); }

// Generic HTTP(S) request. Returns {status, body, headers, ms}.
function request(opts, callback) {
  var mod = opts.port === 80 || opts.protocol === 'http:' ? http : https;
  var started = Date.now();
  var req = mod.request({
    hostname: opts.hostname,
    port: opts.port || 443,
    path: opts.path,
    method: opts.method || 'GET',
    timeout: opts.timeout || 20000,
    headers: opts.headers || {}
  }, function (res) {
    var body = '';
    res.on('data', function (d) { body += d; });
    res.on('end', function () {
      callback(null, { status: res.statusCode, body: body, headers: res.headers, ms: Date.now() - started });
    });
  });
  req.on('error', function (e) { callback(e); });
  req.on('timeout', function () { req.destroy(new Error('timeout')); });
  if (opts.payload) req.write(opts.payload);
  req.end();
}

function postJson(hostname, p, headers, payload, cb) {
  request({ hostname: hostname, path: p, method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, headers), payload: payload }, cb);
}

function httpGet(url, cb) {
  var mod = url.indexOf('https') === 0 ? https : http;
  var req = mod.get(url, { timeout: 10000, headers: { 'User-Agent': 'azadi.wtf-healthcheck/2.0' } }, function (res) {
    var body = '';
    res.on('data', function (d) { body += d; });
    res.on('end', function () { cb(null, res.statusCode, body); });
  });
  req.on('error', function (e) { cb(e); });
  req.on('timeout', function () { req.destroy(); cb(new Error('timeout')); });
}

function runTests(i) {
  if (i >= tests.length) {
    printSearchSummary();
    console.log('\n══════════════════════════════════════════');
    console.log('  RESULT: ' + results.pass + ' pass, ' + results.fail + ' fail, ' + results.warn + ' warn');
    console.log('══════════════════════════════════════════\n');
    process.exit(results.fail > 0 ? 1 : 0);
  }
  tests[i].fn(function () { runTests(i + 1); });
}

function printSearchSummary() {
  console.log('\n── Search Source Summary ──');
  var order = ['TinyFish', 'Tavily', 'Firecrawl', 'SerpAPI', 'DeepSeek'];
  order.forEach(function (n) {
    var s = SEARCH[n];
    if (s === 'OK') console.log('  ✓ ' + n + ': WORKING');
    else if (s) console.log('  ✗ ' + n + ': ' + s);
    else console.log('  ? ' + n + ': not tested');
  });
}

console.log('\n══════════════════════════════════════════');
console.log('  azadi.wtf — Health Check (v2)');
console.log('  ' + new Date().toISOString());
console.log('══════════════════════════════════════════\n');

// ═══════════════════════════════════════════
// 1. SEARCH SOURCES (all four + DeepSeek)
// ═══════════════════════════════════════════
console.log('── Search Sources (fallback chain: TinyFish → Tavily → Firecrawl → SerpAI) ──');

test('TinyFish (MCP search)', function (done) {
  var started = Date.now();
  var init = JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2024-11-05', capabilities: {}, clientInfo: { name: 'healthcheck', version: '2.0' } } });
  request({
    hostname: 'agent.tinyfish.ai', path: '/mcp', method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream', 'Authorization': 'Bearer ' + TINYFISH_KEY },
    payload: init, timeout: 25000
  }, function (err, r) {
    if (err) { fail('TinyFish', 'connection failed'); SEARCH.TinyFish = 'DOWN'; done(); return; }
    var sid = (r.headers && (r.headers['mcp-session-id'] || r.headers['Mcp-Session-Id'])) || '';
    if (r.status !== 200 || !sid) { fail('TinyFish', 'init HTTP ' + r.status); SEARCH.TinyFish = 'INIT HTTP ' + r.status; done(); return; }
    var search = JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'search', arguments: { query: 'Delhi protest news', domain_type: 'news', location: 'IN' } } });
    request({
      hostname: 'agent.tinyfish.ai', path: '/mcp', method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream', 'Authorization': 'Bearer ' + TINYFISH_KEY, 'Mcp-Session-Id': sid },
      payload: search, timeout: 25000
    }, function (err2, r2) {
      var ms = Date.now() - started;
      if (err2) { fail('TinyFish', 'search failed'); SEARCH.TinyFish = 'DOWN'; done(); return; }
      if (r2.status !== 200) { fail('TinyFish', 'search HTTP ' + r2.status); SEARCH.TinyFish = 'HTTP ' + r2.status; done(); return; }
      try {
        var d = JSON.parse(r2.body);
        var text = (d.result && d.result.content || []).filter(function (b) { return b.type === 'text'; }).map(function (b) { return b.text; }).join('');
        var inner = JSON.parse(text);
        var n = (inner.results || []).length;
        if (n > 0) { pass('TinyFish', n + ' results in ' + ms + 'ms'); SEARCH.TinyFish = 'OK'; }
        else { warn('TinyFish', '0 results'); SEARCH.TinyFish = 'OK (0 results)'; }
      } catch (e) { fail('TinyFish', 'parse error'); SEARCH.TinyFish = 'PARSE-ERROR'; }
      done();
    });
  });
});

test('Firecrawl (search)', function (done) {
  var started = Date.now();
  postJson('api.firecrawl.dev', '/v1/search', { 'Authorization': 'Bearer ' + FIRECRAWL_KEY },
    JSON.stringify({ query: 'Delhi protest news', limit: 5 }), function (err, r) {
      if (err) { fail('Firecrawl', 'connection failed'); SEARCH.Firecrawl = 'DOWN'; done(); return; }
      if (r.status !== 200) { fail('Firecrawl', 'HTTP ' + r.status); SEARCH.Firecrawl = 'HTTP ' + r.status; done(); return; }
      try {
        var d = JSON.parse(r.body);
        var n = (d.data || []).length;
        if (n > 0) { pass('Firecrawl', n + ' results in ' + r.ms + 'ms'); SEARCH.Firecrawl = 'OK'; }
        else { warn('Firecrawl', '0 results'); SEARCH.Firecrawl = 'OK (0 results)'; }
      } catch (e) { fail('Firecrawl', 'parse error'); SEARCH.Firecrawl = 'PARSE-ERROR'; }
      done();
    });
});

test('SerpAPI (search)', function (done) {
  var started = Date.now();
  var q = encodeURIComponent('Delhi protest news');
  request({
    hostname: 'serpapi.com', path: '/search.json?api_key=' + SERPAI_KEY + '&q=' + q + '&num=5&gl=in&hl=en&engine=google', method: 'GET', timeout: 15000
  }, function (err, r) {
    if (err) { fail('SerpAPI', 'connection failed'); SEARCH.SerpAPI = 'DOWN'; done(); return; }
    if (r.status !== 200) { fail('SerpAPI', 'HTTP ' + r.status + ' — ' + r.body.substring(0, 80)); SEARCH.SerpAPI = 'HTTP ' + r.status; done(); return; }
    try {
      var d = JSON.parse(r.body);
      var n = (d.organic_results || []).length;
      if (n > 0) { pass('SerpAPI', n + ' results in ' + r.ms + 'ms'); SEARCH.SerpAPI = 'OK'; }
      else { warn('SerpAPI', '0 results'); SEARCH.SerpAPI = 'OK (0 results)'; }
    } catch (e) { fail('SerpAPI', 'parse error'); SEARCH.SerpAPI = 'PARSE-ERROR'; }
    done();
  });
});

test('Tavily (search)', function (done) {
  var started = Date.now();
  postJson('api.tavily.com', '/search', {},
    JSON.stringify({ api_key: TAVILY_KEY, query: 'Delhi protest news', max_results: 3 }), function (err, r) {
      if (err) { fail('Tavily', 'connection failed'); SEARCH.Tavily = 'DOWN'; done(); return; }
      if (r.status !== 200) { fail('Tavily', 'HTTP ' + r.status + ' — ' + r.body.substring(0, 120)); SEARCH.Tavily = 'HTTP ' + r.status; done(); return; }
      try {
        var d = JSON.parse(r.body);
        var n = (d.results || []).length;
        if (n > 0) { pass('Tavily', n + ' results in ' + r.ms + 'ms'); SEARCH.Tavily = 'OK'; }
        else { warn('Tavily', '0 results'); SEARCH.Tavily = 'OK (0 results)'; }
      } catch (e) { fail('Tavily', 'parse error'); SEARCH.Tavily = 'PARSE-ERROR'; }
      done();
    });
});

test('DeepSeek (analysis, model ' + DEEPSEEK_MODEL + ')', function (done) {
  var started = Date.now();
  postJson('api.deepseek.com', '/v1/chat/completions', { 'Authorization': 'Bearer ' + DEEPSEEK_KEY },
    JSON.stringify({ model: DEEPSEEK_MODEL, messages: [{ role: 'user', content: 'Reply with exactly: OK' }], max_tokens: 20 }), function (err, r) {
      if (err) { fail('DeepSeek', 'connection failed'); SEARCH.DeepSeek = 'DOWN'; done(); return; }
      if (r.status !== 200) { fail('DeepSeek', 'HTTP ' + r.status); SEARCH.DeepSeek = 'HTTP ' + r.status; done(); return; }
      try {
        var d = JSON.parse(r.body);
        var c = d.choices && d.choices[0] && d.choices[0].message ? (d.choices[0].message.content || '') : '';
        var model = d.model || '(unknown)';
        if (c.length > 0) { pass('DeepSeek', 'model ' + model + ' responded in ' + r.ms + 'ms'); SEARCH.DeepSeek = 'OK'; }
        else { warn('DeepSeek', 'empty content (model ' + model + ')'); SEARCH.DeepSeek = 'OK (empty content)'; }
      } catch (e) { fail('DeepSeek', 'parse error'); SEARCH.DeepSeek = 'PARSE-ERROR'; }
      done();
    });
});

// ═══════════════════════════════════════════
// 2. PAGES
// ═══════════════════════════════════════════
console.log('── Page Availability ──');

var pages = [
  { path: '/', name: 'Homepage' },
  { path: '/guide.html', name: 'Protest Guide' },
  { path: '/history.html', name: 'Historical Movements' },
  { path: '/checklist.html', name: 'Checklist' },
  { path: '/state.html?code=DL', name: 'State Page (Delhi)' },
  { path: '/Protest%20Safely.pdf', name: 'PDF Download' },
  { path: '/admin/index.php', name: 'Admin Panel' },
  { path: '/api/protests.php', name: 'API Endpoint' },
];

pages.forEach(function (p) {
  test(p.name, function (done) {
    httpGet(BASE + p.path, function (err, code) {
      if (err) fail(p.name, 'connection error');
      else if (code === 200) pass(p.name, '200 OK');
      else fail(p.name, 'HTTP ' + code);
      done();
    });
  });
});

// ═══════════════════════════════════════════
// 3. SECURITY
// ═══════════════════════════════════════════
console.log('── Security ──');

var secChecks = [
  { path: '/config.php', name: 'config.php blocked' },
  { path: '/data/protests.json', name: 'protests.json blocked' },
  { path: '/data/auth.json', name: 'auth.json blocked' },
  { path: '/cron/update.php', name: 'cron blocked w/o secret' },
];

secChecks.forEach(function (s) {
  test(s.name, function (done) {
    httpGet(BASE + s.path, function (err, code, body) {
      if (err || code === 403 || code === 404 || (code === 200 && body.length < 10)) pass(s.name);
      else fail(s.name, 'HTTP ' + code + ' — ' + body.length + ' bytes exposed');
      done();
    });
  });
});

// ═══════════════════════════════════════════
// 4. CRON FUNCTIONALITY
// ═══════════════════════════════════════════
console.log('── Cron Jobs ──');

test('update.php (Delhi only, dry run)', function (done) {
  httpGet(BASE + '/cron/update.php?secret=' + SECRET + '&city=delhi', function (err, code, body) {
    if (err) { fail('update.php', 'connection error'); done(); return; }
    if (body.indexOf('Done') >= 0) pass('update.php', 'ran successfully');
    else fail('update.php', body.substring(0, 80));
    done();
  });
});

// ═══════════════════════════════════════════
// 5. DATA INTEGRITY
// ═══════════════════════════════════════════
console.log('── Data Integrity ──');

test('protests.json freshness', function (done) {
  httpGet(BASE + '/api/protests.php', function (err, code, body) {
    if (err) { fail('protests.json', 'connection error'); done(); return; }
    try {
      var d = JSON.parse(body);
      var updated = new Date(d.lastUpdated);
      var hoursAgo = (Date.now() - updated.getTime()) / 3600000;
      if (hoursAgo < 6) pass('protests.json', Math.round(hoursAgo * 60) + 'min ago — fresh');
      else if (hoursAgo < 12) warn('protests.json', Math.round(hoursAgo) + 'h ago — acceptable');
      else fail('protests.json', Math.round(hoursAgo) + 'h ago — stale');
    } catch (e) { fail('protests.json', 'invalid JSON'); }
    done();
  });
});

test('Per-protest staleness (>7d)', function (done) {
  httpGet(BASE + '/api/protests.php', function (err, code, body) {
    if (err || code !== 200) { fail('Per-protest staleness', 'API error'); done(); return; }
    try {
      var d = JSON.parse(body);
      var stale = 0, total = 0;
      Object.keys(d.cities || {}).forEach(function (slug) {
        (d.cities[slug].protests || []).forEach(function (p) {
          total++;
          if (p.lastUpdated) {
            var ageDays = (Date.now() - new Date(p.lastUpdated).getTime()) / 86400000;
            if (ageDays > 7) stale++;
          }
        });
      });
      if (stale === 0) pass('Per-protest staleness', '0/' + total + ' stale (>7d)');
      else warn('Per-protest staleness', stale + '/' + total + ' stale (>7d)');
    } catch (e) { fail('Per-protest staleness', 'parse error'); }
    done();
  });
});

test('State coverage (all 37 states/UTs)', function (done) {
  httpGet(BASE + '/api/protests.php', function (err, code, body) {
    if (err || code !== 200) { fail('State coverage', 'API error'); done(); return; }
    try {
      var d = JSON.parse(body);
      var cities = d.cities ? Object.keys(d.cities) : [];
      var withProtest = cities.filter(function (c) { return (d.cities[c].protests || []).length > 0; }).length;
      if (cities.length >= 37) pass('State coverage', cities.length + ' states/UTs (' + withProtest + ' with active protests)');
      else if (cities.length >= 28) warn('State coverage', cities.length + ' states/UTs (expected 37)');
      else fail('State coverage', 'only ' + cities.length + ' states/UTs');
    } catch (e) { fail('State coverage', 'parse error'); }
    done();
  });
});

test('data.js has news links', function (done) {
  httpGet(BASE + '/data.js', function (err, code, body) {
    if (err) { fail('data.js', 'connection error'); done(); return; }
    var totalCount = (body.match(/"type"\s*:\s*"article"/g) || []).length;
    if (totalCount > 50) pass('data.js news links', totalCount + ' articles');
    else if (totalCount > 0) warn('data.js news links', 'only ' + totalCount + ' articles');
    else fail('data.js news links', 'no articles found');
    done();
  });
});

test('News URL validity (6 states)', function (done) {
  var sample = ['DL', 'MH', 'KA', 'WB', 'TN', 'MN'];
  var pending = sample.length, ok = 0, total = 0;
  httpGet(BASE + '/data.js', function (err, code, body) {
    if (err || code !== 200) { fail('News URLs', 'cannot fetch data.js'); done(); return; }
    function finish() {
      if (total === 0) { warn('News URLs', 'regex pattern mismatch'); done(); return; }
      if (ok === total) pass('News URLs (' + ok + '/' + total + ')', 'all accessible');
      else if (ok >= total - 2) warn('News URLs (' + ok + '/' + total + ')', 'some paywalled');
      else fail('News URLs (' + ok + '/' + total + ')', 'multiple broken');
      done();
    }
    sample.forEach(function (sc) {
      var re = new RegExp('"' + sc + '":\\s*\\{[^}]*?"news":\\s*\\[[^\\]]*?"url"\\s*:\\s*"([^"]+)"');
      var m = body.match(re);
      if (m) { total++; httpGet(m[1], function (e, c) { if (!e && c >= 200 && c < 400) ok++; pending--; if (!pending) finish(); }); }
      else { pending--; if (!pending) finish(); }
    });
  });
});

test('State data (6-state sample)', function (done) {
  var sampleStates = ['DL', 'MH', 'KA', 'WB', 'TN', 'MN'];
  var citySlugs = { DL: 'delhi', MH: 'mumbai', KA: 'bangalore', WB: 'kolkata', TN: 'chennai', MN: 'imphal' };
  var pending = sampleStates.length, passed = 0;
  sampleStates.forEach(function (code) {
    httpGet(BASE + '/api/protests.php?city=' + citySlugs[code], function (err, respCode, body) {
      if (!err && respCode === 200) {
        try {
          var d = JSON.parse(body);
          var ok = d.protests && d.protests.length > 0 &&
            d.protests.every(function (p) { return p.dangerLevel && p.status && p.details; });
          if (ok) passed++;
        } catch (e) {}
      }
      pending--;
      if (pending === 0) {
        if (passed === sampleStates.length) pass('State data (' + passed + '/' + sampleStates.length + ')', 'all states OK');
        else if (passed >= 4) warn('State data (' + passed + '/' + sampleStates.length + ')', 'some states have gaps');
        else fail('State data (' + passed + '/' + sampleStates.length + ')', 'multiple states missing data');
        done();
      }
    });
  });
});

// ═══════════════════════════════════════════
// 6. LOCAL FILES
// ═══════════════════════════════════════════
console.log('── Local Files ──');

var localFiles = [
  'index.html', 'guide.html', 'state.html', 'history.html', 'checklist.html',
  'data.js', 'style.css', 'cron/run.php', 'cron/update.php', 'cron/verify_news.js'
];

localFiles.forEach(function (f) {
  test('Local: ' + f, function (done) {
    var fp = path.join(__dirname, '..', f);
    if (fs.existsSync(fp)) pass('Local: ' + f, fs.statSync(fp).size + ' bytes');
    else fail('Local: ' + f, 'file missing');
    done();
  });
});

// START
runTests(0);
