<?php
ob_start(); // buffer toute sortie — évite "headers already sent"
ini_set('display_errors', '1');
error_reporting(E_ALL);
// ============================================================
//  CONFIGURATION
// ============================================================
define('PH_API_USER',        'ca090d4c75f80521945812f3968b3df9');
define('PH_API_KEY',         'f22301c7f17e2d7333cf553230cab99973da80a0b826396f7953d5375fa51859');
define('MAIL_DOMAIN',        'neomails.fr');
define('APP_PASSWORD',       'S@rix93100');
define('DEFAULT_EMAIL_PWD',  'S@rix93100');   // mot de passe par défaut des boîtes créées
define('APP_TITLE',          'Adminov — Avance Immédiate URSSAF');
define('APP_VERSION',        '4.5');
define('LIST_CACHE_TTL',     600); // 10 minutes
define('PH_API_BASE',        'https://api.planethoster.net/v3');
define('N0C_ACCOUNT_ID',     113185);
define('DB_PATH', dirname($_SERVER['DOCUMENT_ROOT']) . '/adminov_contacts.db');

// Adresses réelles en Île-de-France (pool aléatoire)
const IDF_ADDRESSES = [
    '15 Rue de la Paix, 75002 Paris',
    '42 Avenue des Champs-Élysées, 75008 Paris',
    '8 Rue du Faubourg Saint-Antoine, 75011 Paris',
    '23 Rue de Rivoli, 75004 Paris',
    '37 Boulevard Voltaire, 75011 Paris',
    '12 Rue Saint-Lazare, 75009 Paris',
    '6 Place de la République, 75010 Paris',
    '19 Rue de Belleville, 75019 Paris',
    '54 Avenue d\'Italie, 75013 Paris',
    '3 Rue de la Convention, 75015 Paris',
    '28 Rue Legendre, 75017 Paris',
    '11 Rue du Commerce, 75015 Paris',
    '47 Rue d\'Alembert, 92120 Montrouge',
    '9 Rue Victor Hugo, 92300 Levallois-Perret',
    '22 Avenue de la République, 93100 Montreuil',
    '5 Rue du Général Leclerc, 94000 Créteil',
    '14 Avenue Foch, 94120 Fontenay-sous-Bois',
    '31 Rue Carnot, 92110 Clichy',
    '7 Boulevard Galliéni, 92130 Issy-les-Moulineaux',
    '18 Rue des Acacias, 91300 Massy',
    '2 Allée des Magnolias, 78000 Versailles',
    '25 Rue Jean Jaurès, 93200 Saint-Denis',
    '40 Rue de Paris, 93000 Bobigny',
    '16 Avenue du Général de Gaulle, 94700 Maisons-Alfort',
];
// ============================================================

if (function_exists('opcache_invalidate')) opcache_invalidate(__FILE__, true);

// Log toutes les erreurs fatales dans un fichier accessible
$_log = __DIR__ . '/adminov_debug.log';
register_shutdown_function(function() use ($_log) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        file_put_contents($_log,
            date('Y-m-d H:i:s') . ' [FATAL] ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . "\n"
            . 'POST: ' . json_encode($_POST) . "\n\n",
            FILE_APPEND);
    }
});
set_error_handler(function($no, $msg, $file, $line) use ($_log) {
    if ($no & (E_ERROR | E_USER_ERROR)) {
        file_put_contents($_log,
            date('Y-m-d H:i:s') . ' [ERR ' . $no . '] ' . $msg . ' in ' . $file . ':' . $line . "\n",
            FILE_APPEND);
    }
    return false;
});

session_start();

