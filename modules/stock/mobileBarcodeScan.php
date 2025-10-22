<?php
// modules/stock/mobileBarcodeScan.php
require_once '../../config.php';
require_once '../../functions.php';
session_start();
if (!isset($_SESSION['user_id'])) {
  header('Location: ../users/login.php');
  exit;
}

/* ---------- Helpers (same robust matching as before) ---------- */
function build_variants(string $raw): array
{
  $raw = trim($raw);
  $variants = [];
  if ($raw !== '') $variants[] = $raw;

  $digitsOnly = preg_replace('/\D+/', '', $raw);
  $noHyphenSpace = preg_replace('/[-\s]+/', '', $raw);
  $upper = strtoupper($raw);
  $upperNoHS = strtoupper($noHyphenSpace);

  foreach ([$digitsOnly, $noHyphenSpace, $upper, $upperNoHS] as $v) {
    if ($v !== '' && !in_array($v, $variants, true)) $variants[] = $v;
  }

  // UPC-A (12) <-> EAN-13 (leading zero)
  if ($digitsOnly !== '') {
    if (strlen($digitsOnly) === 12) {
      $v = '0' . $digitsOnly;
      if (!in_array($v, $variants, true)) $variants[] = $v;
    } elseif (strlen($digitsOnly) === 13 && $digitsOnly[0] === '0') {
      $v = substr($digitsOnly, 1);
      if (!in_array($v, $variants, true)) $variants[] = $v;
    }
  }
  return array_values(array_unique($variants));
}

function normalize_for_compare($v)
{
  $digits = preg_replace('/\D+/', '', (string)$v);
  $upperNoHS = strtoupper(preg_replace('/[-\s]+/', '', (string)$v));
  return [$digits, $upperNoHS];
}

function find_product_safely($db, array $variants, array &$debug, ?string &$matchedField = null): ?array
{
  foreach (['barcode', 'sku'] as $field) {
    foreach ($variants as $v) {
      $rows = $db->readAll('products', [[$field, '==', (string)$v]], null, 1);
      if (is_array($rows) && !empty($rows)) {
        $debug['hit_mode'] = 'query';
        $debug['hit_field'] = $field;
        $debug['hit_variant'] = $v;
        $matchedField = $field;
        return $rows[0];
      }
    }
  }
  $list = $db->readAll('products', [], null, 500) ?? [];
  $debug['scanned_count'] = is_countable($list) ? count($list) : 0;

  foreach ($list as $row) {
    foreach (['barcode', 'sku'] as $field) {
      if (!isset($row[$field])) continue;
      [$rowDigits, $rowUpperNoHS] = normalize_for_compare($row[$field]);
      foreach ($variants as $v) {
        [$vDigits, $vUpperNoHS] = normalize_for_compare($v);
        if (($rowDigits && $rowDigits === $vDigits) || ($rowUpperNoHS && $rowUpperNoHS === $vUpperNoHS)) {
          $debug['hit_mode'] = 'scan';
          $debug['hit_field'] = $field;
          $debug['hit_variant'] = $v;
          $debug['matched_against'] = $row[$field];
          $matchedField = $field;
          return $row;
        }
      }
    }
  }
  return null;
}

