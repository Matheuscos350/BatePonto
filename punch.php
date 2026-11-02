<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$user = require_login();
$uid = (int)$user['id'];

$last = last_punch($uid);
$allowed = allowed_punch_types($last);
$sites = active_sites();
$defaultSite = (int)($last['site_id'] ?? 0);

$week = summarize_week($uid, week_start('today'), (float)$user['weekly_hours']);
$barPct = $week['limit_sec'] > 0 ? min(100, $week['overtime'] / $week['limit_sec'] * 100) : 0;
$barColor = $week['status'] === 'limit' ? 'var(--bad)' : ($week['status'] === 'overtime' ? 'var(--warn)' : 'var(--ok)');

$today = db()->prepare("SELECT * FROM punches WHERE user_id = ? AND punched_at >= ? ORDER BY punched_at");
$today->execute([$uid, date('Y-m-d 00:00:00')]);
$todayRows = $today->fetchAll();

page_header('Bater ponto', $user);
flash_html();
?>
<div class="card">
  <h2>Bater ponto <span class="muted small">— <?= h(current_state_label($last)) ?></span></h2>
  <div class="row">
    <div>
      <video id="video" autoplay playsinline muted></video>
      <p><button type="button" class="btn sec" id="flip">Trocar câmera</button></p>
    </div>
    <div>
      <?php if (!$sites && !ALLOW_FREE_SITE_NAME): ?><div class="err">Nenhuma obra cadastrada. Peça ao administrador para cadastrar em “Obras”.</div><?php endif; ?>
      <label for="siteName">Obra (local de trabalho)</label>
      <input id="siteName" list="sitesList" maxlength="120" autocomplete="off" placeholder="<?= ALLOW_FREE_SITE_NAME ? 'Digite o nome. Ex.: Mercado Livre' : 'Escolha a obra' ?>" value="<?= h((string)($last['site_name'] ?? '')) ?>">
      <datalist id="sitesList"><?php foreach ($sites as $s): ?><option value="<?= h($s['name']) ?>"><?php endforeach; ?></datalist>
      <p class="muted small" id="siteAddr" style="margin:4px 0 0"></p>
      <input id="siteAddress" maxlength="250" placeholder="Endereço da obra (opcional)" style="display:none;margin-top:6px">
      <p class="small" style="margin:6px 0"><a href="minhas_obras.php">Corrigir o nome de uma obra que você cadastrou</a></p>
      <p><b>Localização:</b> <span id="geoStatus" class="muted">obtendo…</span></p>
      <p><b>Câmera:</b> <span id="camStatus" class="muted">iniciando…</span></p>
      <div id="msg"></div>
      <div class="acts">
        <?php foreach ($allowed as $t): ?>
          <button type="button" class="punchBtn t-<?= h($t) ?>" data-type="<?= h($t) ?>" disabled><?= h(PUNCH_LABELS[$t]) ?></button>
        <?php endforeach; ?>
      </div>
      <p class="muted small">A foto, a localização e a obra são registradas junto com o horário do servidor.</p>
      <p class="small">Esqueceu de bater ou errou? <a href="chamados.php">Abra um chamado</a> — só o administrador altera o ponto.</p>
    </div>
  </div>
  <canvas id="canvas" hidden></canvas>
</div>

<div class="card">
  <h2>Semana atual (<?= $week['start']->format('d/m') ?> a <?= $week['end']->format('d/m') ?>)</h2>
  <p>
    Trabalhado (sem almoço): <b><?= fmt_hm($week['total']) ?></b><?= $week['abono'] ? ' (+ ' . fmt_hm($week['abono']) . ' abonadas)' : '' ?> de <?= fmt_hm($week['contracted']) ?> •
    Horas extras: <b><?= fmt_hm($week['overtime']) ?></b> de <?= fmt_hm($week['limit_sec']) ?> permitidas (<?= OVERTIME_LIMIT_PERCENT ?>%)
  </p>
  <div class="bar"><i style="width:<?= number_format($barPct, 1, '.', '') ?>%;background:<?= $barColor ?>"></i></div>
  <p><span class="badge b-<?= h($week['status']) ?>"><?= h(status_label($week)) ?></span>
     <?php if ($week['open']): ?><span class="muted"> (jornada em andamento)</span><?php endif; ?></p>
  <?php $rate = hourly_rate($user); ?>
  <?php if ($rate !== null): ?>
  <p>Valor da hora: <b><?= h(money($rate)) ?></b> • Horas extras (+<?= OVERTIME_PREMIUM_PERCENT ?>%): <b><?= h(money((float)overtime_pay($week, $user))) ?></b>
     <span class="muted small">(estimativa bruta desta semana)</span></p>
  <?php else: ?><p class="muted small">O administrador ainda não informou seu salário, por isso não há estimativa em R$.</p><?php endif; ?>
</div>

