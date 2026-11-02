<?php
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

// ====== CONFIGURAÇÕES ======
const DB_FILE = __DIR__ . '/data/ponto.sqlite';
const UPLOAD_DIR = __DIR__ . '/uploads';
const DEFAULT_WEEKLY_HOURS = 44;      // jornada semanal contratada (padrão CLT)
const OVERTIME_LIMIT_PERCENT = 20;    // limite de horas extras semanais (% da jornada semanal)
const MAX_PHOTO_BYTES = 2 * 1024 * 1024;

// Tipos de marcação
const PUNCH_LABELS = [
    'in'        => 'Entrada',
    'lunch_out' => 'Saída para almoço',
    'lunch_in'  => 'Volta do almoço',
    'out'       => 'Saída',
];
const PUNCH_SHORT = ['in' => 'E', 'lunch_out' => 'SA', 'lunch_in' => 'VA', 'out' => 'S'];

const TICKET_TYPES = [
    'adjust'  => 'Reajuste de ponto',
    'medical' => 'Atestado (abono de horas)',
];
const MAX_ATTACH_BYTES = 5 * 1024 * 1024;
const REQUIRE_SIGNUP_APPROVAL = false;  // true = conta criada pelo funcionário só entra depois que o ADM aprovar
const OVERTIME_PREMIUM_PERCENT = 50;    // adicional de hora extra (CLT: mínimo 50%) usado na estimativa em R$
const ALLOW_FREE_SITE_NAME = true;      // funcionário pode digitar o nome da obra (nova obra é cadastrada na hora)
// Chave da Google Maps Platform (ative "Geocoding API" no Google Cloud). Fica só no servidor.
// Pode ser definida aqui ou na variável de ambiente GOOGLE_MAPS_API_KEY. Vazia = sem endereço automático.
const GOOGLE_MAPS_API_KEY = '';
const ALLOW_SELF_REGISTRATION = true;   // página pública de cadastro (o ADM precisa aprovar cada conta)

// Pasta de sessões: se a padrão do servidor não for gravável (comum em hospedagem), usa data/sessions.
// Sem isso o login "dá certo" mas a sessão não é guardada e a pessoa volta para a tela de login.
$sp = session_save_path() ?: sys_get_temp_dir();
if (!is_dir($sp) || !is_writable($sp)) {
    $own = __DIR__ . '/data/sessions';
    if (!is_dir($own)) @mkdir($own, 0770, true);
    if (is_dir($own) && is_writable($own)) session_save_path($own);
}
$https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
session_set_cookie_params([
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $https,
]);
session_start();

