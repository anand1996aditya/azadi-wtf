#!/usr/bin/env node
/**
 * News Link Verification + Auto-Fix (Node.js version)
 * Checks news URLs across states. Replaces broken states with Tavily results.
 * Runs every 12 hours via run.php.
 */

var fs = require('fs');
var https = require('https');
var http = require('http');
var exec = require('child_process').execSync;

// Load data
var DATA_FILE = __dirname + '/../data.js';
var LOG_FILE = __dirname + '/../data/news_verify.log';

eval(fs.readFileSync(DATA_FILE, 'utf8'));

// API keys — loaded from gitignored cron/keys.json (see keys.example.json)
var KEYS = {};
try { KEYS = JSON.parse(fs.readFileSync(__dirname + '/keys.json', 'utf8')); } catch (e) {}
var TAVILY_KEY = process.env.TAVILY_KEY || KEYS.TAVILY || '';
var FIRECRAWL_KEY = process.env.FIRECRAWL_KEY || KEYS.FIRECRAWL || '';
var TINYFISH_KEY = process.env.TINYFISH_KEY || KEYS.TINYFISH || '';
var SERPAI_KEY = process.env.SERPAI_KEY || KEYS.SERPAI || '';

function vlog(msg) {
  var entry = '[' + new Date().toISOString() + '] ' + msg;
  fs.appendFileSync(LOG_FILE, entry + '\n');
  console.log(msg);
}

function checkUrl(url, callback) {
  var mod = url.startsWith('https') ? https : http;
  var req = mod.get(url, {
    headers: { 'User-Agent': 'Mozilla/5.0 (compatible; azadi.wtf/1.0)' },
    timeout: 8000
  }, function(res) {
    res.resume();
    callback(res.statusCode >= 200 && res.statusCode < 400);
  });
  req.on('error', function() { callback(false); });
  req.on('timeout', function() { req.destroy(); callback(false); });
}

function firecrawlSearch(query, callback) {
  var payload = JSON.stringify({ query: query, limit: 6 });
  var req = https.request({
    hostname: 'api.firecrawl.dev', path: '/v1/search', method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + FIRECRAWL_KEY, 'Content-Length': payload.length },
    timeout: 15000
  }, function(res) {
    var body = '';
    res.on('data', function(d) { body += d; });
    res.on('end', function() {
      try {
        var d = JSON.parse(body);
        callback((d.data || []).map(function(r) { return { title: r.title, url: r.url, content: r.description || '' }; }));
      } catch(e) { callback([]); }
    });
  });
  req.on('error', function() { callback([]); });
  req.on('timeout', function() { req.destroy(); callback([]); });
  req.write(payload); req.end();
}

function tinyfishSearch(query, callback) {
  var init = JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2024-11-05', capabilities: {}, clientInfo: { name: 'verify_news', version: '3.0' } } });
  var req = https.request({
    hostname: 'agent.tinyfish.ai', path: '/mcp', method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream', 'Authorization': 'Bearer ' + TINYFISH_KEY, 'Content-Length': init.length },
    timeout: 15000
  }, function(res) {
    var body = '';
    res.on('data', function(d) { body += d; });
    res.on('end', function() {
      var sid = res.headers['mcp-session-id'];
      if (!sid) { callback([]); return; }
      var search = JSON.stringify({ jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'search', arguments: { query: query, domain_type: 'news', location: 'IN' } } });
      var req2 = https.request({
        hostname: 'agent.tinyfish.ai', path: '/mcp', method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream', 'Authorization': 'Bearer ' + TINYFISH_KEY, 'Mcp-Session-Id': sid, 'Content-Length': search.length },
        timeout: 15000
      }, function(res2) {
        var body2 = '';
        res2.on('data', function(d) { body2 += d; });
        res2.on('end', function() {
          try {
            var d = JSON.parse(body2);
            var text = (d.result && d.result.content || []).filter(function(b){return b.type === 'text';}).map(function(b){return b.text;}).join('');
            var inner = JSON.parse(text);
            callback((inner.results || []).map(function(r) { return { title: r.title, url: r.url, content: r.snippet || '' }; }));
          } catch(e) { callback([]); }
        });
      });
      req2.on('error', function() { callback([]); });
      req2.on('timeout', function() { req2.destroy(); callback([]); });
      req2.write(search); req2.end();
    });
  });
  req.on('error', function() { callback([]); });
  req.on('timeout', function() { req.destroy(); callback([]); });
  req.write(init); req.end();
}

function serpaiSearch(query, callback) {
  var q = encodeURIComponent(query);
  var req = https.get('https://serpapi.com/search.json?api_key=' + SERPAI_KEY + '&q=' + q + '&num=6&gl=in&hl=en&engine=google', { timeout: 15000 }, function(res) {
    var body = '';
    res.on('data', function(d) { body += d; });
    res.on('end', function() {
      try {
        var d = JSON.parse(body);
        callback((d.organic_results || []).map(function(r) { return { title: r.title, url: r.link, content: r.snippet || '' }; }));
      } catch(e) { callback([]); }
    });
  });
  req.on('error', function() { callback([]); });
  req.on('timeout', function() { req.destroy(); callback([]); });
}

