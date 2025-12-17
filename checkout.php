<?php
/**
 * checkout2.php — SumUp checkout styled like Stripe Checkout
 * - Secure plan codes (no price in URL)
 * - Signature verification (HMAC)
 * - After payment, redirects to thankyou.html with email & details
 * - On non-success: stays on page and shows inline error (no auto-cancel redirect)
 */

const SUMUP_BASE_URL      = 'https://api.sumup.com';
const SUMUP_API_TOKEN     = 'sup_sk_gCP0wPWLXhZBjTf6fmp9VRwKHB60Quq3Z'; // keep secret server-side
const SUMUP_MERCHANT_CODE = 'MLXZTTRT';

// ===== Secure-link config =====
const USE_SIGNATURE = true; // set to false if you want to disable signature
const LINK_SECRET   = 'b8c6e2c1d1a04b1e7e3a2b9f6c0d4a8894a1b3c7d5e2f9a0b7c6d5e4a3b2c1d0';

// ===== Success/Cancel destinations =====
const THANKYOU_URL = 'https://raidlinestudio.com/checkout/thankyou.html';
const CANCEL_URL   = 'https://raidlinestudio.com/checkout/cancel.html'; // not auto-used anymore by JS

// ===== Plan code mapping (server decides the real price) =====
$PLANS = [
  // code       => [amount, currency, planName, description]
  'ZXSZ212'   => [29.99, 'EUR', 'Argent Premium',  'Premium Access Argent'],
  'JKEO3232'  => [39.99, 'EUR', 'Or Premium',      'Premium Access Or'],
  'PLUXI6MO'  => [59.99, 'EUR', '6 Mois Premium',  '6-Month Access'],
  'PLUXI12MO' => [69.99, 'EUR', '12 Mois Premium', '12-Month Access'],
   'PLPREMI12MO' => [9.99, 'EUR', '1 Mois Premium', '1-Month Access'],
   'PLPREMI25MO' => [25, 'EUR', '12 Mois Premium', '12-Mois Access'],
    'PLPREMI19MO' => [19.99, 'EUR', '12 Mois Premium', '12-Mois Access'],
     'PLPRLIFETIMEMO' => [169.99, 'EUR', 'LIFE-TIME', 'LIFE-TIME Access'],
      'PLUXI6MO17' => [17.00, 'EUR', '12 Mois Premium', '12-Month Access'],
       'PLUXEDITIEND' => [30.99, 'EUR', '12 Mois Premium', '12-Month Access'],
];

// ---------- Read query ----------
$company   = $_GET['company'] ?? 'MAISON DU BIJOU FRANCAIS';
$code      = $_GET['c'] ?? '';     // plan code (required)
$t         = isset($_GET['t']) ? (int)$_GET['t'] : 0;
$ttl       = isset($_GET['ttl']) ? (int)$_GET['ttl'] : 0;
$sig       = $_GET['s'] ?? '';

$returnUrl   = $_GET['return']   ?? 'https://raidlinestudio.com/'; // optional; passed to thankyou as "return"
$redirectUrl = $_GET['redirect'] ?? THANKYOU_URL;                  // SumUp fallback success URL (no email)

// Build current page URL (used as SumUp return_url on non-success)
$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$selfUrl = $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').($_SERVER['REQUEST_URI'] ?? '/');

// ---------- Verify + resolve plan ----------
function bad($msg){
  http_response_code(400);
  echo "<h3 style='font-family:system-ui; color:#b91c1c;'>$msg</h3>";
  exit;
}
if (!$code) bad('Lien invalide : code de plan manquant.');
if (!isset($PLANS[$code])) bad('Lien invalide : code de plan inconnu.');

if (USE_SIGNATURE) {
  if (!$t || !$ttl || !$sig) bad('Lien invalide : paramètres de sécurité manquants.');
  if ($t > time() + 60)      bad('Horloge invalide.');
  if (time() > $t + $ttl)    bad('Lien expiré.');
  $payload  = $code.'|'.$t.'|'.$ttl;
  $expected = hash_hmac('sha256', $payload, LINK_SECRET);
  if (!hash_equals($expected, $sig)) bad('Signature invalide.');
}