// ====== BANCO ======
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    foreach ([dirname(DB_FILE), UPLOAD_DIR] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0750, true);
    }
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        login TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'employee',
        weekly_hours REAL NOT NULL DEFAULT " . DEFAULT_WEEKLY_HOURS . ",
        active INTEGER NOT NULL DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS sites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        address TEXT NOT NULL,
        active INTEGER NOT NULL DEFAULT 1
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS punches (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        type TEXT NOT NULL,
        punched_at TEXT NOT NULL,
        lat REAL, lng REAL, accuracy REAL,
        photo TEXT,
        ip TEXT,
        site_id INTEGER,
        site_name TEXT,
        site_address TEXT
    )");
    // Migração de bancos da versão anterior (sem obra)
    $cols = array_column($pdo->query('PRAGMA table_info(punches)')->fetchAll(), 'name');
    foreach (['site_id' => 'INTEGER', 'site_name' => 'TEXT', 'site_address' => 'TEXT',
              'adjusted' => 'INTEGER NOT NULL DEFAULT 0', 'adjusted_by' => 'INTEGER', 'geo_address' => 'TEXT'] as $c => $t) {
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE punches ADD COLUMN $c $t");
    }
    // Migração: dados de cadastro e aprovação do funcionário
    $ucols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    foreach (['cpf' => 'TEXT', 'job_title' => 'TEXT', 'approval' => "TEXT NOT NULL DEFAULT 'approved'"] as $c => $t) {
        if (!in_array($c, $ucols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $c $t");
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_cpf ON users(cpf) WHERE cpf IS NOT NULL AND cpf != ''");
    if (!in_array('email', $ucols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN email TEXT');
    foreach (['salary' => 'REAL', 'created_at' => 'TEXT'] as $c => $t) {
        if (!in_array($c, $ucols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN $c $t");
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(lower(email)) WHERE email IS NOT NULL AND email != ''");
    $scols = array_column($pdo->query('PRAGMA table_info(sites)')->fetchAll(), 'name');
    if (!in_array('created_by', $scols, true)) $pdo->exec('ALTER TABLE sites ADD COLUMN created_by INTEGER');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_punch_user_time ON punches(user_id, punched_at)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        type TEXT NOT NULL,
        date_from TEXT NOT NULL,
        date_to TEXT NOT NULL,
        req_type TEXT, req_time TEXT,
        description TEXT NOT NULL,
        file TEXT, file_mime TEXT,
        status TEXT NOT NULL DEFAULT 'pending',
        admin_id INTEGER, admin_note TEXT,
        created_at TEXT NOT NULL, decided_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS abonos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        day TEXT NOT NULL,
        seconds INTEGER NOT NULL,
        ticket_id INTEGER,
        created_by INTEGER,
        created_at TEXT NOT NULL,
        UNIQUE(user_id, day)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS punch_audit (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        punch_id INTEGER, ticket_id INTEGER,
        action TEXT NOT NULL,
        admin_id INTEGER NOT NULL,
        reason TEXT NOT NULL,
        old_json TEXT, new_json TEXT,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id),
        date TEXT NOT NULL,
        supplier TEXT NOT NULL,
        description TEXT NOT NULL,
        department TEXT NOT NULL,
        city TEXT NOT NULL,
        state TEXT NOT NULL,
        project TEXT NOT NULL,
        amount_cents INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses(date, user_id)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS expense_files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        expense_id INTEGER NOT NULL REFERENCES expenses(id) ON DELETE CASCADE,
        file TEXT NOT NULL,
        mime TEXT NOT NULL,
        orig_name TEXT,
        created_at TEXT NOT NULL
    )");

    // Dados iniciais (TROQUE AS SENHAS e edite a obra de exemplo!)
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO users (name, login, password_hash, role, weekly_hours) VALUES (?,?,?,?,?)');
        $ins->execute(['Administrador', 'admin', password_hash('admin123', PASSWORD_DEFAULT), 'admin', DEFAULT_WEEKLY_HOURS]);
        $ins->execute(['Funcionário Exemplo', 'funcionario', password_hash('123456', PASSWORD_DEFAULT), 'employee', DEFAULT_WEEKLY_HOURS]);
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM sites')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO sites (name, address) VALUES (?,?)')
            ->execute(['Obra Exemplo (edite em Obras)', 'Rua Exemplo, 100 - Centro - São Paulo/SP']);
    }
    return $pdo;
}

// ====== HELPERS / SEGURANÇA ======
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function cut(string $s, int $max): string
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 3) . '...' : $s;
    }
    return strlen($s) > $max ? substr($s, 0, $max - 3) . '...' : $s;
}

function google_key(): string
{
    return trim((string)(getenv('GOOGLE_MAPS_API_KEY') ?: GOOGLE_MAPS_API_KEY));
}

/** Link do ponto no Google Maps (não precisa de chave). */
function gmaps_url(float $lat, float $lng): string
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', ''));
}

