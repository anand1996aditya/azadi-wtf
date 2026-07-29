#!/usr/bin/env node
/**
 * News Link Verification + Auto-Fix (Node.js version)
 * Checks news URLs across states. Replaces broken states with Tavily results.
 * Runs every 3 hours via run.php.
 */

var fs = require('fs');
var https = require('https');
var http = require('http');
var exec = require('child_process').execSync;

// Load data
var DATA_FILE = __dirname + '/../data.js';
var LOG_FILE = __dirname + '/../data/news_verify.log';

eval(fs.readFileSync(DATA_FILE, 'utf8'));

var TAVILY_KEY = process.env.TAVILY_KEY || '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';

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

function searchTavily(stateName, callback) {
  try {
    var payload = JSON.stringify({
      api_key: TAVILY_KEY,
      query: stateName + ' protest latest news 2026',
      search_depth: 'basic',
      include_answer: false,
      max_results: 6
    });
    var resp = exec('curl -s -X POST https://api.tavily.com/search -H "Content-Type: application/json" -d \'' + payload.replace(/'/g, "'\\''") + '\'', {timeout: 15000}).toString();
    var data = JSON.parse(resp);
    callback((data.results || []).slice(0, 6));
  } catch(e) {
    callback([]);
  }
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
        searchTavily(state.name, function(results) {
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
            vlog('    Replaced with ' + results.length + ' Tavily links');
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