// Route debug (accessible uniquement si connecté : ?debug=1)
if (isset($_GET['debug']) && !empty($_SESSION['auth'])) {
    header('Content-Type: text/plain');
    echo 'PHP: ' . PHP_VERSION . "\n";
    echo 'PDO drivers: ' . implode(', ', PDO::getAvailableDrivers()) . "\n";
    echo 'Session: OK' . "\n";
    echo 'Cache emails: ' . (empty($_SESSION['accounts_cache']) ? 'vide' : count($_SESSION['accounts_cache']) . ' entrées') . "\n";
    echo 'Cache age: ' . (isset($_SESSION['accounts_cache_ts']) ? (time() - $_SESSION['accounts_cache_ts']) . 's' : 'N/A') . "\n";
    if (file_exists($_log)) echo "\n--- LOG ---\n" . file_get_contents($_log);
    else echo "Log: vide\n";
    exit;
}
// Lire log seul
if (isset($_GET['showlog']) && !empty($_SESSION['auth'])) {
    header('Content-Type: text/plain');
    echo file_exists($_log) ? file_get_contents($_log) : 'Log vide.';
    exit;
}

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
$contacts      = [];

// ─── Client API PlanetHoster ──────────────────────────────
function ph_request(string $method, string $path, array $body = []): array
{
    $url = PH_API_BASE . $path;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'X-API-USER: '    . PH_API_USER,
            'X-API-KEY: '     . PH_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $response = curl_exec($ch);
    $http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($ch);
    $error    = curl_error($ch);
    curl_close($ch);
    if ($errno) return ['ok' => false, 'http' => 0, 'error' => "cURL ($errno): $error", 'data' => []];
    $data = json_decode($response, true) ?? [];
    $ok   = $http >= 200 && $http < 300;
    $msg  = '';
    if (!$ok) {
        $msg = $data['message'] ?? $data['error'] ?? $data['msg'] ?? "Erreur HTTP $http.";
        if (is_array($msg)) $msg = implode(' ', $msg);
    }
    return ['ok' => $ok, 'http' => $http, 'error' => $msg, 'data' => $data];
}

function get_n0c_id(): int { return N0C_ACCOUNT_ID; }