/** Endereço do ponto via Google Geocoding API (geocodificação reversa). Retorna null se sem chave/sem resposta. */
function reverse_geocode(float $lat, float $lng): ?string
{
    $key = google_key();
    if ($key === '' || !function_exists('curl_init')) return null;
    $ck = round($lat, 4) . ',' . round($lng, 4);                      // cache curto na sessão (~11 m)
    if (isset($_SESSION['geo_cache'][$ck])) return $_SESSION['geo_cache'][$ck] ?: null;
    $url = (getenv('GOOGLE_GEOCODE_URL') ?: 'https://maps.googleapis.com/maps/api/geocode/json') . '?' . http_build_query([
        'latlng' => number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', ''),
        'language' => 'pt-BR', 'result_type' => 'street_address|route|premise|subpremise', 'key' => $key,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3]);
    $body = curl_exec($ch);
    curl_close($ch);
    $j = is_string($body) ? json_decode($body, true) : null;
    $addr = null;
    if (is_array($j) && ($j['status'] ?? '') === 'OK' && !empty($j['results'][0]['formatted_address'])) {
        $addr = mb_substr((string)$j['results'][0]['formatted_address'], 0, 300);
    } elseif (is_array($j) && ($j['status'] ?? '') === 'ZERO_RESULTS') {
        $j2 = null;                                                    // sem endereço exato: tenta sem filtro de tipo
        $ch = curl_init(str_replace('&result_type=street_address%7Croute%7Cpremise%7Csubpremise', '', $url));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3]);
        $b2 = curl_exec($ch); curl_close($ch);
        $j2 = is_string($b2) ? json_decode($b2, true) : null;
        if (is_array($j2) && ($j2['status'] ?? '') === 'OK' && !empty($j2['results'][0]['formatted_address'])) $addr = mb_substr((string)$j2['results'][0]['formatted_address'], 0, 300);
    }
    $_SESSION['geo_cache'][$ck] = $addr ?? '';
    if (count($_SESSION['geo_cache']) > 20) array_shift($_SESSION['geo_cache']);
    return $addr;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(?string $token): void
{
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        // Sessão perdida/expirada: volta para a página anterior com aviso, em vez de um beco sem saída.
        $back = basename(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: 'index.php');
        if (!preg_match('/^[A-Za-z0-9_.-]+\.php$/', $back)) $back = 'index.php';
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página e entre de novo.']));
        }
        unset($_SESSION['csrf']);
        flash('err', 'Sua sessão expirou. Tente novamente.');
        header('Location: ' . $back);
        exit;
    }
}
function current_user(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch() ?: null;
}
function require_login(): array
{
    $u = current_user();
    if (!$u) { header('Location: index.php'); exit; }
    return $u;
}
function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') { http_response_code(403); exit('Acesso restrito ao administrador.'); }
    return $u;
}

