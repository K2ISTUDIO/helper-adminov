<?php
// ============================================================
//  CONFIGURATION
// ============================================================
define('PH_API_USER',  'ca090d4c75f80521945812f3968b3df9');
define('PH_API_KEY',   'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859');
define('MAIL_DOMAIN',  'neomails.fr');
define('APP_PASSWORD', 'S@rix93100');
define('APP_TITLE',    'Adminov — Emails neomails.fr');
define('PH_API_BASE',  'https://api.planethoster.net/v3');
// ============================================================

session_start();

// ─── Auth ─────────────────────────────────────────────────
$auth_error = '';
if (isset($_POST['app_login'])) {
    if ($_POST['app_password'] === APP_PASSWORD) {
        $_SESSION['auth'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $auth_error = 'Mot de passe incorrect.';
}
if (isset($_POST['app_logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}
$authenticated = !empty($_SESSION['auth']);

// ─── Client API PlanetHoster ──────────────────────────────
function ph_request(string $method, string $path, array $body = []): array
{
    $url = PH_API_BASE . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'X-API-USER: '    . PH_API_USER,
            'X-API-KEY: '     . PH_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'http' => 0, 'error' => "cURL ($errno): $error", 'data' => []];
    }
    $data = json_decode($response, true) ?? [];
    $ok   = $http >= 200 && $http < 300;
    $msg  = '';
    if (!$ok) {
        $msg = $data['message'] ?? $data['error'] ?? $data['msg'] ?? "Erreur HTTP $http.";
        if (is_array($msg)) $msg = implode(' ', $msg);
    }
    return ['ok' => $ok, 'http' => $http, 'error' => $msg, 'data' => $data];
}

// ─── Actions POST ─────────────────────────────────────────
$flash = ['type' => '', 'msg' => ''];

if ($authenticated) {

    // Créer un email
    if (($_POST['action'] ?? '') === 'create') {
        $prefix   = trim($_POST['prefix']   ?? '');
        $password = trim($_POST['password'] ?? '');
        $quota    = max(0, (int)($_POST['quota'] ?? 250));

        if (!$prefix || !$password) {
            $flash = ['type' => 'danger', 'msg' => 'Le préfixe et le mot de passe sont obligatoires.'];
        } elseif (!preg_match('/^[a-zA-Z0-9._+\-]+$/', $prefix)) {
            $flash = ['type' => 'danger', 'msg' => 'Préfixe invalide (lettres, chiffres, . _ + - autorisés).'];
        } elseif (strlen($password) < 8) {
            $flash = ['type' => 'danger', 'msg' => 'Mot de passe trop court (8 caractères minimum).'];
        } else {
            $result = ph_request('POST', '/emails', [
                'domain'   => MAIL_DOMAIN,
                'email'    => $prefix,
                'password' => $password,
                'quota'    => $quota,
            ]);
            $flash = $result['ok']
                ? ['type' => 'success', 'msg' => "Adresse <strong>{$prefix}@" . MAIL_DOMAIN . "</strong> créée avec succès."]
                : ['type' => 'danger',  'msg' => 'Erreur API : ' . htmlspecialchars($result['error'])];
        }
    }

    // Supprimer un email
    if (($_POST['action'] ?? '') === 'delete') {
        $prefix = trim($_POST['prefix'] ?? '');
        if (!$prefix) {
            $flash = ['type' => 'danger', 'msg' => 'Paramètre manquant.'];
        } else {
            $result = ph_request('DELETE', '/emails', [
                'domain' => MAIL_DOMAIN,
                'email'  => $prefix,
            ]);
            $flash = $result['ok']
                ? ['type' => 'success', 'msg' => "Adresse <strong>{$prefix}@" . MAIL_DOMAIN . "</strong> supprimée."]
                : ['type' => 'danger',  'msg' => 'Erreur API : ' . htmlspecialchars($result['error'])];
        }
    }

    // Changer le mot de passe
    if (($_POST['action'] ?? '') === 'passwd') {
        $prefix   = trim($_POST['prefix']   ?? '');
        $password = trim($_POST['password'] ?? '');
        if (!$prefix || !$password) {
            $flash = ['type' => 'danger', 'msg' => 'Tous les champs sont obligatoires.'];
        } elseif (strlen($password) < 8) {
            $flash = ['type' => 'danger', 'msg' => 'Mot de passe trop court (8 caractères minimum).'];
        } else {
            $result = ph_request('PUT', '/emails', [
                'domain'   => MAIL_DOMAIN,
                'email'    => $prefix,
                'password' => $password,
            ]);
            $flash = $result['ok']
                ? ['type' => 'success', 'msg' => "Mot de passe de <strong>{$prefix}@" . MAIL_DOMAIN . "</strong> modifié."]
                : ['type' => 'danger',  'msg' => 'Erreur API : ' . htmlspecialchars($result['error'])];
        }
    }

    // Charger la liste des emails
    $list_result = ph_request('GET', '/emails?domain=' . urlencode(MAIL_DOMAIN));
    $accounts    = [];
    if ($list_result['ok']) {
        $raw = $list_result['data'];
        // Normalise selon la structure retournée par l'API
        if (isset($raw['data']) && is_array($raw['data']))        $accounts = $raw['data'];
        elseif (isset($raw['emails']) && is_array($raw['emails'])) $accounts = $raw['emails'];
        elseif (is_array($raw) && isset($raw[0]))                  $accounts = $raw;
    }
}

// ─── Génération mot de passe fort ─────────────────────────
function strong_password(int $len = 16): string
{
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    $out   = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}
$suggested = strong_password();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(APP_TITLE) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root { --brand:#0e6adb; --brand-dark:#0a55b3; --surface:#f4f7ff; }
*, ::before, ::after { box-sizing: border-box; }
body { background:var(--surface); font-family:'Segoe UI',system-ui,sans-serif; min-height:100vh; }

/* ── Login ── */
.login-wrap { display:flex; align-items:center; justify-content:center; min-height:100vh; }
.login-card  { width:100%; max-width:400px; border:none; border-radius:16px;
               box-shadow:0 4px 32px rgba(14,106,219,.15); padding:2.5rem 2rem; background:#fff; }
.login-logo  { font-size:2rem; font-weight:800; color:var(--brand); letter-spacing:-.03em; }
.login-sub   { color:#6b7280; font-size:.9rem; }

/* ── App ── */
.navbar-brand { font-weight:700; font-size:1.1rem; }
.card { border:none; border-radius:14px; box-shadow:0 2px 16px rgba(0,0,0,.07); }
.card-header { border-radius:14px 14px 0 0 !important; font-weight:600; font-size:.95rem; }
.btn-brand { background:var(--brand); border-color:var(--brand); color:#fff; }
.btn-brand:hover { background:var(--brand-dark); border-color:var(--brand-dark); color:#fff; }
.domain-badge { background:rgba(14,106,219,.1); color:var(--brand); border-radius:6px;
                padding:.1rem .55rem; font-size:.82rem; font-weight:600; }
.table th { font-size:.75rem; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; border-bottom-width:1px; }
.table td { vertical-align:middle; }
.email-col { font-family:'Cascadia Code','Fira Code',monospace; font-size:.9rem; }
.quota-badge { font-size:.73rem; }
.copy-icon { opacity:.45; transition:opacity .15s; cursor:pointer; }
.copy-icon:hover { opacity:1; }
.empty-state { padding:3rem 1rem; text-align:center; color:#9ca3af; }
.empty-state i { font-size:2.5rem; display:block; margin-bottom:.75rem; }
.section-label { font-size:.7rem; text-transform:uppercase; letter-spacing:.08em;
                 color:#9ca3af; font-weight:600; margin-bottom:.4rem; }
</style>
</head>
<body>
<?php if (!$authenticated): ?>

<!-- ══════════════════════ LOGIN ══════════════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="text-center mb-4">
      <div class="login-logo"><i class="bi bi-envelope-at-fill"></i> Adminov</div>
      <div class="login-sub mt-1">Gestion des emails <strong><?= htmlspecialchars(MAIL_DOMAIN) ?></strong></div>
    </div>
    <?php if ($auth_error): ?>
    <div class="alert alert-danger py-2 mb-3">
      <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($auth_error) ?>
    </div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
      <div class="mb-3">
        <label class="form-label fw-semibold">Mot de passe</label>
        <input type="password" name="app_password" class="form-control form-control-lg"
               placeholder="••••••••" autofocus required>
      </div>
      <button type="submit" name="app_login" value="1" class="btn btn-brand btn-lg w-100">
        <i class="bi bi-lock-fill me-1"></i>Connexion
      </button>
    </form>
  </div>
</div>

<?php else: ?>

<!-- ══════════════════════ APP ════════════════════════════ -->
<nav class="navbar navbar-dark" style="background:var(--brand); box-shadow:0 2px 8px rgba(14,106,219,.3);">
  <div class="container-xl">
    <span class="navbar-brand">
      <i class="bi bi-envelope-at-fill me-2 opacity-75"></i><?= htmlspecialchars(APP_TITLE) ?>
      <span class="domain-badge ms-2"><?= htmlspecialchars(MAIL_DOMAIN) ?></span>
    </span>
    <form method="post" class="ms-auto">
      <button name="app_logout" value="1" class="btn btn-sm btn-outline-light px-3">
        <i class="bi bi-box-arrow-right me-1"></i>Déconnexion
      </button>
    </form>
  </div>
</nav>

<div class="container-xl py-4">

  <!-- Flash -->
  <?php if ($flash['msg']): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
    <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> flex-shrink-0"></i>
    <div><?= $flash['msg'] ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <div class="row g-4">

    <!-- ──────── Colonne gauche : formulaires ──────── -->
    <div class="col-xl-4 col-lg-5">

      <!-- Créer -->
      <div class="card">
        <div class="card-header bg-primary text-white d-flex align-items-center gap-2">
          <i class="bi bi-plus-circle-fill"></i> Créer une adresse email
        </div>
        <div class="card-body">
          <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="create">

            <div class="mb-3">
              <label class="form-label fw-semibold">Préfixe <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="text" name="prefix" class="form-control" placeholder="contact"
                       pattern="[a-zA-Z0-9._+\-]+" required autofocus>
                <span class="input-group-text text-muted">@<?= htmlspecialchars(MAIL_DOMAIN) ?></span>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Mot de passe <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="text" id="pwd-create" name="password" class="form-control font-monospace"
                       value="<?= htmlspecialchars($suggested) ?>" required minlength="8">
                <button type="button" class="btn btn-outline-secondary" id="regenBtn" title="Régénérer">
                  <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button type="button" class="btn btn-outline-secondary" data-copy="pwd-create" title="Copier">
                  <i class="bi bi-clipboard"></i>
                </button>
              </div>
              <div class="form-text">Minimum 8 caractères</div>
            </div>

            <div class="mb-4">
              <label class="form-label fw-semibold">Quota (Mo)</label>
              <input type="number" name="quota" class="form-control" value="250" min="0" max="51200">
              <div class="form-text">0 = illimité</div>
            </div>

            <button type="submit" class="btn btn-brand w-100 fw-semibold">
              <i class="bi bi-envelope-plus me-1"></i>Créer l'adresse
            </button>
          </form>
        </div>
      </div>

      <!-- Changer mot de passe -->
      <div class="card mt-4">
        <div class="card-header bg-warning text-dark d-flex align-items-center gap-2">
          <i class="bi bi-key-fill"></i> Changer un mot de passe
        </div>
        <div class="card-body">
          <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="passwd">
            <div class="mb-2">
              <div class="input-group">
                <input type="text" name="prefix" class="form-control" placeholder="utilisateur" required>
                <span class="input-group-text text-muted">@<?= htmlspecialchars(MAIL_DOMAIN) ?></span>
              </div>
            </div>
            <div class="mb-3">
              <div class="input-group">
                <input type="text" id="pwd-change" name="password" class="form-control font-monospace"
                       placeholder="Nouveau mot de passe" required minlength="8">
                <button type="button" class="btn btn-outline-secondary" data-copy="pwd-change" title="Copier">
                  <i class="bi bi-clipboard"></i>
                </button>
              </div>
            </div>
            <button type="submit" class="btn btn-warning w-100 fw-semibold">
              <i class="bi bi-key me-1"></i>Modifier le mot de passe
            </button>
          </form>
        </div>
      </div>

    </div><!-- /col gauche -->

    <!-- ──────── Colonne droite : liste ──────── -->
    <div class="col-xl-8 col-lg-7">
      <div class="card">
        <div class="card-header bg-dark text-white d-flex align-items-center gap-2">
          <i class="bi bi-list-ul"></i>
          <span>Adresses existantes</span>
          <span class="badge bg-secondary ms-auto"><?= count($accounts) ?></span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($accounts)): ?>
          <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <?php if (!$list_result['ok']): ?>
              <p class="mb-0">Impossible de charger la liste.</p>
              <small class="text-danger"><?= htmlspecialchars($list_result['error'] ?? '') ?></small>
            <?php else: ?>
              <p class="mb-0">Aucune adresse email pour <strong><?= htmlspecialchars(MAIL_DOMAIN) ?></strong>.</p>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>Adresse</th>
                  <th>Quota</th>
                  <th>Utilisé</th>
                  <th class="text-center" style="width:60px;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($accounts as $acc):
                    // Normalise les champs selon la structure API
                    $prefix_val = $acc['email']    ?? $acc['login']    ?? $acc['username'] ?? $acc['prefix'] ?? '—';
                    $quota_val  = $acc['quota']     ?? $acc['quota_mb'] ?? $acc['diskquota'] ?? '?';
                    $used_val   = $acc['disk_used'] ?? $acc['diskused'] ?? $acc['used']      ?? null;
                    $full_email = (strpos($prefix_val, '@') !== false)
                        ? $prefix_val
                        : $prefix_val . '@' . MAIL_DOMAIN;
                    $prefix_only = strstr($full_email, '@', true) ?: $prefix_val;
                    $quota_label = ($quota_val == 0 || $quota_val === '∞' || $quota_val === 'unlimited')
                        ? '<span class="badge bg-success quota-badge">Illimité</span>'
                        : '<span class="text-muted">' . htmlspecialchars((string)$quota_val) . ' Mo</span>';
                ?>
                <tr>
                  <td class="email-col">
                    <?= htmlspecialchars($full_email) ?>
                    <i class="bi bi-clipboard copy-icon ms-1"
                       data-copy-val="<?= htmlspecialchars($full_email) ?>"
                       title="Copier"></i>
                  </td>
                  <td><?= $quota_label ?></td>
                  <td class="text-muted">
                    <?= $used_val !== null ? htmlspecialchars(round((float)$used_val, 1)) . ' Mo' : '—' ?>
                  </td>
                  <td class="text-center">
                    <button class="btn btn-sm btn-outline-danger"
                            onclick="confirmDelete('<?= htmlspecialchars($prefix_only, ENT_QUOTES) ?>','<?= htmlspecialchars($full_email, ENT_QUOTES) ?>')"
                            title="Supprimer">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Infos API -->
      <div class="card mt-4">
        <div class="card-header bg-secondary text-white d-flex align-items-center gap-2">
          <i class="bi bi-plug-fill"></i> Informations API
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-sm-6">
              <div class="section-label">Endpoint</div>
              <code class="text-break"><?= htmlspecialchars(PH_API_BASE) ?>/emails</code>
            </div>
            <div class="col-sm-6">
              <div class="section-label">Domaine géré</div>
              <code><?= htmlspecialchars(MAIL_DOMAIN) ?></code>
            </div>
            <div class="col-sm-12">
              <div class="section-label">API User</div>
              <code><?= htmlspecialchars(substr(PH_API_USER, 0, 8)) ?>••••••••••••••••••••••••</code>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /col droite -->
  </div>
</div>

<!-- Modal suppression -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 rounded-4 overflow-hidden">
      <div class="modal-header bg-danger text-white border-0">
        <h5 class="modal-title fw-semibold">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirmer la suppression
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body py-4">
        <p class="mb-1">Supprimer définitivement :</p>
        <p class="fw-semibold font-monospace fs-5 mb-1" id="del-display"></p>
        <small class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Tous les emails seront perdus et cette action est irréversible.</small>
      </div>
      <div class="modal-footer border-0 bg-light">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <form method="post" class="d-inline">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="prefix" id="del-prefix">
          <button type="submit" class="btn btn-danger fw-semibold">
            <i class="bi bi-trash me-1"></i>Supprimer
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Copie presse-papier — boutons data-copy (id de champ)
document.querySelectorAll('[data-copy]').forEach(btn => {
  btn.addEventListener('click', () => {
    const el = document.getElementById(btn.dataset.copy);
    if (el) copyText(el.value, btn);
  });
});
// Copie presse-papier — icônes data-copy-val (valeur directe)
document.querySelectorAll('[data-copy-val]').forEach(el => {
  el.style.cursor = 'pointer';
  el.addEventListener('click', () => copyText(el.dataset.copyVal, el));
});
function copyText(text, el) {
  navigator.clipboard.writeText(text).then(() => {
    const prev = el.className;
    el.className = el.className.replace('bi-clipboard', 'bi-clipboard-check') + ' text-success';
    setTimeout(() => { el.className = prev; }, 1500);
  });
}

// Regénérer mot de passe fort
const CHARS = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
function genPwd(n = 16) {
  const arr = new Uint32Array(n);
  crypto.getRandomValues(arr);
  return Array.from(arr, v => CHARS[v % CHARS.length]).join('');
}
document.getElementById('regenBtn')?.addEventListener('click', () => {
  document.getElementById('pwd-create').value = genPwd();
});

// Modal suppression
function confirmDelete(prefix, fullEmail) {
  document.getElementById('del-prefix').value       = prefix;
  document.getElementById('del-display').textContent = fullEmail;
  new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</body>
</html>