/* ---------- AJAX: decode result arrives here ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['barcode'])) {
  header('Content-Type: application/json');
  try {
    $db = getDB();
    if (!is_object($db) || !method_exists($db, 'readAll')) {
      echo json_encode(['ok' => false, 'error' => 'Database not available.']);
      exit;
    }

    $raw = trim((string)($_POST['barcode'] ?? ''));
    if ($raw === '') {
      echo json_encode(['ok' => false, 'error' => 'Empty barcode.']);
      exit;
    }

    $variants = build_variants($raw);
    $debug = ['decoded_raw' => $raw, 'variants' => $variants];
    $matchedField = null;
    $product = find_product_safely($db, $variants, $debug, $matchedField);

    if ($product) {
      $out = [
        // prefer your numeric/string id if present
        'doc_id'        => $product['id'] ?? ($product['doc_id'] ?? null),
        'name'          => $product['name'] ?? '',
        'sku'           => $product['sku'] ?? '',
        'barcode'       => $product['barcode'] ?? '',
        'category'      => $product['category'] ?? 'General',
        'quantity'      => isset($product['quantity']) ? (int)$product['quantity'] : 0,
        'reorder_level' => isset($product['reorder_level']) ? (int)$product['reorder_level'] : 0,
        'price'         => $product['price'] ?? null,
      ];

      // tell the frontend what field is safest to use for deep-linking
      $locatorField = !empty($out['doc_id']) ? 'doc_id' : (!empty($out['sku']) ? 'sku' : 'barcode');
      $locatorValue = $locatorField === 'doc_id' ? $out['doc_id'] : ($locatorField === 'sku' ? $out['sku'] : $out['barcode']);

      echo json_encode([
        'ok'            => true,
        'product'       => $out,
        'locator_field' => $locatorField,
        'locator_value' => $locatorValue,
        'matched_field' => $matchedField,
        'debug'         => $debug
      ]);
    } else {
      echo json_encode(['ok' => false, 'error' => 'No product found.', 'debug' => $debug]);
    }
  } catch (Throwable $e) {
    error_log('Barcode lookup error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Lookup failed.']);
  }
  exit;
}

$page_title = 'Product Barcode Scanning - Inventory System';
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?php echo $page_title; ?></title>
  <link rel="stylesheet" href="../../assets/css/style.css">
  <!-- Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* ===========================
   Mobile Barcode Scan Styles
   Namespace: mbscan-*
   =========================== */

    :root {
      /* Palette (scoped via class names to avoid global conflicts) */
      --mbscan-bg: #f6f8fb;
      --mbscan-card: #ffffff;
      --mbscan-border: #e7e9ee;
      --mbscan-text: #1f2937;
      --mbscan-muted: #6b7280;
      --mbscan-primary: #2563eb;
      --mbscan-primary-2: #1e4fd3;
      --mbscan-ghost: #f3f5f9;
      --mbscan-focus: #93c5fd;
      --mbscan-shadow: 0 8px 24px rgba(15, 23, 42, .06);
      --mbscan-radius: 14px;
      --mbscan-radius-sm: 10px;
      --mbscan-gap: 12px;
    }

    /* Page wrapper aligns with your dashboard content width */
    .mbscan-page {
      max-width: 980px;
      margin: 0 auto 24px;
      padding: 0 12px 24px;
    }

    /* Cards */
    .mbscan-card {
      background: var(--mbscan-card);
      border: 1px solid var(--mbscan-border);
      border-radius: var(--mbscan-radius);
      box-shadow: var(--mbscan-shadow);
      padding: 16px;
      margin-bottom: 16px;
    }

    .mbscan-card__header {
      display: flex;
      align-items: center;
      gap: var(--mbscan-gap);
    }

    .mbscan-card__actions {
      margin-left: auto;
      display: flex;
      gap: var(--mbscan-gap);
    }

    /* Typography */
    .mbscan-title {
      margin: 0;
      font-weight: 700;
      color: var(--mbscan-text);
      letter-spacing: .2px;
    }

    .mbscan-title--h3 {
      font-size: 1.1rem;
    }

    .mbscan-subtle {
      color: var(--mbscan-muted);
      margin: 6px 0 12px;
    }

    /* Controls row */
    .mbscan-controls {
      display: flex;
      flex-wrap: wrap;
      gap: var(--mbscan-gap);
      align-items: center;
    }

    /* Buttons */
    .mbscan-btn {
      appearance: none;
      border: 1px solid var(--mbscan-border);
      background: #f7f8fc;
      color: var(--mbscan-text);
      padding: 10px 14px;
      border-radius: var(--mbscan-radius-sm);
      cursor: pointer;
      transition: transform .02s ease, box-shadow .2s ease, background .2s ease, border-color .2s ease;
      box-shadow: 0 1px 0 rgba(0, 0, 0, .02);
    }

    .mbscan-btn:hover {
      background: #eef2ff;
      border-color: #dce3f7;
    }

    .mbscan-btn:active {
      transform: translateY(1px);
    }

    .mbscan-btn[disabled] {
      opacity: .6;
      cursor: not-allowed
    }

    .mbscan-btn--primary {
      background: var(--mbscan-primary);
      border-color: var(--mbscan-primary);
      color: #fff;
    }

    .mbscan-btn--primary:hover {
      background: var(--mbscan-primary-2);
      border-color: var(--mbscan-primary-2);
    }

    /* --- Enhanced button color set --- */
    .mbscan-btn--ghost {
      background: #e5e7eb;
      /* light grey */
      color: #111827;
      border: 1px solid #d1d5db;
    }

    .mbscan-btn--ghost:hover {
      background: #d1d5db;
    }


    /* File input as a styled control */
    .mbscan-file {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 8px 10px;
      border: 1px dashed var(--mbscan-border);
      border-radius: var(--mbscan-radius-sm);
      background: #fff;
    }

    .mbscan-file__input {
      position: absolute;
      opacity: 0;
      width: 1px;
      height: 1px;
      pointer-events: none;
    }

    .mbscan-file__btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-weight: 600;
      padding: 8px 12px;
      border-radius: 10px;
      background: #f6f7fb;
      border: 1px solid var(--mbscan-border);
    }

    .mbscan-file__btn:hover {
      background: #eef2ff;
    }

    .mbscan-file__hint {
      color: var(--mbscan-muted);
      font-size: .9rem;
    }

    .mbscan-icon {
      font-style: normal;
    }

    /* Manual box */
    .mbscan-manual {
      display: inline-flex;
      gap: 8px;
      align-items: center;
    }

    .mbscan-input {
      min-width: 260px;
      padding: 10px 12px;
      border: 1px solid var(--mbscan-border);
      border-radius: var(--mbscan-radius-sm);
      background: #fff;
      outline: none;
      transition: border-color .2s ease, box-shadow .2s ease;
    }

    .mbscan-input:focus {
      border-color: var(--mbscan-primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, .15);
    }

    /* Preview + status */
    .mbscan-preview {
      display: grid;
      grid-template-columns: 1fr;
      gap: 10px;
      margin-top: 12px;
    }

    .mbscan-preview__img {
      max-width: 100%;
      max-height: 320px;
      display: none;
      /* becomes block via JS when src set */
      border-radius: var(--mbscan-radius-sm);
      border: 1px solid var(--mbscan-border);
      background: #fff;
    }

    .mbscan-note {
      color: var(--mbscan-muted);
      font-size: .95rem;
    }

    /* Product grid (result) */
    .mbscan-product {
      display: grid;
      grid-template-columns: repeat(2, minmax(220px, 1fr));
      gap: 10px 18px;
      border: 1px solid var(--mbscan-border);
      border-radius: var(--mbscan-radius);
      padding: 14px;
      background: #fff;
    }

    .mbscan-field {
      display: contents;
    }

    /* label + value line up in grid */
    .mbscan-field__label {
      color: #4b5563;
    }

    .mbscan-field__value {
      font-weight: 700;
      color: #111827;
    }

    .mbscan-field__value--mono {
      font-family: ui-monospace, Menlo, Consolas, monospace;
    }

    /* Actions under result */
    .mbscan-actions {
      display: flex;
      gap: var(--mbscan-gap);
      margin-top: 12px;
      justify-content: flex-end;
    }

    /* Debug */
    .mbscan-debug {
      margin-top: 8px;
    }

    .mbscan-code {
      background: #f6f7fb;
      border: 1px solid var(--mbscan-border);
      border-radius: var(--mbscan-radius-sm);
      padding: 12px;
      overflow: auto;
    }

    /* Small screens */
    @media (max-width: 640px) {
      .mbscan-controls {
        flex-direction: column;
        align-items: stretch;
      }

      .mbscan-manual {
        width: 100%;
      }

      .mbscan-input {
        width: 100%;
        min-width: 0;
      }

      .mbscan-product {
        grid-template-columns: 1fr;
      }

      .mbscan-card__header {
        flex-wrap: wrap;
      }

      .mbscan-card__actions {
        width: 100%;
        justify-content: flex-start;
      }
    }

    /* Light purple for Lookup button */
    #manualBtn {
      background: #c7d2fe;
      border-color: #c7d2fe;
      color: #312e81;
      transition: background 0.2s ease, border-color 0.2s ease;
    }

    #manualBtn:hover {
      background: #a5b4fc;
      border-color: #a5b4fc;
    }

    /* Slightly dark grey for Back to Stock List button */
    a[href="list.php"].mbscan-btn,
    .mbscan-card__actions .mbscan-btn--ghost {
      background: #d1d5db;
      color: #111827;
      border-color: #9ca3af;
    }

    a[href="list.php"].mbscan-btn:hover,
    .mbscan-card__actions .mbscan-btn--ghost:hover {
      background: #9ca3af;
    }

    /* Suggestion for Clear button — soft red for visibility but not warning red */
    #clearBtn {
      background: #fee2e2;
      border-color: #fecaca;
      color: #991b1b;
      transition: background 0.2s ease, border-color 0.2s ease;
    }

    #clearBtn:hover {
      background: #fecaca;
      border-color: #fca5a5;
    }

    .mbscan-btn,
    .mbscan-btn:visited,
    .mbscan-btn:hover {
      text-decoration: none;
    }
  </style>
  <script src="https://unpkg.com/@zxing/library@0.20.0"></script>