function fmt_hm(int $sec): string
{
    $neg = $sec < 0; $sec = abs($sec);
    return ($neg ? '-' : '') . sprintf('%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60));
}
function dia_semana(string $ymd): string
{
    return ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'][(int)date('w', strtotime($ymd))];
}

// ====== SEQUÊNCIA DE MARCAÇÕES ======
function last_punch(int $uid): ?array
{
    $st = db()->prepare('SELECT * FROM punches WHERE user_id = ? ORDER BY punched_at DESC, id DESC LIMIT 1');
    $st->execute([$uid]);
    return $st->fetch() ?: null;
}

/** Quais marcações são permitidas agora, dado a última marcação. */
function allowed_punch_types(?array $last): array
{
    if (!$last || $last['type'] === 'out') return ['in'];
    $next = match ($last['type']) {
        'in'        => ['lunch_out', 'out'],
        'lunch_out' => ['lunch_in'],
        'lunch_in'  => ['out'],
        default     => ['in'],
    };
    // Esqueceu de bater a saída em um dia anterior: permite iniciar uma nova jornada.
    if (substr($last['punched_at'], 0, 10) !== date('Y-m-d')) $next[] = 'in';
    return $next;
}

function current_state_label(?array $last): string
{
    if (!$last || $last['type'] === 'out') return 'Fora do expediente';
    return $last['type'] === 'lunch_out' ? 'Em horário de almoço' : 'Em jornada de trabalho';
}

function active_sites(): array
{
    return db()->query('SELECT * FROM sites WHERE active = 1 ORDER BY name')->fetchAll();
}

// ====== OBRA DIGITADA PELO FUNCIONÁRIO ======
function norm_site_name(string $s): string
{
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/**
 * Encontra a obra pelo nome (ignora maiúsculas e espaços repetidos) ou, se for nova,
 * cadastra na hora com o que o funcionário digitou. Retorna a linha da tabela sites.
 */
function resolve_site(string $name, string $address, int $uid): array
{
    $name = preg_replace('/\s+/u', ' ', trim($name));
    $address = preg_replace('/\s+/u', ' ', trim($address));
    if (strlen($name) < 2 || strlen($name) > 120) throw new RuntimeException('Informe o nome da obra (ex.: Mercado Livre).');
    if (strlen($address) > 250) throw new RuntimeException('Endereço muito longo (máx. 250 caracteres).');

    $key = norm_site_name($name);
    foreach (db()->query('SELECT * FROM sites')->fetchAll() as $s) {
        if (norm_site_name($s['name']) !== $key) continue;
        if (!$s['active']) throw new RuntimeException('Esta obra está desativada. Procure o administrador.');
        if ($s['address'] === '' && $address !== '') { // completa o endereço se a obra estava sem
            db()->prepare('UPDATE sites SET address = ? WHERE id = ?')->execute([$address, $s['id']]);
            $s['address'] = $address;
        }
        return $s;
    }
    if (!ALLOW_FREE_SITE_NAME) throw new RuntimeException('Obra não cadastrada. Escolha uma obra da lista.');

    db()->prepare('INSERT INTO sites (name, address, created_by) VALUES (?,?,?)')->execute([$name, $address, $uid]);
    $st = db()->prepare('SELECT * FROM sites WHERE id = ?');
    $st->execute([(int)db()->lastInsertId()]);
    return $st->fetch();
}

// ====== CADASTRO DE FUNCIONÁRIOS ======
function valid_cpf(string $cpf): bool
{
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) $sum += (int)$cpf[$i] * (($t + 1) - $i);
        if ((int)$cpf[$t] !== ((10 * $sum) % 11) % 10) return false;
    }
    return true;
}
function money(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}
/** Aceita "2.640,00", "2640,50", "2640.50", "2.640", "R$ 2640". */
function parse_money(string $s): float
{
    $s = trim(str_replace(['R$', ' '], '', $s));
    if ($s === '') return 0.0;
    if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s));
    elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    return is_numeric($s) ? round((float)$s, 2) : 0.0;
}
/** Valor da hora = salário mensal ÷ (jornada semanal × 5). Ex.: 44h → ÷220. Null se o salário não foi informado. */
function hourly_rate(array $user): ?float
{
    $sal = (float)($user['salary'] ?? 0);
    $wh = (float)($user['weekly_hours'] ?? 0);
    return ($sal > 0 && $wh > 0) ? $sal / ($wh * 5) : null;
}
/** Estimativa bruta das horas extras da semana: horas × valor da hora × (1 + adicional). */
function overtime_pay(array $week, array $user): ?float
{
    $r = hourly_rate($user);
    return $r === null ? null : round($week['overtime'] / 3600 * $r * (1 + OVERTIME_PREMIUM_PERCENT / 100), 2);
}
function valid_email(string $e): bool
{
    return strlen($e) <= 120 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}
function fmt_cpf(?string $cpf): string
{
    $c = preg_replace('/\D/', '', (string)$cpf);
    return strlen($c) === 11 ? substr($c, 0, 3) . '.' . substr($c, 3, 3) . '.' . substr($c, 6, 3) . '-' . substr($c, 9) : '—';
}
function pending_signups_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM users WHERE approval = 'pending'")->fetchColumn();
}

