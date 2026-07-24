<?php
/**
 * Admin Panel — Manage Protest Data
 * Single-file admin with login and dashboard
 */
session_start();

define('DATA_DIR', __DIR__ . '/../data/');
define('PROTESTS_FILE', DATA_DIR . 'protests.json');
define('AUTH_FILE', DATA_DIR . 'auth.json');

// --- Auth Helper ---
function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function verifyPassword($input) {
    $auth = json_decode(file_get_contents(AUTH_FILE), true);
    if (!$auth || !isset($auth['password'])) return false;
    return password_verify($input, $auth['password']);
}

function hashPassword($plain) {
    return password_hash($plain, PASSWORD_BCRYPT);
}

function loadProtests() {
    if (!file_exists(PROTESTS_FILE)) return null;
    $data = json_decode(file_get_contents(PROTESTS_FILE), true);
    return $data;
}

function saveProtests($data) {
    $data['lastUpdated'] = date('c');
    return file_put_contents(
        PROTESTS_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

// --- Routing ---
$action = $_GET['action'] ?? ($_POST['action'] ?? 'dashboard');

// Handle login
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (verifyPassword($password)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid password.';
    }
}

// Handle logout
if ($action === 'logout') {
    session_destroy();
    header('Location: index.php');
    exit;
}

// Handle password change
if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn()) {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $pwError = 'Password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $pwError = 'Passwords do not match.';
    } else {
        $auth = json_decode(file_get_contents(AUTH_FILE), true);
        $auth['password'] = hashPassword($newPassword);
        file_put_contents(AUTH_FILE, json_encode($auth, JSON_PRETTY_PRINT));
        $pwSuccess = 'Password changed successfully.';
    }
}

// Handle data save
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST' && isLoggedIn()) {
    $jsonData = $_POST['json_data'] ?? '';
    $decoded = json_decode($jsonData, true);

    if ($decoded === null) {
        $saveError = 'Invalid JSON: ' . json_last_error_msg();
    } else {
        $decoded['lastUpdated'] = date('c');
        $result = file_put_contents(
            PROTESTS_FILE,
            json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        if ($result !== false) {
            $saveSuccess = 'Data saved successfully.';
        } else {
            $saveError = 'Failed to write file. Check permissions.';
        }
    }
}

