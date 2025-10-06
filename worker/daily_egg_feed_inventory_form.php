<?php
// worker/daily_egg_feed_inventory_form.php
// Form I-102 — Daily Egg Production, Feeds, Layer Inventory Report
// Desktop: table fills dashboard width (100%).
// Mobile (≤576px): same table, automatically scaled smaller to fit the screen (no horizontal scroll, no stacked layout).
// Building # is fixed (not editable). Submits to admin.

require_once __DIR__ . '/inc/common.php';

if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }

$PAGE_TITLE = 'Form I-102 (Daily Report)';
$CURRENT    = 'daily_egg_feed_inventory_form.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$CSRF = $_SESSION['csrf'];

$rows = range(1, 20); // fixed building numbers

// BASE for actions/redirects (no hard-coded leading slash)
$BASE = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

include __DIR__ . '/inc/layout_head.php';

$ok    = isset($_GET['ok']);
$error = isset($_GET['err']) ? $_GET['err'] : '';
?>
<style>
  /* Make the card span full dashboard width */
  .i102-wrap{ width:100%; max-width:100%; margin:0; }

  .i102-meta{ display:flex; gap:12px; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; }
  .i102-meta .left{ display:flex; gap:12px; align-items:center; flex-wrap:wrap; }
  .i102-legend{ font-size:.9rem; color:#6c757d; }

  @media (max-width: 576px){
    .card-header.d-flex{ flex-direction:column; align-items:flex-start; gap:4px; }
  }

  /* ===== Table fits container width ===== */
  .i102-fit{
    position:relative;
    width:100%;
    transform-origin: top left;  /* so we can scale on mobile */
    will-change: transform;
  }

  .i102-table{
    width:100%;
    margin:0;
    border-collapse:collapse;
    table-layout:fixed;                /* force columns to share available width */
    font-size:clamp(11px, 0.85vw, 13px);
    line-height:1.25;
  }
  .i102-table th,.i102-table td{
    border:1px solid #e3e7eb;
    padding:6px 8px;                   /* tighter padding so everything fits */
    text-align:center;
    background:#fff;
  }
  .i102-table thead th{
    background:#f8fafc;
    vertical-align:middle;
    white-space:normal;                /* allow wrapping when needed */
    word-break:keep-all;
  }
  .i102-table thead abbr{ text-decoration:none; border-bottom:1px dotted #adb5bd; cursor:help; }
  .i102-table tfoot td{ background:#fafafa; font-weight:700; }

  /* Inputs */
  .i102-in{ width:100%; border:0; outline:0; background:transparent; text-align:right; padding:0; font-size:inherit; }
  .i102-in:focus{ outline:2px solid #cfe3ff; outline-offset:-2px; border-radius:4px; background:#fff; }
  .i102-in[readonly]{ color:#6c757d; }

  /* Bldg column (fixed text) — tighter */
  .i102-table .bldg{ width:36px; min-width:36px; max-width:40px; padding:6px 4px; font-weight:700; }

  .i102-note{ min-height:70px; }
  .sticky-submit{
    position: sticky; bottom:-1px; z-index:5; background:#fff; border-top:1px solid #e7eaef;
    padding:10px 12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;
  }

  /* We are NOT changing layout on mobile; we auto-scale via JS.
     Just make fonts/padding a hair smaller as a baseline. */
  @media (max-width: 576px){
    .i102-table{ font-size:11px; }
    .i102-table th,.i102-table td{ padding:5px 6px; }
  }

  /* Keep any modal above sticky toolbars/sidebars (consistency across pages) */
  :root{ --pm-z-backdrop: 2040; --pm-z-modal: 2050; }
  .modal-backdrop{ z-index: var(--pm-z-backdrop) !important; }
  .modal{ z-index: var(--pm-z-modal) !important; }
  body.modal-open{ overflow: hidden; }

  /* Print: natural width */
  @media print{
    .sticky-submit, .alert{ display:none !important; }
    .card{ box-shadow:none !important; }
    .content{ padding-top:0 !important; }
    .i102-fit{ transform:none !important; height:auto !important; }
    .i102-table{ width:100% !important; font-size:11px !important; }
  }
</style>

<div class="i102-wrap">
  <?php if ($ok): ?>
    <div class="alert alert-success d-flex align-items-center" role="alert">
      <i class="fa-solid fa-circle-check me-2"></i>
      <div>Report submitted to admin. Thank you!</div>
    </div>
  <?php elseif ($error): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
      <i class="fa-solid fa-triangle-exclamation me-2"></i>
      <div><?php echo h($error); ?></div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
      <div class="d-flex align-items-center gap-2">
        <i class="fa-solid fa-table-list text-warning"></i>
        <strong>Daily Egg Production, Feeds, Layer Inventory Report</strong>
        <span class="text-muted small">Form I-102</span>
      </div>
      <div class="i102-legend">Fill out the fields below and submit to admin.</div>
    </div>

    <form id="i102Form" method="post" action="<?= h($BASE) ?>/daily_egg_feed_inventory_submit.php" class="card-body" autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?php echo h($CSRF); ?>">
      <input type="hidden" name="payload" id="payload">

      <div class="i102-meta">
        <div class="left">
          <div class="col-auto">
            <label for="i102Date" class="form-label fw-semibold mb-1">Date</label>
            <input type="date" id="i102Date" name="report_date" class="form-control form-control-sm" style="min-width:190px;" required>
          </div>
          <div class="col-auto">
            <label for="i102Farm" class="form-label fw-semibold mb-1">Farm/Location (optional)</label>
            <input type="text" id="i102Farm" name="farm" class="form-control form-control-sm" placeholder="e.g., Farm A">
          </div>
        </div>
      </div>

      <div class="i102-fit" id="i102Fit">
        <table class="i102-table" id="i102Table" aria-label="Form I-102 table">
          <thead>
            <tr>
              <th class="bldg" rowspan="2">Bldg</th>
              <th colspan="4">Daily Egg Production</th>
              <th colspan="2">Feeds</th>
              <th colspan="8">Layer Inventory</th>
            </tr>
            <tr>
              <!-- Eggs -->
              <th><abbr title="AM">AM</abbr></th>
              <th><abbr title="PM">PM</abbr></th>
              <th><abbr title="3:45 PM">3:45</abbr></th>
              <th><abbr title="Total Eggs">Total</abbr></th>

              <!-- Feeds -->
              <th><abbr title="Number of Sacks"># sacks</abbr></th>
              <th><abbr title="Total Feed">Total</abbr></th>

              <!-- Layer -->
              <th><abbr title="Beginning Balance">Beg&nbsp;Bal</abbr></th>
              <th><abbr title="Mortality">M</abbr></th>
              <th><abbr title="Rejects">Rejects</abbr></th>
              <th><abbr title="Water Bag">Water&nbsp;Bag</abbr></th>
              <th><abbr title="Cull">Cull</abbr></th>
              <th><abbr title="Old">OLD</abbr></th>
              <th><abbr title="Total">Total</abbr></th>
              <th><abbr title="Balance">Balance</abbr></th>
            </tr>
          </thead>

          <tbody id="i102Body">
            <?php foreach ($rows as $r): ?>
            <tr>
              <!-- Fixed building number (not editable) -->
              <td class="bldg">
                <span><?php echo (int)$r; ?></span>
                <input type="hidden" name="bldg[]" value="<?php echo (int)$r; ?>">
              </td>

              <!-- Eggs -->
              <td><input class="i102-in" data-col="am"        inputmode="numeric" pattern="[0-9]*" aria-label="AM eggs"></td>
              <td><input class="i102-in" data-col="pm"        inputmode="numeric" pattern="[0-9]*" aria-label="PM eggs"></td>
              <td><input class="i102-in" data-col="pm345"     inputmode="numeric" pattern="[0-9]*" aria-label="3:45 eggs"></td>
              <td><input class="i102-in" data-col="egg_total" readonly tabindex="-1" aria-label="Total eggs"></td>

              <!-- Feeds -->
              <td><input class="i102-in" data-col="sacks"     inputmode="numeric" pattern="[0-9]*" aria-label="Feed sacks"></td>
              <td><input class="i102-in" data-col="feed_tot"  inputmode="numeric" pattern="[0-9]*" aria-label="Feed total"></td>

              <!-- Layer -->
              <td><input class="i102-in" data-col="beg_bal"   inputmode="numeric" pattern="[0-9]*" aria-label="Beginning balance"></td>
              <td><input class="i102-in" data-col="M"         inputmode="numeric" pattern="[0-9]*" aria-label="Mortality"></td>
              <td><input class="i102-in" data-col="rejects"   inputmode="numeric" pattern="[0-9]*" aria-label="Rejects"></td>
              <td><input class="i102-in" data-col="waterbag"  inputmode="numeric" pattern="[0-9]*" aria-label="Water bag"></td>
              <td><input class="i102-in" data-col="cull"      inputmode="numeric" pattern="[0-9]*" aria-label="Cull"></td>
              <td><input class="i102-in" data-col="old"       inputmode="numeric" pattern="[0-9]*" aria-label="Old"></td>
              <td><input class="i102-in" data-col="inv_tot"   inputmode="numeric" pattern="[0-9]*" aria-label="Inventory total"></td>
              <td><input class="i102-in" data-col="balance"   inputmode="numeric" pattern="[0-9]*" aria-label="Balance"></td>
            </tr>
            <?php endforeach; ?>
          </tbody>

          <tfoot>
            <tr>
              <td class="text-start fw-bold">TOTAL:</td>

              <!-- Eggs -->
              <td id="sum_am"      class="text-end">0</td>
              <td id="sum_pm"      class="text-end">0</td>
              <td id="sum_pm345"   class="text-end">0</td>
              <td id="sum_egg"     class="text-end">0</td>

              <!-- Feeds -->
              <td id="sum_sacks"   class="text-end">0</td>
              <td id="sum_feed"    class="text-end">0</td>

              <!-- Layer -->
              <td id="sum_begbal"  class="text-end">0</td>
              <td id="sum_M"       class="text-end">0</td>
              <td id="sum_rejects" class="text-end">0</td>
              <td id="sum_water"   class="text-end">0</td>
              <td id="sum_cull"    class="text-end">0</td>
              <td id="sum_old"     class="text-end">0</td>
              <td id="sum_inv"     class="text-end">0</td>
              <td id="sum_balance" class="text-end">0</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="row g-3 mt-2">
        <div class="col-12">
          <label for="remarks" class="form-label fw-semibold">Remarks (optional)</label>
          <textarea id="remarks" name="remarks" class="form-control i102-note" placeholder="Notes for admin..."></textarea>
        </div>
      </div>

      <div class="sticky-submit mt-3 rounded-bottom">
        <div class="small text-muted">
          Review before submitting. Admin will receive your report and can review totals.
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-warning">
            <i class="fa-solid fa-paper-plane me-1"></i>Submit to Admin
          </button>
          <button type="reset" class="btn btn-outline-secondary">Clear</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
$PAGE_FOOT_SCRIPTS = <<<JS
<script>
(function(){
  var body  = document.getElementById('i102Body');
  var fit   = document.getElementById('i102Fit');
  var table = document.getElementById('i102Table');

  /* ---------- helpers ---------- */
  function toNum(v){ if(v==null) return 0; v=String(v).replace(/,/g,'').trim(); return v===''||isNaN(+v)?0:+v; }

  function rowTotal(tr){
    var am=toNum(tr.querySelector('[data-col="am"]')?.value);
    var pm=toNum(tr.querySelector('[data-col="pm"]')?.value);
    var p3=toNum(tr.querySelector('[data-col="pm345"]')?.value);
    var totEl = tr.querySelector('[data-col="egg_total"]');
    if (totEl) totEl.value = (am+pm+p3) || '';
  }

  function recalcAll(){
    var sums={am:0,pm:0,pm345:0,egg_total:0,sacks:0,feed_tot:0,beg_bal:0,M:0,rejects:0,waterbag:0,cull:0,old:0,inv_tot:0,balance:0};
    body.querySelectorAll('tr').forEach(function(tr){
      rowTotal(tr);
      Object.keys(sums).forEach(function(k){
        var el=tr.querySelector('[data-col="'+k+'"]');
        if(el) sums[k]+=toNum(el.value);
      });
    });
    function set(id,v){ var el=document.getElementById(id); if(el) el.textContent=Number(v).toLocaleString(); }
    set('sum_am',sums.am); set('sum_pm',sums.pm); set('sum_pm345',sums.pm345); set('sum_egg',sums.egg_total);
    set('sum_sacks',sums.sacks); set('sum_feed',sums.feed_tot);
    set('sum_begbal',sums.beg_bal); set('sum_M',sums.M); set('sum_rejects',sums.rejects); set('sum_water',sums.waterbag);
    set('sum_cull',sums.cull); set('sum_old',sums.old); set('sum_inv',sums.inv_tot); set('sum_balance',sums.balance);
  }

  /* ---------- Mobile auto-fit (scale down to fit screen) ---------- */
  function isPhone(){ return window.matchMedia('(max-width: 576px)').matches; }
  function fitMobile(){
    if (!fit || !table) return;
    // reset
    fit.style.transform = 'none';
    fit.style.height = 'auto';

    if (!isPhone()) return; // only on small screens

    // compute natural width vs available width
    var full   = table.scrollWidth;   // natural unscaled width
    var avail  = fit.clientWidth;     // viewport width inside card
    if (!full || !avail) return;

    var scale = avail / full;
    if (scale < 1){
      fit.style.transform = 'scale(' + scale + ')';
      fit.style.height    = (table.offsetHeight * scale) + 'px'; // reserve space so content below isn't overlapped
    }
  }
  function debounce(fn, delay){ var t; return function(){ clearTimeout(t); t=setTimeout(fn, delay); }; }
  var fitMobileDeb = debounce(fitMobile, 50);

  // Observe size changes (fonts, container width etc.)
  if ('ResizeObserver' in window){
    var ro = new ResizeObserver(fitMobileDeb);
    if (fit)   ro.observe(fit);
    if (table) ro.observe(table);
  }

  window.addEventListener('resize', fitMobileDeb);
  window.addEventListener('orientationchange', fitMobile);
  window.addEventListener('pageshow', fitMobile);
  document.addEventListener('DOMContentLoaded', fitMobile);

  /* ---------- numeric input hardening ---------- */
  function scrubNumeric(el){
    if (!el || el.readOnly) return;
    var v = el.value;
    var cleaned = v.replace(/[^0-9]/g,'');
    if (cleaned !== v) el.value = cleaned;
  }
  body.addEventListener('input', function(e){
    if(e.target.matches('input.i102-in')){
      scrubNumeric(e.target);
      var tr=e.target.closest('tr'); if(tr){ rowTotal(tr); recalcAll(); }
      fitMobileDeb(); // refit if keyboard/reflow changed metrics
    }
  });
  body.addEventListener('paste', function(e){
    if(e.target.matches('input.i102-in')){
      e.preventDefault();
      var text = (e.clipboardData || window.clipboardData).getData('text') || '';
      e.target.value = text.replace(/[^0-9]/g,'');
      var tr=e.target.closest('tr'); if(tr){ rowTotal(tr); recalcAll(); }
      fitMobileDeb();
    }
  });

  // date default = today
  var d=document.getElementById('i102Date');
  if(d && !d.value){
    var t=new Date(), mm=String(t.getMonth()+1).padStart(2,'0'), dd=String(t.getDate()).padStart(2,'0');
    d.value = t.getFullYear()+'-'+mm+'-'+dd;
  }

  recalcAll();
  fitMobile();

  document.getElementById('i102Form').addEventListener('submit', function(){
    var rows=[];
    body.querySelectorAll('tr').forEach(function(tr){
      var bldg = tr.querySelector('input[name="bldg[]"]')?.value || '';
      var rec={
        bldg:bldg,
        am:toNum(tr.querySelector('[data-col="am"]')?.value),
        pm:toNum(tr.querySelector('[data-col="pm"]')?.value),
        pm345:toNum(tr.querySelector('[data-col="pm345"]')?.value),
        egg_total:toNum(tr.querySelector('[data-col="egg_total"]')?.value),
        sacks:toNum(tr.querySelector('[data-col="sacks"]')?.value),
        feed_tot:toNum(tr.querySelector('[data-col="feed_tot"]')?.value),
        beg_bal:toNum(tr.querySelector('[data-col="beg_bal"]')?.value),
        M:toNum(tr.querySelector('[data-col="M"]')?.value),
        rejects:toNum(tr.querySelector('[data-col="rejects"]')?.value),
        waterbag:toNum(tr.querySelector('[data-col="waterbag"]')?.value),
        cull:toNum(tr.querySelector('[data-col="cull"]')?.value),
        old:toNum(tr.querySelector('[data-col="old"]')?.value),
        inv_tot:toNum(tr.querySelector('[data-col="inv_tot"]')?.value),
        balance:toNum(tr.querySelector('[data-col="balance"]')?.value)
      };
      var hasData = Object.keys(rec).some(function(k){ return k==='bldg' ? rec[k]!=='' : rec[k]>0; });
      if (hasData) rows.push(rec);
    });
    document.getElementById('payload').value = JSON.stringify({rows:rows});
  });
})();
</script>
JS;

include __DIR__ . '/inc/layout_foot.php';