// ====== FLASH / AUDITORIA / CHAMADOS ======
function flash(?string $kind = null, ?string $msg = null): ?array
{
    if ($kind !== null) { $_SESSION['flash'] = [$kind, (string)$msg]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
function flash_html(): void
{
    if ($f = flash()) echo '<div class="' . ($f[0] === 'ok' ? 'okmsg' : 'err') . '">' . h($f[1]) . '</div>';
}
function valid_date($d): bool
{
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false && date('Y-m-d', strtotime($d)) === $d;
}

function audit_log(int $uid, string $action, int $adminId, string $reason, ?array $old, ?array $new, ?int $punchId = null, ?int $ticketId = null): void
{
    db()->prepare('INSERT INTO punch_audit (user_id, punch_id, ticket_id, action, admin_id, reason, old_json, new_json, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$uid, $punchId, $ticketId, $action, $adminId, $reason,
            $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
            $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            date('Y-m-d H:i:s')]);
}
function audit_list(int $uid, int $limit = 30): array
{
    $st = db()->prepare('SELECT a.*, u.name AS admin_name FROM punch_audit a LEFT JOIN users u ON u.id = a.admin_id WHERE a.user_id = ? ORDER BY a.id DESC LIMIT ' . (int)$limit);
    $st->execute([$uid]);
    return $st->fetchAll();
}
function audit_summary(?string $json): string
{
    $a = $json ? json_decode($json, true) : null;
    if (!is_array($a)) return '—';
    if (isset($a['type'], $a['punched_at'])) {
        return (PUNCH_LABELS[$a['type']] ?? $a['type']) . ' em ' . date('d/m/Y H:i', strtotime($a['punched_at'])) . (!empty($a['site']) ? ' — ' . $a['site'] : '');
    }
    if (isset($a['days'])) {
        return 'Dias: ' . implode(', ', array_map(fn($d) => date('d/m', strtotime($d)), $a['days'])) . ' (' . ($a['hours'] ?? '?') . 'h/dia)';
    }
    return '—';
}
function audit_action_label(string $a): string
{
    return ['add' => 'Marcação incluída', 'edit' => 'Marcação alterada', 'delete' => 'Marcação excluída',
        'abono_add' => 'Abono por atestado', 'abono_undo' => 'Abono desfeito', 'ticket_reject' => 'Chamado recusado'][$a] ?? $a;
}

function pending_tickets_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM tickets WHERE status = 'pending'")->fetchColumn();
}
function ticket_status_label(string $s): string
{
    return ['pending' => 'Em análise', 'approved' => 'Aprovado', 'rejected' => 'Recusado'][$s] ?? $s;
}

/** Dias que recebem abono: seg–sex do período (se for um único dia, esse dia sempre entra). Máx. 31 dias. */
function abono_dates(string $from, string $to): array
{
    $out = [];
    $d = new DateTimeImmutable($from);
    $end = new DateTimeImmutable($to);
    for ($i = 0; $d <= $end && $i < 31; $i++, $d = $d->modify('+1 day')) {
        if ($from === $to || (int)$d->format('N') <= 5) $out[] = $d->format('Y-m-d');
    }
    return $out;
}

/** Valida e salva um anexo (PDF, JPG ou PNG, até MAX_ATTACH_BYTES). Retorna [nome_arquivo, mime]. */
function save_attachment(array $f): array
{
    return save_attachment_to($f, 'atestados');
}
/** Igual a save_attachment, mas em outra subpasta de uploads (ex.: 'notas'). */
function save_attachment_to(array $f, string $subdir): array
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('Arquivo maior que o permitido pelo servidor.');
    if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Falha no envio do arquivo. Tente novamente.');
    if (($f['size'] ?? 0) > MAX_ATTACH_BYTES || ($f['size'] ?? 0) <= 0) throw new RuntimeException('O arquivo deve ter até ' . (MAX_ATTACH_BYTES / 1048576) . ' MB.');
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Arquivo inválido.');

    $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 8);
    if (strncmp($head, '%PDF', 4) === 0)            { $mime = 'application/pdf'; $ext = 'pdf'; }
    elseif (strncmp($head, "\xFF\xD8\xFF", 3) === 0) { $mime = 'image/jpeg';      $ext = 'jpg'; }
    elseif (strncmp($head, "\x89PNG", 4) === 0)      { $mime = 'image/png';       $ext = 'png'; }
    else throw new RuntimeException('Formato não aceito. Envie PDF, JPG ou PNG.');

    $dir = UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException('Não foi possível salvar o arquivo.');
    return [$name, $mime];
}

// ====== CÁLCULO DE HORAS ======
function week_start(string $date): DateTimeImmutable
{
    return (new DateTimeImmutable($date))->modify('monday this week')->setTime(0, 0);
}

/**
 * Resumo de uma semana (segunda a domingo).
 * Conta como trabalhado: Entrada→Saída almoço e Volta almoço→Saída. O almoço não é contado.
 */