// --- Render ---
$protests = loadProtests();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — Protest Site</title>
<style>
  :root {
    --bg: #0a0a0a;
    --bg-card: #141414;
    --bg-input: #1a1a1a;
    --border: #2a2a2a;
    --text: #e0e0e0;
    --text-dim: #888;
    --accent: #ff4d3a;
    --green: #2ecc71;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .admin-container { width: 100%; max-width: 600px; padding: 24px; }
  .admin-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: 40px; }
  h1 { font-size: 1.5rem; margin-bottom: 8px; }
  h2 { font-size: 1.1rem; margin-bottom: 20px; color: var(--text-dim); }
  .subtitle { color: var(--text-dim); font-size: 0.85rem; margin-bottom: 24px; }

  input[type="password"], textarea {
    width: 100%;
    padding: 12px 16px;
    background: var(--bg-input);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-family: 'SF Mono', 'Fira Code', monospace;
    font-size: 0.9rem;
    margin-bottom: 12px;
  }
  input:focus, textarea:focus { outline: none; border-color: var(--accent); }
  textarea { min-height: 400px; resize: vertical; line-height: 1.5; }

  .btn {
    display: inline-block;
    padding: 12px 28px;
    font-size: 0.9rem;
    font-weight: 600;
    border-radius: 8px;
    cursor: pointer;
    border: none;
    transition: all 0.2s ease;
    width: 100%;
    text-align: center;
  }
  .btn-primary { background: var(--accent); color: #fff; }
  .btn-primary:hover { background: #e04435; }
  .btn-secondary { background: var(--bg-input); color: var(--text); border: 1px solid var(--border); margin-top: 8px; }
  .btn-secondary:hover { border-color: var(--accent); }
  .btn-danger { background: transparent; color: var(--accent); border: 1px solid var(--accent); margin-top: 8px; }
  .btn-danger:hover { background: var(--accent); color: #fff; }
  .btn-small { padding: 8px 16px; font-size: 0.8rem; width: auto; }

  .error { background: rgba(255,77,58,0.1); border: 1px solid rgba(255,77,58,0.3); color: #ff6b5b; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; }
  .success { background: rgba(46,204,113,0.1); border: 1px solid rgba(46,204,113,0.3); color: #3dd68c; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.85rem; }

  .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
  .top-bar .actions { display: flex; gap: 8px; }

  .info-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
  .info-row:last-child { border: none; }
  .info-label { color: var(--text-dim); }

  .section-divider { border: none; border-top: 1px solid var(--border); margin: 24px 0; }
  .label { display: block; font-size: 0.8rem; color: var(--text-dim); margin-bottom: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

  details { margin-bottom: 8px; }
  summary { cursor: pointer; color: var(--text-dim); font-size: 0.85rem; padding: 8px 0; }
  summary:hover { color: var(--text); }
</style>
</head>
<body>
<div class="admin-container">

<?php if (!isLoggedIn()): ?>
  <!-- LOGIN FORM -->
  <div class="admin-card">
    <h1>&#128274; Admin Login</h1>
    <p class="subtitle">Enter the master password to manage protest data.</p>
    <?php if (isset($error)): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="POST" action="?action=login">
      <label class="label" for="password">Master Password</label>
      <input type="password" id="password" name="password" placeholder="Enter password..." required autofocus>
      <button type="submit" class="btn btn-primary">Sign In</button>
    </form>

  </div>

<?php else: ?>
  <!-- DASHBOARD -->
  <div class="admin-card" style="max-width:800px;">
    <div class="top-bar">
      <div>
        <h1>&#9881; Admin Dashboard</h1>
        <p class="subtitle" style="margin-bottom:0;">Manage protest data across all cities</p>
      </div>
      <div class="actions">
        <a href="?action=logout" class="btn btn-danger btn-small">Logout</a>
      </div>
    </div>

    <?php if (isset($saveSuccess)): ?>
      <div class="success"><?php echo htmlspecialchars($saveSuccess); ?></div>
    <?php endif; ?>
    <?php if (isset($saveError)): ?>
      <div class="error"><?php echo htmlspecialchars($saveError); ?></div>
    <?php endif; ?>
    <?php if (isset($pwSuccess)): ?>
      <div class="success"><?php echo htmlspecialchars($pwSuccess); ?></div>
    <?php endif; ?>
    <?php if (isset($pwError)): ?>
      <div class="error"><?php echo htmlspecialchars($pwError); ?></div>
    <?php endif; ?>

    <!-- Data Stats -->
    <div style="margin-bottom:20px;">
      <div class="info-row">
        <span class="info-label">Cities tracked</span>
        <span><?php echo $protests ? count($protests['cities']) : 0; ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Total protests</span>
        <span><?php
          $count = 0;
          if ($protests) {
            foreach ($protests['cities'] as $city) {
              $count += count($city['protests']);
            }
          }
          echo $count;
        ?></span>
      </div>
      <div class="info-row">
        <span class="info-label">Last updated</span>
        <span><?php echo $protests ? htmlspecialchars($protests['lastUpdated'] ?? 'Unknown') : 'N/A'; ?></span>
      </div>
    </div>

    <!-- JSON Editor -->
    <form method="POST" action="?action=save">
      <label class="label" for="json_data">Edit Protest Data (JSON)</label>
      <textarea id="json_data" name="json_data" spellcheck="false"><?php
        echo htmlspecialchars(json_encode($protests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      ?></textarea>
      <div style="display:flex;gap:8px;">
        <button type="submit" class="btn btn-primary" style="flex:1;">&#128190; Save Changes</button>
        <button type="button" class="btn btn-secondary" style="flex:1;" onclick="if(confirm('Reload data from file? Unsaved changes will be lost.')){location.reload();}">&#8634; Reload</button>
      </div>
    </form>

    <hr class="section-divider">

    <!-- Password Change -->
    <details>
      <summary>Change Admin Password</summary>
      <form method="POST" action="?action=change_password" style="margin-top:12px;">
        <label class="label" for="new_password">New Password (min 8 chars)</label>
        <input type="password" id="new_password" name="new_password" placeholder="New password..." required minlength="8">
        <label class="label" for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password..." required minlength="8">
        <button type="submit" class="btn btn-secondary">Update Password</button>
      </form>
    </details>

    <!-- JSON Structure Guide -->
    <details>
      <summary>JSON Structure Reference</summary>
      <pre style="background:var(--bg-input);padding:16px;border-radius:8px;overflow-x:auto;font-size:0.75rem;color:var(--text-dim);margin-top:12px;line-height:1.6;">
{
  "lastUpdated": "ISO 8601 timestamp",
  "cities": {
    "cityslug": {
      "name": "City Name",
      "slug": "cityslug",
      "overallStatus": "One-line overview",
      "overallDanger": "critical|high|moderate|low",
      "protests": [
        {
          "id": "unique-id",
          "name": "Protest Name",
          "location": "Specific location in city",
          "cause": "What the protest is about",
          "dangerLevel": "critical|high|moderate|low",
          "dangerReason": "Why this danger level was assigned",
          "status": "Current status description",
          "details": ["Array of bullet points"],
          "lastUpdated": "ISO 8601 timestamp"
        }
      ]
    }
  }
}</pre>
    </details>
  </div>
<?php endif; ?>

</div>
</body>
</html>