list($amount, $currency, $planName, $description) = $PLANS[$code];
$currency = strtoupper($currency);

// ---------- Create SumUp Checkout ----------
$checkout = null;
$errorMsg = null;

try {
  $payload = [
    'checkout_reference' => 'order_'.time().'_'.bin2hex(random_bytes(3)),
    'amount'             => $amount,
    'currency'           => $currency,
    'merchant_code'      => SUMUP_MERCHANT_CODE,
    'description'        => $description,

    // Fallbacks if SumUp finishes natively:
    // - return_url (non-success): back to THIS PAGE instead of cancel.html
    // - redirect_url (success): thank you (but will not have email)
    'return_url'         => $selfUrl,
    'redirect_url'       => THANKYOU_URL,
  ];

  $ch = curl_init(SUMUP_BASE_URL.'/v0.1/checkouts');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
      'Authorization: Bearer '.SUMUP_API_TOKEN,
      'Accept: application/json',
      'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES)
  ]);
  $raw  = curl_exec($ch);
  $codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($raw === false) throw new RuntimeException('Curl error: '.curl_error($ch));
  curl_close($ch);

  if ($codeHttp >= 400) {
    $errorMsg = "Create checkout failed ($codeHttp): ".$raw;
  } else {
    $checkout = json_decode($raw, true);
  }
} catch (Throwable $e) {
  $errorMsg = $e->getMessage();
}