function summarize_week(int $uid, DateTimeImmutable $ws, float $weeklyHours): array
{
    $we = $ws->modify('+6 days');
    $st = db()->prepare('SELECT * FROM punches WHERE user_id = ? AND punched_at >= ? AND punched_at <= ? ORDER BY punched_at, id');
    $st->execute([$uid, $ws->format('Y-m-d 00:00:00'), $we->format('Y-m-d 23:59:59')]);
    $rows = $st->fetchAll();

    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[$ws->modify("+$i days")->format('Y-m-d')] = ['seconds' => 0, 'lunch' => 0, 'abono' => 0, 'events' => [], 'sites' => []];
    }

    $pending = null;      // trecho de trabalho aberto: ['d' => data, 'ts' => int]
    $lunchStart = null;   // ['d' => data, 'ts' => int] quando está em almoço
    $incomplete = false;
    $open = false;
    $now = time();
    $today = date('Y-m-d');

    foreach ($rows as $r) {
        $ts = strtotime($r['punched_at']);
        $d = substr($r['punched_at'], 0, 10);
        $days[$d]['events'][] = ['ts' => $ts, 'type' => $r['type'], 'site' => $r['site_name'], 'addr' => $r['site_address'], 'adj' => (int)($r['adjusted'] ?? 0)];
        if (!empty($r['site_name'])) $days[$d]['sites'][$r['site_name']] = (string)$r['site_address'];

        switch ($r['type']) {
            case 'in':
                if ($pending || $lunchStart) $incomplete = true;
                $pending = ['d' => $d, 'ts' => $ts];
                $lunchStart = null;
                break;
            case 'lunch_out':
                if ($pending) {
                    $days[$pending['d']]['seconds'] += max(0, $ts - $pending['ts']);
                    $pending = null;
                    $lunchStart = ['d' => $d, 'ts' => $ts];
                } else {
                    $incomplete = true;
                }
                break;
            case 'lunch_in':
                if ($lunchStart) {
                    $days[$lunchStart['d']]['lunch'] += max(0, $ts - $lunchStart['ts']);
                } else {
                    $incomplete = true;
                }
                if ($pending) $incomplete = true;
                $pending = ['d' => $d, 'ts' => $ts];
                $lunchStart = null;
                break;
            case 'out':
                if ($pending) {
                    $days[$pending['d']]['seconds'] += max(0, $ts - $pending['ts']);
                    $pending = null;
                } else {
                    $incomplete = true; // saída sem entrada / sem volta do almoço
                }
                $lunchStart = null;
                break;
        }
    }
    if ($pending) {
        if ($pending['d'] === $today) { // jornada em andamento: conta até agora
            $days[$pending['d']]['seconds'] += max(0, $now - $pending['ts']);
            $open = true;
        } else {
            $incomplete = true;
        }
    }
    if ($lunchStart) {
        if ($lunchStart['d'] === $today) $open = true; else $incomplete = true;
    }

    // Abonos (atestado aprovado): creditam horas na semana, mas NÃO geram hora extra.
    $ab = db()->prepare('SELECT day, seconds FROM abonos WHERE user_id = ? AND day >= ? AND day <= ?');
    $ab->execute([$uid, $ws->format('Y-m-d'), $we->format('Y-m-d')]);
    foreach ($ab->fetchAll() as $a) {
        if (isset($days[$a['day']])) $days[$a['day']]['abono'] += (int)$a['seconds'];
    }
    $abono = (int)array_sum(array_column($days, 'abono'));
    $total = array_sum(array_column($days, 'seconds'));
    $contracted = (int)round($weeklyHours * 3600);
    $overtime = max(0, $total - $contracted);
    $percent = $contracted > 0 ? $overtime / $contracted * 100 : 0.0;
    $limitSec = (int)round($contracted * OVERTIME_LIMIT_PERCENT / 100);

    if ($percent >= OVERTIME_LIMIT_PERCENT) $status = 'limit';
    elseif ($overtime > 0) $status = 'overtime';
    else $status = 'ok';

    return [
        'start' => $ws, 'end' => $we, 'days' => $days,
        'total' => $total, 'contracted' => $contracted, 'overtime' => $overtime,
        'percent' => $percent, 'limit_sec' => $limitSec,
        'remaining_to_limit' => max(0, $limitSec - $overtime),
        'status' => $status, 'incomplete' => $incomplete, 'open' => $open,
        'abono' => $abono, 'credited' => $total + $abono,
    ];
}