</head>

<body>
  <?php
  include '../../includes/dashboard_header.php';
  ?>
  <!-- Page wrapper -->
  <div class="mbscan-page">

    <!-- Scan card -->
    <section class="mbscan-card mbscan-card--scan">
      <header class="mbscan-card__header">
        <h2 class="mbscan-title">Product Barcode Scanning</h2>
        <div class="mbscan-card__actions">
          <a href="list.php" class="mbscan-btn mbscan-btn--ghost">Back to Stock List</a>
        </div>
      </header>

      <p class="mbscan-subtle">Upload an image with a barcode (Code-128). Each new scan replaces the previous result.</p>

      <div class="mbscan-controls">
        <label class="mbscan-file">
          <input id="file" type="file" accept="image/*" class="mbscan-file__input" />
          <span class="mbscan-file__btn">
            <i class="mbscan-icon">📷</i> Choose Image
          </span>
          <span class="mbscan-file__hint">PNG / JPG / GIF</span>
        </label>

        <button id="decodeBtn" class="mbscan-btn mbscan-btn--primary" disabled>
          Decode&nbsp;&amp;&nbsp;Show
        </button>

        <div class="mbscan-manual">
          <input id="manual" type="text" class="mbscan-input" placeholder="Or type barcode / SKU…" />
          <button id="manualBtn" class="mbscan-btn">Lookup</button>
        </div>

        <button id="clearBtn" class="mbscan-btn mbscan-btn--ghost">Clear</button>
      </div>

      <div class="mbscan-preview">
        <img id="preview" alt="Preview" class="mbscan-preview__img">
        <div id="result" class="mbscan-note"></div>
      </div>
    </section>

    <!-- Matched product card (single latest) -->
    <section class="mbscan-card mbscan-card--result" id="matchedCard" style="display:none">
      <header class="mbscan-card__header">
        <h3 class="mbscan-title mbscan-title--h3">Matched Product</h3>
      </header>

      <div class="mbscan-product" id="productCard">
        <!-- JS injects fields as:
           <div class="mbscan-field">
             <div class="mbscan-field__label">Barcode</div>
             <div class="mbscan-field__value">…</div>
           </div>
      -->
      </div>

      <div class="mbscan-actions">
        <a id="openBtn" class="mbscan-btn mbscan-btn--primary" href="list.php">
          View / Edit in Stock List
        </a>
      </div>

      <details class="mbscan-debug">
        <summary>Debug</summary>
        <pre id="debugJson" class="mbscan-code"></pre>
      </details>
    </section>

  </div>


  <script>
    const fileInput = document.getElementById('file');
    const decodeBtn = document.getElementById('decodeBtn');
    const preview = document.getElementById('preview');
    const resultEl = document.getElementById('result');
    const manual = document.getElementById('manual');
    const manualBtn = document.getElementById('manualBtn');
    const clearBtn = document.getElementById('clearBtn');

    const matchedCard = document.getElementById('matchedCard');
    const productCard = document.getElementById('productCard');
    const openBtn = document.getElementById('openBtn');
    const debugJson = document.getElementById('debugJson');

    const CURRENCY_PREFIX = 'RM';

    let objectUrl = null;

    function money(v) {
      if (v === null || v === undefined || v === '') return '-';
      const n = Number(v);
      if (Number.isNaN(n)) return String(v);
      return `${CURRENCY_PREFIX} ${n.toFixed(2)}`;
    }

    function clearView() {
      productCard.innerHTML = '';
      matchedCard.style.display = 'none';
      debugJson.textContent = '';
    }

    function renderProduct(p) {
      const rows = [
        ['Barcode', p.barcode || '-', true],
        ['Product Name', p.name || '-'],
        ['SKU', p.sku || '-'],
        ['Current Quantity', ('quantity' in p) ? p.quantity : '-'],
        ['Unit Price', (p.price ?? p.unit_price ?? '-')],
      ];
      const html = rows.map(([label, val, mono]) => `
    <div class="mbscan-field">
      <div class="mbscan-field__label">${label}</div>
      <div class="mbscan-field__value ${mono ? 'mbscan-field__value--mono' : ''}">${val}</div>
    </div>
  `).join('');
      document.getElementById('productCard').innerHTML = html;
      document.getElementById('matchedCard').style.display = 'block';
    }

    function buildListLink(locatorField, locatorValue, product) {
      const params = new URLSearchParams();
      params.set('search', product?.name || ''); // just pass the name
      return `list.php?${params.toString()}`;
    }

    async function lookupServer(value) {
      const form = new FormData();
      form.append('barcode', value);
      const r = await fetch('', {
        method: 'POST',
        body: form
      });
      return await r.json();
    }

    function handleResponse(data) {
      if (data && data.ok && data.product) {
        renderProduct(data.product);
        // always replace (fresh result each time)
        const field = data.locator_field || (data.product.doc_id ? 'doc_id' : (data.product.sku ? 'sku' : 'barcode'));
        const value = data.locator_value || (data.product.doc_id || data.product.sku || data.product.barcode || '');
        openBtn.href = buildListLink(field, value, data.product);
        debugJson.textContent = JSON.stringify(data, null, 2);
      } else {
        clearView();
        resultEl.textContent = (data && data.error) ? data.error : 'No product found.';
      }
    }

    fileInput.addEventListener('change', () => {
      resultEl.textContent = '';
      clearView();
      const f = fileInput.files?.[0];
      if (!f) {
        preview.style.display = 'none';
        decodeBtn.disabled = true;
        return;
      }
      if (objectUrl) URL.revokeObjectURL(objectUrl);
      objectUrl = URL.createObjectURL(f);
      preview.src = objectUrl;
      preview.style.display = 'block';
      decodeBtn.disabled = false;
    });

    decodeBtn.addEventListener('click', async () => {
      resultEl.textContent = 'Decoding…';
      const codeReader = new ZXing.BrowserMultiFormatReader();
      try {
        const res = await codeReader.decodeFromImageUrl(preview.src);
        const text = res.text;
        const fmt = res.getBarcodeFormat ? res.getBarcodeFormat() : (res.format || '');
        resultEl.textContent = `Decoded: "${text}"`;
        const data = await lookupServer(text);
        handleResponse(data);
      } catch (e) {
        console.error(e);
        clearView();
        resultEl.textContent = 'Could not decode. Try a clearer, closer, well-lit photo.';
      } finally {
        codeReader.reset();
      }
    });

    manualBtn.addEventListener('click', async () => {
      const val = (manual.value || '').trim();
      if (!val) return;
      resultEl.textContent = `Manual: "${val}"`;
      clearView();
      const data = await lookupServer(val);
      handleResponse(data);
    });

    clearBtn.addEventListener('click', () => {
      fileInput.value = '';
      preview.style.display = 'none';
      resultEl.textContent = '';
      clearView();
    });
  </script>
</body>

</html>