<div class="card">
  <h2>Marcações de hoje</h2>
  <?php if (!$todayRows): ?><p class="muted">Nenhuma marcação hoje.</p><?php else: ?>
  <table>
    <tr><th>Hora</th><th>Tipo</th><th>Obra / endereço</th><th>Local GPS</th><th>Foto</th></tr>
    <?php foreach ($todayRows as $r): ?>
    <tr>
      <td><?= h(substr($r['punched_at'], 11, 8)) ?></td>
      <td><?= h(PUNCH_LABELS[$r['type']] ?? $r['type']) ?></td>
      <td><?= h($r['site_name'] ?: '—') ?><br><span class="muted small"><?= h($r['site_address']) ?></span></td>
      <td><?php if ($r['lat'] !== null): ?><a target="_blank" rel="noopener" href="<?= h(gmaps_url((float)$r['lat'], (float)$r['lng'])) ?>">ver no Google Maps</a><?php if (!empty($r['geo_address'])): ?><br><span class="muted small"><?= h($r['geo_address']) ?></span><?php endif; ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td><a target="_blank" href="photo.php?id=<?= (int)$r['id'] ?>">ver foto</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const LABELS = <?= json_encode(PUNCH_LABELS, JSON_UNESCAPED_UNICODE) ?>;
const video = document.getElementById('video'), canvas = document.getElementById('canvas');
const btns = [...document.querySelectorAll('.punchBtn')], msg = document.getElementById('msg');
const siteName = document.getElementById('siteName'), siteAddr = document.getElementById('siteAddr'), siteAddress = document.getElementById('siteAddress');
const FREE = <?= json_encode(ALLOW_FREE_SITE_NAME) ?>;
const SITES = <?= json_encode(array_map(fn($s) => ['n' => norm_site_name($s['name']), 'a' => $s['address']], $sites), JSON_UNESCAPED_UNICODE) ?>;
let stream = null, facing = 'user', camOk = false, geoOk = false, busy = false;

function norm(s) { return s.trim().replace(/\s+/g, ' ').toLowerCase(); }
function siteMatch() { return SITES.find(x => x.n === norm(siteName.value)); }
function siteOk() { return siteName.value.trim().length >= 2 && (FREE || !!siteMatch()); }
function refresh() { const ok = camOk && geoOk && !busy && siteOk(); btns.forEach(b => b.disabled = !ok); }
function show(text, ok) { msg.innerHTML = '<div class="' + (ok ? 'okmsg' : 'err') + '"></div>'; msg.firstChild.textContent = text; }
function updAddr() {
  const m = siteMatch(), typed = siteName.value.trim().length >= 2;
  if (m) { siteAddr.textContent = m.a ? 'Endereço: ' + m.a : ''; siteAddress.style.display = m.a ? 'none' : (typed ? '' : 'none'); }
  else if (typed) { siteAddr.textContent = FREE ? 'Obra nova: será cadastrada com este nome.' : 'Escolha uma obra da lista.'; siteAddress.style.display = FREE ? '' : 'none'; }
  else { siteAddr.textContent = ''; siteAddress.style.display = 'none'; }
  refresh();
}
siteName.addEventListener('input', updAddr); updAddr();

async function startCam() {
  if (stream) stream.getTracks().forEach(t => t.stop());
  camOk = false; refresh();
  try {
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing, width: { ideal: 960 }, height: { ideal: 720 } }, audio: false });
    video.srcObject = stream; camOk = true;
    document.getElementById('camStatus').textContent = 'pronta';
  } catch (e) {
    document.getElementById('camStatus').textContent = 'indisponível — permita o acesso à câmera (é necessário HTTPS)';
  }
  refresh();
}

function getPosition() {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) return reject(new Error('Geolocalização não suportada.'));
    navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  });
}

async function checkGeo() {
  const el = document.getElementById('geoStatus');
  try {
    const p = await getPosition();
    geoOk = true;
    el.textContent = p.coords.latitude.toFixed(5) + ', ' + p.coords.longitude.toFixed(5) + ' (±' + Math.round(p.coords.accuracy) + ' m)';
    fetch('geo_lookup.php?lat=' + p.coords.latitude + '&lng=' + p.coords.longitude, {headers: {'X-Requested-With': 'fetch'}})
      .then(r => r.json()).then(j => { if (j.ok && j.address) { const s = document.createElement('div'); s.className = 'muted small'; s.textContent = '📍 ' + j.address; el.parentNode.appendChild(s); } })
      .catch(() => {});
  } catch (e) {
    geoOk = false;
    el.textContent = 'indisponível — permita o acesso à localização (é necessário HTTPS)';
  }
  refresh();
}

document.getElementById('flip').onclick = () => { facing = facing === 'user' ? 'environment' : 'user'; startCam(); };

btns.forEach(b => b.onclick = async () => {
  const type = b.dataset.type;
  busy = true; refresh(); msg.innerHTML = '';
  try {
    const pos = await getPosition(); // posição fresca no momento do clique
    const vw = video.videoWidth || 640, vh = video.videoHeight || 480;
    const scale = Math.min(1, 960 / vw);
    canvas.width = Math.round(vw * scale); canvas.height = Math.round(vh * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    const photo = canvas.toDataURL('image/jpeg', 0.7);

    const res = await fetch('api_punch.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ csrf: CSRF, type, site_name: siteName.value, site_address: siteAddress.value, lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy, photo })
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Erro ao registrar.');
    show(data.label + ' registrada às ' + data.time + ' — ' + data.site + '.', true);
    setTimeout(() => location.reload(), 1500);
  } catch (e) {
    busy = false;
    show(e.message || 'Não foi possível bater o ponto.', false);
    refresh();
  }
});

startCam(); checkGeo();
</script>
<?php page_footer();