function tavilySearch(query, callback) {
  var payload = JSON.stringify({ api_key: TAVILY_KEY, query: query, search_depth: 'basic', include_answer: false, max_results: 6 });
  var req = https.request({
    hostname: 'api.tavily.com', path: '/search', method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Content-Length': payload.length },
    timeout: 15000
  }, function(res) {
    var body = '';
    res.on('data', function(d) { body += d; });
    res.on('end', function() {
      try {
        var d = JSON.parse(body);
        callback((d.results || []).map(function(r) { return { title: r.title, url: r.url, content: r.content || '' }; }));
      } catch(e) { callback([]); }
    });
  });
  req.on('error', function() { callback([]); });
  req.on('timeout', function() { req.destroy(); callback([]); });
  req.write(payload); req.end();
}

function searchWeb(query, callback) {
  // Cost-effective: TinyFish (free) → Tavily → Firecrawl → SerpAI (most expensive)
  tinyfishSearch(query, function(tf) {
    if (tf.length > 0) { callback(tf); return; }
    tavilySearch(query, function(tv) {
      if (tv.length > 0) { callback(tv); return; }
      firecrawlSearch(query, function(fr) {
        if (fr.length > 0) { callback(fr); return; }
        serpaiSearch(query, callback);
      });
    });
  });
}

vlog('=== NEWS VERIFICATION STARTED ===');

var states = Object.keys(STATE_DATA);
var totalChecked = 0, totalBroken = 0, totalReplaced = 0;
var pending = states.length;

states.forEach(function(code) {
  var state = STATE_DATA[code];
  if (!state.news || state.news.length === 0) { pending--; return; }
  
  var sample = state.news.slice(0, Math.min(3, state.news.length));
  var broken = 0;
  var done = 0;
  
  sample.forEach(function(item) {
    checkUrl(item.url, function(ok) {
      if (!ok) broken++;
      done++;
      if (done < sample.length) return;
      
      var pct = Math.round((broken / sample.length) * 100);
      
      if (pct >= 67) {
        vlog('  ✗ ' + state.name + ' — ' + broken + '/' + sample.length + ' broken. Replacing...');
        searchWeb(state.name + ' protest latest news 2026', function(results) {
          if (results.length >= 3) {
            STATE_DATA[code].news = results.map(function(r) {
              var u = r.url || '';
              var domain = u.replace('https://','').replace('http://','').split('/')[0];
              var source = 'News Source';
              if (domain.indexOf('thehindu.com') >= 0) source = 'The Hindu';
              else if (domain.indexOf('indianexpress.com') >= 0) source = 'Indian Express';
              else if (domain.indexOf('bbc.com') >= 0) source = 'BBC';
              else if (domain.indexOf('aljazeera.com') >= 0) source = 'Al Jazeera';
              else if (domain.indexOf('ndtv.com') >= 0) source = 'NDTV';
              else if (domain.indexOf('timesofindia') >= 0) source = 'Times of India';
              else if (domain.indexOf('youtube.com') >= 0) source = 'YouTube';
              else if (domain.indexOf('instagram.com') >= 0) source = 'Instagram';
              else if (domain.indexOf('cnn.com') >= 0) source = 'CNN';
              else if (domain.indexOf('washingtonpost.com') >= 0) source = 'Washington Post';
              else if (domain.indexOf('hrw.org') >= 0) source = 'Human Rights Watch';
              else if (domain.indexOf('nytimes.com') >= 0) source = 'New York Times';
              
              var type = 'article';
              if (u.indexOf('youtube.com') >= 0) type = 'video';
              if (u.indexOf('instagram.com') >= 0) type = 'social';
              
              return {
                title: (r.title || '').substring(0, 120),
                url: u,
                source: source,
                snippet: (r.content || '').substring(0, 180),
                type: type
              };
            });
            totalReplaced++;
            vlog('    Replaced with ' + results.length + ' search links');
          }
          totalBroken++;
          finishState();
        });
      } else if (pct > 0) {
        vlog('  ~ ' + state.name + ' — ' + broken + '/' + sample.length + ' broken (within tolerance)');
        finishState();
      } else {
        vlog('  ✓ ' + state.name + ' — OK');
        finishState();
      }
    });
  });
});

function finishState() {
  pending--;
  if (pending === 0) {
    if (totalReplaced > 0) {
      var output = '/**\n * India Protest Map — State Data (Rich Format)\n */\nvar STATE_DATA = ' + JSON.stringify(STATE_DATA, null, 2) + ';\n';
      fs.writeFileSync(DATA_FILE, output);
      vlog('SAVED: ' + totalReplaced + ' states updated');
    }
    vlog('=== COMPLETE: ' + totalChecked + ' checked, ' + totalBroken + ' had broken links, ' + totalReplaced + ' fixed ===\n');
  }
}
