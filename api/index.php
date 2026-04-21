<?php
// ============================================================
//  CONFIGURATION — à modifier avant déploiement
// ============================================================
define('CPANEL_HOST',     'votre-serveur.planethoster.net'); // ex: srv123.n0c.com
define('CPANEL_PORT',     2083);
define('CPANEL_USER',     'votre_utilisateur_cpanel');
define('CPANEL_PASS',     'votre_mot_de_passe_cpanel');
define('APP_PASSWORD',    'motdepasse_admin_app');           // Mot de passe pour accéder à l'app
define('APP_TITLE',       'Adminov — Gestion des adresses email');
define('SESSION_TIMEOUT', 3600);                             // Durée session en secondes (1h)
// ============================================================

// ─── Auth stateless (cookie HMAC — compatible Vercel serverless) ──
function auth_token(): string
{
    $window = (int)(time() / SESSION_TIMEOUT);
    return hash_hmac('sha256', 'adminov:' . $window, APP_PASSWORD);
}

function is_authenticated(): bool
{
    $cookie = $_COOKIE['adminov_auth'] ?? '';
    return $cookie !== '' && hash_equals(auth_token(), $cookie);
}

function set_auth_cookie(): void
{
    setcookie('adminov_auth', auth_token(), [
        'expires'  => time() + SESSION_TIMEOUT,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function clear_auth_cookie(): void
{
    setcookie('adminov_auth', '', ['expires' => time() - 3600, 'path' => '/']);
}

// ─── Gestion connexion / déconnexion ─────────────────────
$auth_error = '';

if (isset($_POST['app_login'])) {
    if ($_POST['app_password'] === APP_PASSWORD) {
        set_auth_cookie();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $auth_error = 'Mot de passe incorrect.';
}

if (isset($_POST['app_logout'])) {
    clear_auth_cookie();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$authenticated = is_authenticated();

// ─── Fonctions UAPI cPanel ────────────────────────────────
function uapi(string $module, string $function, array $params = []): array
{
    $url = sprintf('https://%s:%d/execute/%s/%s', CPANEL_HOST, CPANEL_PORT, $module, $function);
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERPWD        => CPANEL_USER . ':' . CPANEL_PASS,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        return ['ok' => false, 'error' => "cURL error ($errno): $error"];
    }
    $data = json_decode($response, true);
    if ($data === null) {
        return ['ok' => false, 'error' => 'Réponse invalide du serveur.'];
    }
    $success = !empty($data['status']) && $data['status'] == 1;
    return [
        'ok'    => $success,
        'data'  => $data['data']  ?? [],
        'error' => $success ? '' : implode(' ', $data['errors'] ?? ['Erreur inconnue.']),
    ];
}

// ─── Actions POST ─────────────────────────────────────────
$flash = ['type' => '', 'msg' => ''];

if ($authenticated) {

    if (!empty($_POST['action']) && $_POST['action'] === 'create') {
        $email    = trim($_POST['email']    ?? '');
        $domain   = trim($_POST['domain']   ?? '');
        $password = trim($_POST['password'] ?? '');
        $quota    = (int)($_POST['quota']   ?? 250);

        if (!$email || !$domain || !$password) {
            $flash = ['type' => 'danger', 'msg' => 'Tous les champs sont obligatoires.'];
        } elseif (!preg_match('/^[a-zA-Z0-9._+\-]+$/', $email)) {
            $flash = ['type' => 'danger', 'msg' => 'Nom d\'utilisateur invalide.'];
        } elseif (strlen($password) < 8) {
            $flash = ['type' => 'danger', 'msg' => 'Mot de passe trop court (8 caractères minimum).'];
        } else {
            $result = uapi('Email', 'add_pop', [
                'email'    => $email,
                'domain'   => $domain,
                'password' => $password,
                'quota'    => $quota,
            ]);
            $flash = $result['ok']
                ? ['type' => 'success', 'msg' => "Adresse <strong>{$email}@{$domain}</strong> créée avec succès."]
                : ['type' => 'danger',  'msg' => 'Erreur : ' . htmlspecialchars($result['error'])];
        }
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'passwd') {
        $email    = trim($_POST['email']    ?? '');
        $domain   = trim($_POST['domain']   ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!$email || !$domain || !$password) {
            $flash = ['type' => 'danger', 'msg' => 'Tous les champs sont obligatoires.'];
        } elseif (strlen($password) < 8) {
            $flash = ['type' => 'danger', 'msg' => 'Mot de passe trop court (8 caractères minimum).'];
        } else {
            $result = uapi('Email', 'passwd_pop', [
                'email'    => $email,
                'domain'   => $domain,
                'password' => $password,
            ]);
            $flash = $result['ok']
                ? ['type' => 'success', 'msg' => "Mot de passe de <strong>{$email}@{$domain}</strong> modifié."]
                : ['type' => 'danger',  'msg' => 'Erreur : ' . htmlspecialchars($result['error'])];
        }
    }

    if (!empty($_POST['action']) && $_POST['action'] === 'delete') {
        $email  = trim($_POST['email']  ?? '');
        $domain = trim($_POST['domain'] ?? '');

        if (!$email || !$domain) {
            $flash = ['type' => 'danger', 'msg' => 'Paramètres manquants.'];
        } else {
            $result = uapi('Email', 'delete_pop', [
                'email'  => $email,
                'domain' => $domain,
            ]);
            $flash = $result['ok']
                ? ['type' => 'success', 'msg' => "Adresse <strong>{$email}@{$domain}</strong> supprimée."]
                : ['type' => 'danger',  'msg' => 'Erreur : ' . htmlspecialchars($result['error'])];
        }
    }

    // Charger domaines et comptes
    $domains_result = uapi('DomainInfo', 'list_domains');
    $all_domains    = [];
    if ($domains_result['ok'] && !empty($domains_result['data'])) {
        $d = $domains_result['data'];
        if (!empty($d['main_domain']))   $all_domains[] = $d['main_domain'];
        if (!empty($d['addon_domains'])) $all_domains   = array_merge($all_domains, $d['addon_domains']);
        if (!empty($d['sub_domains']))   $all_domains   = array_merge($all_domains, $d['sub_domains']);
        $all_domains = array_unique(array_values($all_domains));
    }
    if (empty($all_domains)) {
        $all_domains = ['(domaine non chargé)'];
    }

    $filter_domain   = $_GET['domain'] ?? $all_domains[0];
    $accounts_result = uapi('Email', 'list_pops', ['domain' => $filter_domain, 'regex' => '']);
    $accounts        = $accounts_result['ok'] ? ($accounts_result['data'] ?? []) : [];
}

// ─── Génération de mot de passe fort côté serveur ─────────
function strong_password(int $length = 16): string
{
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    $pwd   = '';
    for ($i = 0; $i < $length; $i++) {
        $pwd .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pwd;
}
$suggested_password = strong_password();
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
  :root { --brand:#1a56db; --brand-dark:#1245b0; --bg:#f0f4ff; }
  body { background:var(--bg); font-family:'Segoe UI',system-ui,sans-serif; }
  .card { border:none; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.08); }
  .card-header { border-radius:12px 12px 0 0 !important; font-weight:600; }
  .btn-brand { background:var(--brand); border-color:var(--brand); color:#fff; }
  .btn-brand:hover { background:var(--brand-dark); border-color:var(--brand-dark); color:#fff; }
  .table th { font-size:.8rem; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; }
  .login-card { max-width:400px; margin:10vh auto; }
  .login-logo { font-size:2rem; font-weight:800; color:var(--brand); }
</style>
</head>
<body>

<?php if (!$authenticated): ?>
<!-- ══ PAGE CONNEXION ══ -->
<div class="container">
  <div class="card login-card p-4">
    <div class="text-center mb-4">
      <div class="login-logo"><i class="bi bi-envelope-at-fill me-2"></i>Adminov</div>
      <p class="text-muted mb-0">Gestion des adresses email</p>
    </div>
    <?php if ($auth_error): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($auth_error) ?></div>
    <?php endif; ?>
    <form method="post">
      <div class="mb-3">
        <label class="form-label fw-semibold">Mot de passe</label>
        <input type="password" name="app_password" class="form-control" placeholder="••••••••" autofocus required>
      </div>
      <button type="submit" name="app_login" value="1" class="btn btn-brand w-100">
        <i class="bi bi-lock-fill me-1"></i>Connexion
      </button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ══ APPLICATION ══ -->
<nav class="navbar navbar-dark" style="background:var(--brand);">
  <div class="container">
    <span class="navbar-brand fw-bold"><i class="bi bi-envelope-at-fill me-2"></i><?= htmlspecialchars(APP_TITLE) ?></span>
    <form method="post" class="ms-auto">
      <button name="app_logout" value="1" class="btn btn-sm btn-outline-light">
        <i class="bi bi-box-arrow-right me-1"></i>Déconnexion
      </button>
    </form>
  </div>
</nav>

<div class="container py-4">

  <?php if ($flash['msg']): ?>
  <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
    <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i>
    <?= $flash['msg'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <div class="row g-4">

    <!-- Colonne formulaires -->
    <div class="col-lg-4">

      <!-- Créer -->
      <div class="card">
        <div class="card-header bg-primary text-white">
          <i class="bi bi-plus-circle me-2"></i>Créer une adresse email
        </div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="create">
            <div class="mb-3">
              <label class="form-label fw-semibold">Utilisateur <span class="text-danger">*</span></label>
              <input type="text" name="email" class="form-control" placeholder="contact" required
                     pattern="[a-zA-Z0-9._+\-]+" title="Lettres, chiffres, . _ + -">
              <div class="form-text">Partie avant le @</div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Domaine <span class="text-danger">*</span></label>
              <select name="domain" class="form-select" required>
                <?php foreach ($all_domains as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Mot de passe <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="text" name="password" id="pwd-field" class="form-control font-monospace"
                       value="<?= htmlspecialchars($suggested_password) ?>" required minlength="8">
                <button type="button" class="btn btn-outline-secondary" id="regenBtn" title="Régénérer">
                  <i class="bi bi-arrow-clockwise"></i>
                </button>
                <button type="button" class="btn btn-outline-secondary" data-copy="pwd-field" title="Copier">
                  <i class="bi bi-clipboard"></i>
                </button>
              </div>
              <div class="form-text">Min. 8 caractères</div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Quota (Mo)</label>
              <input type="number" name="quota" class="form-control" value="250" min="0" max="10240">
              <div class="form-text">0 = illimité</div>
            </div>
            <button type="submit" class="btn btn-brand w-100">
              <i class="bi bi-envelope-plus me-1"></i>Créer l'adresse
            </button>
          </form>
        </div>
      </div>

      <!-- Changer mot de passe -->
      <div class="card mt-4">
        <div class="card-header bg-warning text-dark">
          <i class="bi bi-key me-2"></i>Changer un mot de passe
        </div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="passwd">
            <div class="mb-2">
              <input type="text" name="email" class="form-control" placeholder="utilisateur" required>
            </div>
            <div class="mb-2">
              <select name="domain" class="form-select" required>
                <?php foreach ($all_domains as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="mb-3">
              <input type="text" name="password" class="form-control font-monospace"
                     placeholder="Nouveau mot de passe" required minlength="8">
            </div>
            <button type="submit" class="btn btn-warning w-100">
              <i class="bi bi-key-fill me-1"></i>Modifier le mot de passe
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Colonne liste -->
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span><i class="bi bi-list-ul me-2"></i>Comptes existants</span>
          <form method="get" class="mb-0">
            <select name="domain" class="form-select form-select-sm" onchange="this.form.submit()">
              <?php foreach ($all_domains as $d): ?>
              <option value="<?= htmlspecialchars($d) ?>" <?= $d === $filter_domain ? 'selected' : '' ?>>
                <?= htmlspecialchars($d) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <div class="card-body p-0">
          <?php if (empty($accounts)): ?>
          <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox fs-2 d-block mb-2"></i>Aucun compte email pour ce domaine.
          </div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>Adresse</th>
                  <th>Quota</th>
                  <th>Utilisé</th>
                  <th class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($accounts as $acc):
                    $user   = $acc['email']  ?? $acc['login']  ?? '—';
                    $domain = $acc['domain'] ?? $filter_domain;
                    $full   = $user . '@' . $domain;
                    $quota  = $acc['quota_mb'] ?? $acc['_diskquota'] ?? '?';
                    $used   = isset($acc['_diskused']) ? round($acc['_diskused'], 1) : '—';
                    $qlabel = ($quota == 0 || $quota === '∞')
                        ? '<span class="badge bg-success">Illimité</span>'
                        : htmlspecialchars($quota) . ' Mo';
                ?>
                <tr>
                  <td>
                    <span class="font-monospace"><?= htmlspecialchars($full) ?></span>
                    <button class="btn btn-sm btn-link p-0 ms-1 text-muted" data-copy-val="<?= htmlspecialchars($full) ?>" title="Copier">
                      <i class="bi bi-clipboard"></i>
                    </button>
                  </td>
                  <td><?= $qlabel ?></td>
                  <td><?= htmlspecialchars((string)$used) ?> Mo</td>
                  <td class="text-center">
                    <button class="btn btn-sm btn-outline-danger"
                            onclick="confirmDelete('<?= htmlspecialchars($user, ENT_QUOTES) ?>','<?= htmlspecialchars($domain, ENT_QUOTES) ?>')">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="px-3 py-2 text-muted small">
            <?= count($accounts) ?> compte(s) — <strong><?= htmlspecialchars($filter_domain) ?></strong>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Info config -->
      <div class="card mt-4">
        <div class="card-header bg-secondary text-white">
          <i class="bi bi-info-circle me-2"></i>Configuration active
        </div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-sm-6">
              <p class="text-muted small mb-1">Serveur cPanel</p>
              <code><?= htmlspecialchars(CPANEL_HOST) ?>:<?= CPANEL_PORT ?></code>
            </div>
            <div class="col-sm-6">
              <p class="text-muted small mb-1">Utilisateur cPanel</p>
              <code><?= htmlspecialchars(CPANEL_USER) ?></code>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modal suppression -->
<div class="modal fade" id="deleteModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirmer la suppression</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Supprimer définitivement <strong id="del-address"></strong> ?<br>
        <small class="text-muted">Tous les emails associés seront perdus.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
        <form method="post" id="deleteForm">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="email"  id="del-email">
          <input type="hidden" name="domain" id="del-domain">
          <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Supprimer</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Copie presse-papier
document.querySelectorAll('[data-copy]').forEach(btn => {
  btn.addEventListener('click', () => {
    const text = document.getElementById(btn.dataset.copy).value;
    copyText(text, btn);
  });
});
document.querySelectorAll('[data-copy-val]').forEach(btn => {
  btn.addEventListener('click', () => copyText(btn.dataset.copyVal, btn));
});
function copyText(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    const i = btn.querySelector('i');
    const prev = i.className;
    i.className = 'bi bi-clipboard-check text-success';
    setTimeout(() => i.className = prev, 1500);
  });
}

// Regénérer mot de passe
const CHARS = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
function genPwd(len = 16) {
  const arr = new Uint32Array(len);
  crypto.getRandomValues(arr);
  return Array.from(arr, v => CHARS[v % CHARS.length]).join('');
}
document.getElementById('regenBtn')?.addEventListener('click', () => {
  document.getElementById('pwd-field').value = genPwd();
});

// Modal suppression
function confirmDelete(email, domain) {
  document.getElementById('del-email').value       = email;
  document.getElementById('del-domain').value      = domain;
  document.getElementById('del-address').textContent = email + '@' + domain;
  new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
</body>
</html>