function status_label(array $w): string
{
    $p = number_format($w['percent'], 1, ',', '.');
    return match ($w['status']) {
        'limit'    => ($w['percent'] > OVERTIME_LIMIT_PERCENT ? 'ULTRAPASSOU' : 'ATINGIU') . " o limite de " . OVERTIME_LIMIT_PERCENT . "% ($p% de extras)",
        'overtime' => "Com horas extras: $p% (abaixo do limite de " . OVERTIME_LIMIT_PERCENT . "%)",
        default    => 'Sem horas extras na semana',
    };
}

/** Relatório de um período: todas as semanas (seg-dom) que tocam o intervalo. */
function report_data(int $uid, string $from, string $to): array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$uid]);
    $user = $st->fetch();
    if (!$user) throw new RuntimeException('Funcionário não encontrado');

    $weeks = [];
    $end = new DateTimeImmutable($to);
    for ($ws = week_start($from); $ws <= $end; $ws = $ws->modify('+7 days')) {
        $weeks[] = summarize_week($uid, $ws, (float)$user['weekly_hours']);
    }
    return ['user' => $user, 'weeks' => $weeks];
}

/** "08:00 Entrada | 12:00 Saída almoço | ..." (curto: "08:00 E | 12:00 SA | ...") */
function events_text(array $events, bool $short = false): string
{
    $out = [];
    foreach ($events as $e) {
        $out[] = date('H:i', $e['ts']) . ' ' . ($short ? (PUNCH_SHORT[$e['type']] ?? '?') : (PUNCH_LABELS[$e['type']] ?? $e['type'])) . (!empty($e['adj']) ? '*' : '');
    }
    return implode(' | ', $out);
}