$checkoutId = $checkout['id'] ?? null;
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Checkout – <?= htmlspecialchars($planName) ?></title>
<link rel="preconnect" href="https://gateway.sumup.com">
<style>
  :root{
    --bg:#ffffff; --text:#1a1f36; --muted:#6b7280; --line:#e5e7eb;
    --blue:#0a66ff; --blue-dark:#0856d9; --radius:12px;
    --shadow:0 1px 2px rgba(0,0,0,.06), 0 8px 24px rgba(16,24,40,.08);
  }
  *{box-sizing:border-box}
  body{margin:0; background:var(--bg); color:var(--text);
       font:16px/1.55 system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial;}
  .page{max-width:1120px;margin:20px auto;padding:24px;display:grid;grid-template-columns:1fr 1fr;gap:40px;}
  .summary{padding:12px 8px 0 8px;}
  .panel{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:var(--shadow);padding:20px;}

  .merchant{display:flex;align-items:center;gap:12px;margin-bottom:12px;}
  .avatar{width:34px;height:34px;border-radius:999px;background:#eef2ff;display:grid;place-items:center;font-weight:700;color:#3b49df;}
  .heading{color:#374151;font-size:14px;}
  .bigprice{font-size:44px;font-weight:800;margin:8px 0 24px;letter-spacing:.2px;text-align:left;}
  .line{height:1px;background:var(--line);margin:18px 0;}
  .row{display:flex;justify-content:space-between;padding:10px 0;}
  .muted{color:var(--muted);}
  .note{color:var(--muted);font-size:14px;margin-top:14px;}

  /* Mobile header */
  .mobile-header{display:none;padding:16px 16px 0;}
  .mh-top{display:flex;align-items:center;gap:12px;}
  .mh-company{font-weight:700;}
  .mh-plan{text-align:center;margin:18px 0 6px;color:#4b5563;font-weight:700;letter-spacing:.5px;}
  .mh-price{text-align:center;font-size:44px;font-weight:800;margin:0 0 12px;}
  .mh-accordion{display:flex;justify-content:center;}
  .mh-toggle{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:#fff;cursor:pointer;font-weight:600;}
  .mh-details{margin-top:14px;border-top:1px solid var(--line);padding-top:12px;display:none;}

  /* Form */
  h2{font-size:18px;margin:6px 0 10px;}
  label{display:block;font-size:14px;color:#374151;margin:10px 0 6px;}
  input[type="email"]{width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:10px;font-size:15px;outline:none;}

  .widget-wrap{margin-top:18px;}
  .widget-plate{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px;}
  #sumup-card{background:#fff;border-radius:10px;}

  /* Make SumUp inputs look native */
  [data-sumup-id='cardholder__name__input'],
  [data-sumup-id='card__number__input'],
  [data-sumup-id='card__expiry__input'],
  [data-sumup-id='card__cvv__input']{
    background:#fff !important;border:1px solid var(--line) !important;border-radius:10px !important;color:#111827 !important;padding:12px !important;box-shadow:none !important;
  }
  [data-sumup-id='widget__footer']{display:none !important;} /* ok to hide footer; we keep SumUp submit visible */

  .tos{text-align:center;color:#6b7280;font-size:12px;margin-top:10px;}
  .err{color:#d92d20;font-size:14px;margin-top:8px;}

  @media(max-width:740px){
    .page{grid-template-columns:1fr;gap:20px;padding:0 0 20px;}
    .summary{display:none;}
    .mobile-header{display:block;}
    .panel{border-radius:0;border-left:none;border-right:none;box-shadow:none;}
    .panel h2{display:none;} /* optional: hide "Contact information" on mobile */
  }
</style>
</head>
<body>

  <!-- Mobile header -->
  <div class="mobile-header">
    <div class="mh-top">
      <div class="avatar"><?= htmlspecialchars(substr($company,0,1)) ?></div>
      <div class="mh-company"><?= htmlspecialchars($company) ?></div>
    </div>
    <div class="mh-plan"><?= htmlspecialchars($planName) ?></div>
    <div class="mh-price">€<?= number_format($amount, 2) ?></div>
    <div class="mh-accordion">
      <button class="mh-toggle" id="toggleDetails" type="button">
        Voir les détails <span aria-hidden="true">▾</span>
      </button>
    </div>
    <div class="mh-details" id="detailsBox">
      <div class="row"><div class="muted">Sous-total</div><div>€<?= number_format($amount, 2) ?></div></div>
      <div class="row"><div class="muted">Taxes <span title="Calculées à l'adresse">ⓘ</span></div><div class="muted">Saisir l'adresse</div></div>
      <div class="line" style="margin:12px 0;"></div>
      <div class="row"><div><strong>Total dû</strong></div><div><strong>€<?= number_format($amount, 2) ?></strong></div></div>
    </div>
  </div>

  <div class="page">
    <!-- LEFT: Desktop summary -->
    <section class="summary">
      <div class="merchant">
        <div class="avatar"><?= htmlspecialchars(substr($company,0,1)) ?></div>
        <div>
          <div class="heading"><?= htmlspecialchars($company) ?></div>
          <div class="heading">Payer <?= htmlspecialchars($company) ?></div>
        </div>
      </div>
      <div class="bigprice">€<?= number_format($amount, 2) ?></div>
      <div class="row"><div class="muted"><?= htmlspecialchars($planName) ?></div><div>€<?= number_format($amount, 2) ?></div></div>
      <div class="line"></div>
      <div class="row"><div class="muted">Sous-total</div><div>€<?= number_format($amount, 2) ?></div></div>
      <div class="row"><div class="muted">Taxes <span title="Calculées à l'adresse">ⓘ</span></div><div class="muted">Saisir l'adresse</div></div>
      <div class="line"></div>
      <div class="row"><div>Total dû</div><div>€<?= number_format($amount, 2) ?></div></div>
      <p class="note">Vous recevrez votre code par email une fois le paiement confirmé.</p>
    </section>

    <!-- RIGHT: Email + Card widget -->
    <section class="panel">
      <h2>Coordonnées</h2>
      <label for="email">Email</label>
      <input id="email" type="email" placeholder="email@example.com" autocomplete="email" required />

      <?php if ($errorMsg): ?>
        <div class="err">Erreur lors de la création du checkout : <?= htmlspecialchars($errorMsg) ?></div>
      <?php elseif (!$checkoutId): ?>
        <div class="err">Impossible d'obtenir un checkout ID.</div>
      <?php else: ?>
        <div class="widget-wrap">
          <div class="widget-plate">
            <div id="sumup-card"></div>
          </div>
          <!-- Inline error box (hidden until needed) -->
          <div id="payErr" class="err" style="display:none;"></div>

          <div class="tos">En payant, vous acceptez nos Conditions et Politique de confidentialité.</div>
        </div>
      <?php endif; ?>
    </section>
  </div>

<?php if ($checkoutId): ?>
  <script src="https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js"></script>
  <script>
    // Mobile details toggle
    (function(){
      var btn = document.getElementById('toggleDetails');
      var box = document.getElementById('detailsBox');
      if (btn && box){
        btn.addEventListener('click', function(){
          var open = box.style.display === 'block';
          box.style.display = open ? 'none' : 'block';
          var s = btn.querySelector('span');
          if (s) s.textContent = open ? '▾' : '▴';
        });
      }
    })();

    // Mount SumUp widget (show SumUp's own submit button)
    const PLAN_NAME = <?= json_encode($planName) ?>;
    const AMOUNT    = <?= json_encode(number_format($amount, 2)) ?>;
    const CODE      = <?= json_encode($code) ?>;
    const RETURN_TO = <?= json_encode($returnUrl) ?>;
    const CURRENCY  = <?= json_encode($currency) ?>;

    const card = SumUpCard.mount({
      id: 'sumup-card',
      checkoutId: '<?= htmlspecialchars($checkoutId, ENT_QUOTES) ?>',
      locale: 'fr-FR',
      amount: AMOUNT,
      currency: CURRENCY,
      showFooter: false,
      onResponse: function(type, body){
        const email  = (document.getElementById('email')?.value || '').trim();
        const errBox = document.getElementById('payErr');

        const showErr = (msg) => {
          if (errBox) {
            errBox.textContent = msg;
            errBox.style.display = 'block';
          } else {
            alert(msg);
          }
        };

        // reset any previous error
        if (errBox) errBox.style.display = 'none';

        if (type === 'success') {
          // Go to branded thank you WITH email + details
          const u = new URL('<?= THANKYOU_URL ?>');
          if (email) u.searchParams.set('email', email);
          if (PLAN_NAME) u.searchParams.set('plan', PLAN_NAME);
          if (AMOUNT)    u.searchParams.set('amount', AMOUNT);
          if (CODE)      u.searchParams.set('code', CODE);
          if (RETURN_TO) u.searchParams.set('return', RETURN_TO);
          window.location.href = u.toString();
          return;
        }

        // Not success → stay on page and show a friendly message
        let msg = "Le paiement n’a pas abouti. Vérifiez vos informations puis réessayez.";
        try {
          if (body && typeof body === 'object') {
            if (body.message) msg = body.message;
            if (body.error_message) msg = body.error_message;
            if (body.status === 'canceled' || body.error_code === 'payment_canceled') {
              msg = "Paiement annulé. Vous pouvez réessayer à tout moment.";
            }
          }
        } catch(e){}

        showErr(msg + " Besoin d’aide ? Contactez-nous sur WhatsApp : +33 7 56 75 58 97");
        // If you *only* want to redirect to cancel page on explicit cancel, uncomment below:
        // if (body && (body.status === 'canceled' || body.error_code === 'payment_canceled')) {
        //   const c = new URL('<?= CANCEL_URL ?>');
        //   c.searchParams.set('reason', 'cancelled');
        //   if (RETURN_TO) c.searchParams.set('return', RETURN_TO);
        //   window.location.href = c.toString();
        // }
      }
    });
  </script>
<?php endif; ?>
</body>
</html>