// ─── Base de données SQLite ───────────────────────────────
function get_db(): PDO
{
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS contacts (
        email        TEXT PRIMARY KEY,
        nom          TEXT DEFAULT '',
        prenom       TEXT DEFAULT '',
        naissance    TEXT DEFAULT '',
        adresse      TEXT DEFAULT '',
        pays         TEXT DEFAULT '',
        telephone    TEXT DEFAULT '',
        rib          TEXT DEFAULT '',
        bic          TEXT DEFAULT '',
        updated_at   TEXT DEFAULT ''
    )");
    // Migration : ajoute bic si la table existait avant
    $cols = array_column($db->query("PRAGMA table_info(contacts)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('bic', $cols)) {
        $db->exec("ALTER TABLE contacts ADD COLUMN bic TEXT DEFAULT ''");
    }
    return $db;
}

function load_contacts(): array
{
    try {
        $rows = get_db()->query("SELECT * FROM contacts")->fetchAll(PDO::FETCH_ASSOC);
        $map  = [];
        foreach ($rows as $r) $map[$r['email']] = $r;
        return $map;
    } catch (Exception $e) { return []; }
}

function save_contact(string $email, array $d): string
{
    try {
        get_db()->prepare("INSERT OR REPLACE INTO contacts
            (email,nom,prenom,naissance,adresse,pays,telephone,rib,bic,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$email, $d['nom'] ?? '', $d['prenom'] ?? '', $d['naissance'] ?? '',
                   $d['adresse'] ?? '', $d['pays'] ?? '', $d['telephone'] ?? '',
                   $d['rib'] ?? '', $d['bic'] ?? '', date('Y-m-d H:i:s')]);
        return '';
    } catch (Exception $e) {
        return $e->getMessage();
    }
}

// ─── Actions POST ─────────────────────────────────────────
$flash = ['type' => '', 'msg' => ''];

if ($authenticated) {

    $n0c_id = get_n0c_id();
    $action = $_POST['action'] ?? '';

    // ── Intégrer un nouveau bénéficiaire (email + fiche) ──
    if ($action === 'onboard') {
        $nom       = trim($_POST['nom']       ?? '');
        $prenom    = trim($_POST['prenom']    ?? '');
        $prefix    = trim($_POST['prefix']    ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $naissance = trim($_POST['naissance'] ?? '');
        $pays      = trim($_POST['pays']      ?? '');
        $adresse   = trim($_POST['adresse']   ?? '');
        $rib       = trim($_POST['rib']       ?? '');
        $bic       = trim($_POST['bic']       ?? '');

        if (!$nom || !$prenom || !$prefix) {
            $flash = ['type' => 'danger', 'msg' => 'Nom, prénom et identifiant email sont obligatoires.'];
        } elseif (!preg_match('/^[a-zA-Z0-9._+\-]+$/', $prefix)) {
            $flash = ['type' => 'danger', 'msg' => 'Identifiant email invalide.'];
        } else {
            $full_email = $prefix . '@' . MAIL_DOMAIN;
            $result = ph_request('POST', '/hosting/email', [
                'id'       => $n0c_id,
                'domain'   => MAIL_DOMAIN,
                'mailUser' => $prefix,
                'password' => DEFAULT_EMAIL_PWD,
                'quota'    => 250,
            ]);
            if ($result['ok'] || strpos(strtolower($result['error'] ?? ''), 'exist') !== false) {
                $dberr = save_contact($full_email, compact('nom','prenom','naissance','adresse','pays','telephone','rib','bic'));
                unset($_SESSION['accounts_cache']);
                $flash = $dberr
                    ? ['type' => 'danger',  'msg' => "Email créé mais erreur fiche : $dberr"]
                    : ['type' => 'success', 'msg' => "<strong>{$prenom} {$nom}</strong> ajouté — email <strong>{$full_email}</strong> créé."];
            } else {
                $flash = ['type' => 'danger', 'msg' => 'Erreur création email : ' . htmlspecialchars($result['error'])];
            }
        }
    }

    // ── Supprimer un email ────────────────────────────────
    if ($action === 'delete') {
        $prefix = trim($_POST['prefix'] ?? '');
        if (!$prefix) {
            $flash = ['type' => 'danger', 'msg' => 'Paramètre manquant.'];
        } else {
            $result = ph_request('DELETE', '/hosting/email', [
                'id'       => $n0c_id,
                'domain'   => MAIL_DOMAIN,
                'mailUser' => $prefix,
            ]);
            if ($result['ok']) {
                unset($_SESSION['accounts_cache']);
                $flash = ['type' => 'success', 'msg' => "Adresse <strong>{$prefix}@" . MAIL_DOMAIN . "</strong> supprimée."];
            } else {
                $flash = ['type' => 'danger', 'msg' => 'Erreur API : ' . htmlspecialchars($result['error'])];
            }
        }
    }

    // ── Changer le mot de passe ───────────────────────────
    if ($action === 'passwd') {
        $prefix   = trim($_POST['prefix']   ?? '');
        $password = trim($_POST['password'] ?? '');
        if (!$prefix || !$password) {
            $flash = ['type' => 'danger', 'msg' => 'Tous les champs sont obligatoires.'];
        } elseif (strlen($password) < 8) {
            $flash = ['type' => 'danger', 'msg' => 'Mot de passe trop court (8 caractères minimum).'];
        } else {
            $result = ph_request('PATCH', '/hosting/email', [
                'id'       => $n0c_id,
                'domain'   => MAIL_DOMAIN,
                'mailUser' => $prefix,
                'password' => $password,
            ]);
            $flash = $result['ok']
                ? ['type' => 'success', 'msg' => "Mot de passe de <strong>{$prefix}@" . MAIL_DOMAIN . "</strong> modifié."]
                : ['type' => 'danger',  'msg' => 'Erreur API : ' . htmlspecialchars($result['error'])];
        }
    }

    // ── Sauvegarder une fiche contact ─────────────────────
    if ($action === 'save_contact') {
        $cemail = trim($_POST['c_email'] ?? '');
        if ($cemail) {
            $dberr = save_contact($cemail, [
                'nom'       => trim($_POST['c_nom']       ?? ''),
                'prenom'    => trim($_POST['c_prenom']    ?? ''),
                'naissance' => trim($_POST['c_naissance'] ?? ''),
                'adresse'   => trim($_POST['c_adresse']   ?? ''),
                'pays'      => trim($_POST['c_pays']      ?? ''),
                'telephone' => trim($_POST['c_telephone'] ?? ''),
                'rib'       => trim($_POST['c_rib']       ?? ''),
                'bic'       => trim($_POST['c_bic']       ?? ''),
            ]);
            $flash = $dberr
                ? ['type' => 'danger',  'msg' => "Erreur enregistrement : $dberr"]
                : ['type' => 'success', 'msg' => "Fiche de <strong>{$cemail}</strong> enregistrée."];
        }
    }
}

// ─── Chargement données ───────────────────────────────────
$list_result = ['ok' => false, 'error' => '', 'data' => []];
$accounts    = [];
if ($authenticated) {
    // Sur une requête POST : toujours utiliser le cache existant (jamais d'appel API)
    // Sur GET : recharger si cache expiré
    $is_post  = ($_SERVER['REQUEST_METHOD'] === 'POST');
    $cache_ok = !empty($_SESSION['accounts_cache'])
                && ($is_post || (
                    isset($_SESSION['accounts_cache_ts'])
                    && (time() - $_SESSION['accounts_cache_ts']) < LIST_CACHE_TTL
                    && empty($_GET['refresh'])
                ));

    if ($cache_ok) {
        $accounts    = $_SESSION['accounts_cache'];
        $list_result = ['ok' => true, 'error' => '', 'data' => []];
    } else {
        $list_result = ph_request('GET', '/hosting/emails?id=' . $n0c_id . '&domain=' . urlencode(MAIL_DOMAIN));
        if ($list_result['ok']) {
            $raw = $list_result['data'];
            if (isset($raw['data']) && is_array($raw['data']))         $accounts = $raw['data'];
            elseif (isset($raw['emails']) && is_array($raw['emails'])) $accounts = $raw['emails'];
            elseif (is_array($raw) && isset($raw[0]))                  $accounts = $raw;
            $_SESSION['accounts_cache']    = $accounts;
            $_SESSION['accounts_cache_ts'] = time();
        }
    }

    $contacts = load_contacts();
}

// ─── Génération mot de passe fort ─────────────────────────
function strong_password(int $len = 16): string
{
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    $out   = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

// Adresse IDF aléatoire pour pré-remplissage
$random_address = IDF_ADDRESSES[array_rand(IDF_ADDRESSES)];
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
:root { --brand:#0e6adb; --brand-dark:#0a55b3; --surface:#f0f4ff; }
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
.email-preview { font-family:monospace; font-size:.85rem; color:var(--brand);
                 background:rgba(14,106,219,.07); padding:.25rem .6rem; border-radius:6px;
                 display:inline-block; transition:all .2s; }
.tools-toggle { font-size:.8rem; cursor:pointer; color:#6b7280; text-decoration:none; }
.tools-toggle:hover { color:var(--brand); }
</style>
</head>
<body>
<?php if (!$authenticated): ?>

<!-- ══════════════════════ LOGIN ══════════════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="text-center mb-4">
      <div class="login-logo"><i class="bi bi-person-vcard-fill"></i> Adminov</div>
      <div class="login-sub mt-1">Avance Immédiate URSSAF — <strong><?= htmlspecialchars(MAIL_DOMAIN) ?></strong></div>
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
      <i class="bi bi-person-vcard-fill me-2 opacity-75"></i><?= htmlspecialchars(APP_TITLE) ?>
      <span class="domain-badge ms-2"><?= htmlspecialchars(MAIL_DOMAIN) ?></span>
      <span class="badge bg-secondary ms-2" style="font-size:.65rem;">v<?= APP_VERSION ?></span>
    </span>
    <div class="ms-auto d-flex gap-2">
      <a href="https://neomails.fr/webmail/" target="_blank" class="btn btn-sm btn-light px-3">
        <i class="bi bi-envelope-open-fill me-1"></i>Webmail
      </a>
      <form method="post">
        <button name="app_logout" value="1" class="btn btn-sm btn-outline-light px-3">
          <i class="bi bi-box-arrow-right me-1"></i>Déconnexion
        </button>
      </form>
    </div>
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

    <!-- ──────── Colonne gauche ──────── -->
    <div class="col-xl-5 col-lg-5">

      <!-- ★ FORMULAIRE NOUVEAU BÉNÉFICIAIRE ★ -->
      <div class="card border-0" style="box-shadow:0 4px 24px rgba(14,106,219,.18);">
        <div class="card-header text-white d-flex align-items-center gap-2" style="background:var(--brand);">
          <i class="bi bi-person-plus-fill fs-5"></i>
          <span>Nouveau bénéficiaire</span>
          <span class="badge bg-white text-primary ms-auto" style="font-size:.7rem;">Avance Immédiate URSSAF</span>
        </div>
        <div class="card-body pt-3 pb-4">
          <form method="post" autocomplete="off" id="onboard-form">
            <input type="hidden" name="action" value="onboard">

            <!-- Identité -->
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label fw-semibold mb-1">Nom <span class="text-danger">*</span></label>
                <input type="text" name="nom" id="f-nom" class="form-control" placeholder="DUPONT"
                       style="text-transform:uppercase" required autofocus>
              </div>
              <div class="col-6">
                <label class="form-label fw-semibold mb-1">Prénom <span class="text-danger">*</span></label>
                <input type="text" name="prenom" id="f-prenom" class="form-control" placeholder="Jean" required>
              </div>
            </div>

            <!-- Email généré -->
            <div class="mb-2">
              <label class="form-label fw-semibold mb-1">Adresse email <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="text" name="prefix" id="f-prefix" class="form-control font-monospace"
                       placeholder="jean.dupont" pattern="[a-zA-Z0-9._+\-]+" required>
                <span class="input-group-text text-muted small">@<?= htmlspecialchars(MAIL_DOMAIN) ?></span>
                <button type="button" class="btn btn-outline-secondary" id="regen-prefix-btn" title="Regénérer depuis nom/prénom">
                  <i class="bi bi-arrow-clockwise"></i>
                </button>
              </div>
              <div class="form-text">
                Mot de passe par défaut : <code class="text-danger fw-bold"><?= htmlspecialchars(DEFAULT_EMAIL_PWD) ?></code>
              </div>
            </div>

            <!-- Téléphone -->
            <div class="mb-2">
              <label class="form-label fw-semibold mb-1">Téléphone</label>
              <input type="tel" name="telephone" id="f-telephone" class="form-control"
                     placeholder="+33 6 00 00 00 00">
            </div>

            <!-- Date et pays de naissance -->
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label fw-semibold mb-1">Date de naissance</label>
                <input type="date" name="naissance" id="f-naissance" class="form-control">
              </div>
              <div class="col-6">
                <label class="form-label fw-semibold mb-1">Pays de naissance</label>
                <input type="text" name="pays" id="f-pays" class="form-control" placeholder="France">
              </div>
            </div>

            <!-- Adresse -->
            <div class="mb-2">
              <label class="form-label fw-semibold mb-1">Adresse de résidence</label>
              <div class="input-group">
                <input type="text" name="adresse" id="f-adresse" class="form-control"
                       placeholder="Adresse complète"
                       value="<?= htmlspecialchars($random_address) ?>">
                <button type="button" class="btn btn-outline-secondary" id="rand-addr-btn" title="Adresse aléatoire IDF">
                  <i class="bi bi-shuffle"></i>
                </button>
              </div>
              <div class="form-text">Cliquez <i class="bi bi-shuffle"></i> pour une adresse IDF aléatoire</div>
            </div>

            <!-- RIB + BIC -->
            <div class="row g-2 mb-3">
              <div class="col-8">
                <label class="form-label fw-semibold mb-1">RIB</label>
                <input type="text" name="rib" id="f-rib" class="form-control font-monospace"
                       placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX">
              </div>
              <div class="col-4">
                <label class="form-label fw-semibold mb-1">BIC</label>
                <input type="text" name="bic" id="f-bic" class="form-control font-monospace"
                       placeholder="BNPAFRPP">
              </div>
            </div>

            <button type="submit" class="btn btn-brand w-100 fw-semibold py-2">
              <i class="bi bi-person-check-fill me-2"></i>Créer l'email &amp; enregistrer la fiche
            </button>
          </form>
        </div>
      </div>

      <!-- Outils (accordéon) -->
      <div class="mt-3">
        <a class="tools-toggle d-flex align-items-center gap-1 mb-2" data-bs-toggle="collapse" href="#tools-section">
          <i class="bi bi-tools"></i> Outils avancés
          <i class="bi bi-chevron-down ms-1" style="font-size:.75rem;"></i>
        </a>
        <div class="collapse" id="tools-section">
          <!-- Changer mot de passe -->
          <div class="card mb-3">
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
                <div class="mb-2">
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
        </div>
      </div>

    </div><!-- /col gauche -->

    <!-- ──────── Colonne droite : liste ──────── -->
    <div class="col-xl-7 col-lg-7">
      <div class="card">
        <div class="card-header bg-dark text-white d-flex align-items-center flex-wrap gap-2">
          <i class="bi bi-people-fill"></i>
          <span>Bénéficiaires</span>
          <span class="badge bg-secondary" id="count-badge"><?= count($accounts) ?></span>
          <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
            <input type="search" id="email-search" class="form-control form-control-sm"
                   placeholder="Rechercher…" style="width:180px;">
            <select id="per-page" class="form-select form-select-sm" style="width:75px;">
              <option value="20">20</option>
              <option value="50">50</option>
              <option value="100">100</option>
              <option value="0">Tout</option>
            </select>
          </div>
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
            <table class="table table-hover mb-0" id="email-table">
              <thead class="table-light">
                <tr>
                  <th>Adresse</th>
                  <th>Quota</th>
                  <th>Utilisé</th>
                  <th class="text-center" style="width:130px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($accounts as $acc):
                    $prefix_val  = $acc['mailUser'] ?? $acc['email'] ?? $acc['login'] ?? $acc['username'] ?? '—';
                    $quota_val   = $acc['quota']     ?? $acc['quota_mb'] ?? $acc['diskquota'] ?? '?';
                    $used_val    = $acc['disk_used'] ?? $acc['diskused'] ?? $acc['used']      ?? null;
                    $full_email  = (strpos($prefix_val, '@') !== false) ? $prefix_val : $prefix_val . '@' . MAIL_DOMAIN;
                    $prefix_only = strstr($full_email, '@', true) ?: $prefix_val;
                    $quota_label = ($quota_val == 0 || $quota_val === '∞' || $quota_val === 'unlimited')
                        ? '<span class="badge bg-success quota-badge">Illimité</span>'
                        : '<span class="text-muted">' . htmlspecialchars((string)$quota_val) . ' Mo</span>';
                    $contact     = $contacts[$full_email] ?? [];
                    $has_contact = !empty($contact['nom']) || !empty($contact['prenom']);
                    $c_json      = htmlspecialchars(json_encode($contact + ['email' => $full_email]), ENT_QUOTES);
                ?>
                <tr class="email-row" data-email="<?= htmlspecialchars(strtolower($full_email)) ?>">
                  <td class="email-col">
                    <?php if ($has_contact): ?>
                    <div class="fw-semibold" style="font-size:.82rem;color:#374151;">
                      <?= htmlspecialchars($contact['prenom'] . ' ' . strtoupper($contact['nom'])) ?>
                    </div>
                    <?php endif; ?>
                    <span class="text-muted" style="font-size:.85rem;"><?= htmlspecialchars($full_email) ?></span>
                    <i class="bi bi-clipboard copy-icon ms-1"
                       data-copy-val="<?= htmlspecialchars($full_email) ?>"
                       title="Copier"></i>
                  </td>
                  <td><?= $quota_label ?></td>
                  <td class="text-muted">
                    <?= $used_val !== null ? htmlspecialchars(round((float)$used_val, 1)) . ' Mo' : '—' ?>
                  </td>
                  <td class="text-center">
                    <button class="btn btn-sm <?= $has_contact ? 'btn-info' : 'btn-outline-secondary' ?> me-1"
                            onclick='openContact(<?= $c_json ?>)'
                            title="<?= $has_contact ? 'Voir / modifier la fiche' : 'Créer la fiche' ?>">
                      <i class="bi bi-person<?= $has_contact ? '-fill' : '' ?>"></i>
                    </button>
                    <a href="https://neomails.fr/webmail/" target="_blank"
                       class="btn btn-sm btn-outline-primary me-1" title="Ouvrir le webmail">
                      <i class="bi bi-envelope-open"></i>
                    </a>
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
          <!-- Pagination -->
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 px-3 py-2 border-top">
            <div class="text-muted small" id="pagination-info"></div>
            <nav><ul class="pagination pagination-sm mb-0" id="pagination-nav"></ul></nav>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div><!-- /col droite -->
  </div>
</div>

<!-- Modal fiche contact -->
<div class="modal fade" id="contactModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 rounded-4 overflow-hidden">
      <div class="modal-header text-white border-0" style="background:var(--brand);">
        <h5 class="modal-title fw-semibold d-flex align-items-center gap-2 flex-wrap">
          <i class="bi bi-person-vcard-fill"></i>
          <span id="c-title"></span>
          <button type="button" id="copy-email-btn"
                  class="btn btn-sm btn-light px-2 py-0" style="font-size:.75rem;" title="Copier l'email">
            <i class="bi bi-clipboard me-1"></i><span>Copier</span>
          </button>
        </h5>
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <input type="hidden" name="action"  value="save_contact">
        <input type="hidden" name="c_email" id="c_email">
        <div class="modal-body py-4">
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Nom</label>
              <input type="text" name="c_nom" id="c_nom" class="form-control" placeholder="DUPONT">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Prénom</label>
              <input type="text" name="c_prenom" id="c_prenom" class="form-control" placeholder="Jean">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Date de naissance</label>
              <input type="date" name="c_naissance" id="c_naissance" class="form-control">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Pays de naissance</label>
              <input type="text" name="c_pays" id="c_pays" class="form-control" placeholder="France">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Adresse de résidence</label>
              <input type="text" name="c_adresse" id="c_adresse" class="form-control">
            </div>
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Téléphone</label>
              <input type="tel" name="c_telephone" id="c_telephone" class="form-control" placeholder="+33 6 00 00 00 00">
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold">RIB</label>
              <input type="text" name="c_rib" id="c_rib" class="form-control font-monospace"
                     placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX">
            </div>
            <div class="col-sm-2">
              <label class="form-label fw-semibold">BIC</label>
              <input type="text" name="c_bic" id="c_bic" class="form-control font-monospace"
                     placeholder="BNPAFRPP">
            </div>
          </div>
        </div>
        <div class="modal-footer border-0 bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-brand fw-semibold">
            <i class="bi bi-floppy-fill me-1"></i>Enregistrer
          </button>
        </div>
      </form>
    </div>
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
// ── Adresses IDF côté client (même liste que PHP) ──────────
const IDF_ADDRESSES = <?= json_encode(IDF_ADDRESSES) ?>;

// ── Utilitaire slugify (retire accents, met en minuscules) ─
function slugify(str) {
  return str.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .toLowerCase().trim()
    .replace(/[^a-z0-9]+/g, '.');
}

// ── Génération auto du préfixe email depuis nom/prénom ─────
function updatePrefix() {
  const nom    = document.getElementById('f-nom')?.value.trim()    || '';
  const prenom = document.getElementById('f-prenom')?.value.trim() || '';
  if (prenom || nom) {
    document.getElementById('f-prefix').value =
      slugify(prenom) + (prenom && nom ? '.' : '') + slugify(nom);
  }
}
document.getElementById('f-prenom')?.addEventListener('blur',  updatePrefix);
document.getElementById('f-nom')?.addEventListener('blur',    updatePrefix);
document.getElementById('regen-prefix-btn')?.addEventListener('click', updatePrefix);

// ── Adresse aléatoire IDF ──────────────────────────────────
document.getElementById('rand-addr-btn')?.addEventListener('click', () => {
  const a = IDF_ADDRESSES[Math.floor(Math.random() * IDF_ADDRESSES.length)];
  document.getElementById('f-adresse').value = a;
});

// ── Copie presse-papier ────────────────────────────────────
document.querySelectorAll('[data-copy]').forEach(btn => {
  btn.addEventListener('click', () => {
    const el = document.getElementById(btn.dataset.copy);
    if (el) copyText(el.value, btn);
  });
});
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

// ── Modal fiche contact ────────────────────────────────────
function openContact(data) {
  const email = data.email || '';
  document.getElementById('c_email').value      = email;
  document.getElementById('c_nom').value        = data.nom       || '';
  document.getElementById('c_prenom').value     = data.prenom    || '';
  document.getElementById('c_naissance').value  = data.naissance || '';
  document.getElementById('c_pays').value       = data.pays      || '';
  document.getElementById('c_adresse').value    = data.adresse   || '';
  document.getElementById('c_telephone').value  = data.telephone || '';
  document.getElementById('c_rib').value        = data.rib       || '';
  document.getElementById('c_bic').value        = data.bic       || '';
  document.getElementById('c-title').textContent = email;

  // Bouton copie email
  const copyBtn = document.getElementById('copy-email-btn');
  copyBtn.onclick = () => {
    navigator.clipboard.writeText(email).then(() => {
      copyBtn.innerHTML = '<i class="bi bi-clipboard-check me-1"></i><span>Copié !</span>';
      setTimeout(() => { copyBtn.innerHTML = '<i class="bi bi-clipboard me-1"></i><span>Copier</span>'; }, 1800);
    });
  };

  new bootstrap.Modal(document.getElementById('contactModal')).show();
}

// ── Modal suppression ──────────────────────────────────────
function confirmDelete(prefix, fullEmail) {
  document.getElementById('del-prefix').value       = prefix;
  document.getElementById('del-display').textContent = fullEmail;
  new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// ── Pagination + Recherche ─────────────────────────────────
(function () {
  const table   = document.getElementById('email-table');
  if (!table) return;
  const search  = document.getElementById('email-search');
  const perPage = document.getElementById('per-page');
  const nav     = document.getElementById('pagination-nav');
  const info    = document.getElementById('pagination-info');
  const badge   = document.getElementById('count-badge');
  let currentPage = 1;

  function allRows()  { return Array.from(table.querySelectorAll('tbody .email-row')); }
  function filtered() {
    const q = (search?.value || '').toLowerCase().trim();
    return allRows().filter(r => !q || r.dataset.email.includes(q));
  }

  function render() {
    const rows  = filtered();
    const pp    = parseInt(perPage?.value || '20');
    const total = rows.length;
    const pages = pp === 0 ? 1 : Math.ceil(total / pp);
    if (currentPage > pages) currentPage = 1;

    allRows().forEach(r => r.style.display = 'none');
    const start = pp === 0 ? 0 : (currentPage - 1) * pp;
    const end   = pp === 0 ? total : start + pp;
    rows.slice(start, end).forEach(r => r.style.display = '');

    const from = total === 0 ? 0 : start + 1;
    const to   = Math.min(end, total);
    if (info)  info.textContent  = `${from}–${to} sur ${total}`;
    if (badge) badge.textContent = total;

    if (!nav) return;
    nav.innerHTML = '';
    if (pages <= 1 && pp !== 0) return;

    const mkLi = (label, page, disabled, active) => {
      const li = document.createElement('li');
      li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');
      li.innerHTML = `<a class="page-link" href="#">${label}</a>`;
      if (!disabled && !active) li.addEventListener('click', e => { e.preventDefault(); currentPage = page; render(); });
      return li;
    };

    nav.appendChild(mkLi('‹', currentPage - 1, currentPage === 1));
    let pStart = Math.max(1, currentPage - 2);
    let pEnd   = Math.min(pages, pStart + 4);
    if (pEnd - pStart < 4) pStart = Math.max(1, pEnd - 4);
    if (pStart > 1) { nav.appendChild(mkLi(1, 1)); if (pStart > 2) nav.appendChild(mkLi('…', null, true)); }
    for (let p = pStart; p <= pEnd; p++) nav.appendChild(mkLi(p, p, false, p === currentPage));
    if (pEnd < pages) { if (pEnd < pages - 1) nav.appendChild(mkLi('…', null, true)); nav.appendChild(mkLi(pages, pages)); }
    nav.appendChild(mkLi('›', currentPage + 1, currentPage === pages));
  }

  search?.addEventListener('input',   () => { currentPage = 1; render(); });
  perPage?.addEventListener('change', () => { currentPage = 1; render(); });
  render();
})();
</script>
</body>
</html>