// ====== LAYOUT ======
function page_header(string $title, ?array $user = null): void
{ ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($title) ?> • Ponto</title>
<meta name="theme-color" content="#1f5eff">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" sizes="32x32" href="icons/favicon-32.png">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Ponto">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<style>
:root{--bg:#f4f6f9;--card:#fff;--ink:#1c2430;--muted:#6b7686;--brand:#1f5eff;--ok:#12a150;--warn:#d98a00;--bad:#d62839;--line:#e3e8ef}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif}
header{background:var(--card);border-bottom:1px solid var(--line);padding:12px 20px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}
header b{font-size:17px}header nav{display:flex;gap:14px;flex:1;flex-wrap:wrap}header a{color:var(--brand);text-decoration:none}
main{max-width:980px;margin:20px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:16px}
h1,h2{margin:0 0 12px}h2{font-size:18px}
label{display:block;font-weight:600;margin:10px 0 4px}
input,select,textarea{width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;font:inherit}
button,.btn{background:var(--brand);color:#fff;border:0;border-radius:8px;padding:11px 18px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block}
button:disabled{opacity:.5;cursor:not-allowed}.btn.sec{background:#fff;color:var(--brand);border:1px solid var(--brand)}
.acts{display:grid;gap:10px;margin-top:12px}.acts button{width:100%;font-size:18px;padding:14px}
.t-in{background:var(--ok)}.t-lunch_out{background:var(--warn)}.t-lunch_in{background:#7a4bd6}.t-out{background:var(--bad)}
table{width:100%;border-collapse:collapse}th,td{padding:8px;border-bottom:1px solid var(--line);text-align:left;font-size:14px;vertical-align:top}
th{color:var(--muted);font-weight:600}.muted{color:var(--muted)}.small{font-size:13px}
.badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:13px;font-weight:600;color:#fff}
.b-ok,.b-approved{background:var(--ok)}.b-overtime,.b-pending{background:var(--warn)}.b-limit,.b-rejected{background:var(--bad)}
.bar{height:12px;background:var(--line);border-radius:99px;overflow:hidden}.bar>i{display:block;height:100%;background:var(--ok)}
.err{background:#fde8ea;color:var(--bad);padding:10px;border-radius:8px;margin-bottom:12px}
.okmsg{background:#e5f6ec;color:var(--ok);padding:10px;border-radius:8px;margin-bottom:12px}
.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
video,canvas{width:100%;max-height:360px;background:#000;border-radius:10px;object-fit:cover}
html{-webkit-text-size-adjust:100%}button,.btn,a{-webkit-tap-highlight-color:transparent}
header{padding-top:max(12px,env(safe-area-inset-top));padding-left:max(20px,env(safe-area-inset-left));padding-right:max(20px,env(safe-area-inset-right))}
main{padding-bottom:max(16px,env(safe-area-inset-bottom))}
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
#installBtn{display:none;padding:6px 12px;font-size:14px}
#iosHint{display:none;margin:0 auto;max-width:980px;padding:10px 16px;font-size:14px;background:#eef3ff;border-bottom:1px solid var(--line)}
@media (max-width:640px){
  header{gap:8px 12px;padding-left:max(12px,env(safe-area-inset-left));padding-right:max(12px,env(safe-area-inset-right))}
  header nav{flex:1 1 100%;order:3;flex-wrap:nowrap;overflow-x:auto;gap:16px;padding:4px 0 2px;white-space:nowrap;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  header nav::-webkit-scrollbar{display:none}
  header nav a{padding:6px 0;font-weight:600}
  input,select,textarea{font-size:16px}          /* evita o zoom automático do iPhone ao focar */
  button,.btn{min-height:44px}
  .card{padding:14px;border-radius:10px}
  main{margin:12px auto}
  table{display:block;overflow-x:auto;-webkit-overflow-scrolling:touch}
}
@media (display-mode:standalone){body{overscroll-behavior-y:contain}}
</style>
</head>
<body>
<header>
  <b>⏱ Ponto Eletrônico</b> <button type="button" id="installBtn" class="btn sec">📲 Instalar app</button>
  <?php if ($user): ?>
  <nav>
    <a href="punch.php">Bater ponto</a>
    <a href="report.php">Relatórios / PDF</a>
    <a href="gastos.php">Relatório de Gastos</a>
    <?php if ($user['role'] === 'admin'): $pc = pending_tickets_count(); $pu = pending_signups_count(); ?>
    <a href="chamados_admin.php">Chamados<?= $pc ? " ($pc)" : '' ?></a><a href="adjust.php">Ajustar ponto</a><a href="admin.php">Funcionários<?= $pu ? " ($pu)" : '' ?></a><a href="sites.php">Obras</a>
    <?php else: ?><a href="chamados.php">Meus chamados</a><a href="minhas_obras.php">Minhas obras</a><?php endif; ?>
  </nav>
  <span class="muted"><?= h($user['name']) ?> • <a href="logout.php">Sair</a></span>
  <?php endif; ?>
</header>
<div id="iosHint">📲 Para instalar no iPhone: toque em <b>Compartilhar</b> <span aria-hidden="true">⎙</span> e depois em <b>Adicionar à Tela de Início</b>. <a href="#" id="iosHintX" style="float:right">fechar</a></div>
<script>
(function () {
  if ('serviceWorker' in navigator) window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  let dp = null; const btn = document.getElementById('installBtn');
  window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); dp = e; if (!standalone) btn.style.display = 'inline-block'; });
  btn.onclick = async () => { if (!dp) return; dp.prompt(); await dp.userChoice.catch(() => {}); dp = null; btn.style.display = 'none'; };
  window.addEventListener('appinstalled', () => { btn.style.display = 'none'; });
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  let hidden = false; try { hidden = localStorage.getItem('iosHintX') === '1'; } catch (e) {}
  if (ios && !standalone && !hidden) {
    const h = document.getElementById('iosHint'); h.style.display = 'block';
    document.getElementById('iosHintX').onclick = ev => { ev.preventDefault(); h.style.display = 'none'; try { localStorage.setItem('iosHintX', '1'); } catch (e) {} };
  }
})();
</script>
<main>
<?php }

function page_footer(): void { echo "</main></body></html>"; }
