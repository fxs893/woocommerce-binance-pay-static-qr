<?php
if (!defined('ABSPATH')) { exit; }

class WC_Gateway_Binance_Static extends WC_Payment_Gateway {
    /** @var Binance_Client|null */
    public $binance_client;

    public $api_key;
    public $api_secret;
    public $base_url;

    public function __construct() {
        $this->id                 = 'binance_static';
        $this->method_title       = __('Binance Pay (Static QR)', 'wc-binance-pay');
        $this->method_description = __('Checks Binance Pay only (“Send & Receive → Receive”). Supports USDT / USDC with strict memo and amount validation.', 'wc-binance-pay');
        $this->supports           = array('products');
        $this->has_fields         = true; // Show asset choice on checkout

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->api_key     = $this->get_option('api_key');
        $this->api_secret  = $this->get_option('api_secret');
        $this->base_url    = $this->get_option('base_url', 'https://api.binance.com');

        add_action('admin_enqueue_scripts', array($this, 'enqueue_media'));

        if (!empty($this->api_key) && !empty($this->api_secret) && class_exists('Binance_Client')) {
            $this->binance_client = new Binance_Client($this->api_key, $this->api_secret, $this->base_url);
        }

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

        // Persist asset choice from checkout
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_checkout_fields'));
    }

    public function enqueue_media() {
        if (is_admin()) { wp_enqueue_media(); }
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'wc-binance-pay'),
                'type'    => 'checkbox',
                'label'   => __('Enable Binance Pay', 'wc-binance-pay'),
                'default' => 'yes',
            ),
            'title' => array(
                'title'       => __('Title', 'wc-binance-pay'),
                'type'        => 'text',
                'default'     => __('Binance Pay (USDT / USDC)', 'wc-binance-pay'),
            ),
            'description' => array(
                'title'       => __('Description', 'wc-binance-pay'),
                'type'        => 'textarea',
                'default'     => __('Use Binance App “Send & Receive → Receive” to scan and pay. Paste the memo exactly. Supports USDT / USDC.', 'wc-binance-pay'),
            ),

            // Single QR (universal)
            'qr_code' => array(
                'title'       => __('Receiving QR (USDT/USDC universal)', 'wc-binance-pay'),
                'type'        => 'image',
                'description' => __('Upload your Binance “Receive” QR (Send & Receive → Receive). One QR can accept USDT/USDC.', 'wc-binance-pay'),
                'desc_tip'    => true,
            ),

            // API
            'api_key' => array(
                'title'       => 'API Key',
                'type'        => 'text',
                'description' => __('Must access SAPI; account must have Binance Pay enabled.', 'wc-binance-pay'),
            ),
            'api_secret' => array(
                'title'       => 'API Secret',
                'type'        => 'password',
                'description' => __('Shown only once.', 'wc-binance-pay'),
            ),
            'base_url' => array(
                'title'       => __('API Base Domain (optional)', 'wc-binance-pay'),
                'type'        => 'text',
                'default'     => 'https://api.binance.com', // Some regions: https://api.binance.me
            ),

            // Open debug window (new tab)
            'api_test' => array(
                'title'       => __('Open Debug Window', 'wc-binance-pay'),
                'type'        => 'api_test_link',
                'description' => __('Opens JSON in a new tab showing the latest Binance Pay record.', 'wc-binance-pay'),
            ),
        );
    }

    /** Checkout: asset choice (USDT / USDC) */
    public function payment_fields() {
        // Asset logos
        $logo_usdt = 'https://dash.payerurl.com/assets/usdt_bep20-987ba845.webp';
        $logo_usdc = 'https://dash.payerurl.com/assets/usdc_bep20-07937ccc.png';
        $desc = $this->get_description();

        if ($desc) {
            echo wpautop(wptexturize($desc));
        }

        echo '<style>
          .wc-bp-asset { margin-top:8px; background:#fafafa; border:1px solid #eee; border-radius:10px; padding:14px; }
          .wc-bp-asset .asset-options { display:flex; gap:18px; align-items:center; flex-wrap:wrap; }
          .wc-bp-asset .asset-option { display:flex; align-items:center; gap:8px; padding:8px 10px; border-radius:10px; cursor:pointer; border:1px solid transparent; }
          .wc-bp-asset .asset-option img { height:20px; width:20px; display:inline-block; }
          .wc-bp-asset .asset-option input[type="radio"] { margin:0 4px 0 0; transform:translateY(1px); }
          .wc-bp-asset .asset-option.active { background:#fff; border-color:#f0b90b; box-shadow:0 0 0 2px rgba(240,185,11,.15) inset; }
          .wc-bp-asset .asset-hint { color:#777; font-size:12px; margin-top:6px; }
        </style>';

        echo '<fieldset class="wc-bp-asset" id="wc-bp-asset">
                <div class="asset-options" role="radiogroup" aria-label="Choose asset">
                    <label class="asset-option active">
                        <input type="radio" name="wc_bp_asset" value="USDT" checked>
                        <img src="' . esc_url($logo_usdt) . '" alt="USDT">
                        <span>USDT</span>
                    </label>

                    <label class="asset-option">
                        <input type="radio" name="wc_bp_asset" value="USDC">
                        <img src="' . esc_url($logo_usdc) . '" alt="USDC">
                        <span>USDC</span>
                    </label>
                </div>
                <p class="asset-hint">Select the asset you will send. The Thank You page shows the same QR and memo, but verification strictly uses the selected asset.</p>
              </fieldset>';

        echo '<script>
          (function(){
            var wrap = document.getElementById("wc-bp-asset");
            if (!wrap) return;
            var opts = wrap.querySelectorAll(".asset-option");
            opts.forEach(function(lbl){
              lbl.addEventListener("click", function(){
                opts.forEach(function(o){ o.classList.remove("active"); });
                lbl.classList.add("active");
              });
            });
          })();
        </script>';
    }

    /** Save buyer’s asset selection (redundant copy) */
    public function save_checkout_fields($order_id) {
        if (isset($_POST['wc_bp_asset'])) {
            $asset = strtoupper(sanitize_text_field(wp_unslash($_POST['wc_bp_asset'])));
            if (in_array($asset, array('USDT','USDC'), true)) {
                update_post_meta($order_id, '_asset_symbol', $asset);
            }
        }
    }

    /** Place order: generate memo & store info on the order */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'wc-binance-pay'), 'error');
            return;
        }

        // Read buyer’s chosen asset (default USDT)
        $asset = 'USDT';
        if (isset($_POST['wc_bp_asset'])) {
            $cand = strtoupper(sanitize_text_field(wp_unslash($_POST['wc_bp_asset'])));
            if (in_array($cand, array('USDT','USDC'), true)) $asset = $cand;
        }

        // Single QR
        $qr_id = $this->get_option('qr_code');
        if (empty($qr_id)) {
            wc_add_notice(__('Please upload your receiving QR in settings.', 'wc-binance-pay'), 'error');
            return;
        }

        $memo         = $this->generate_memo($order);
        $amount_asset = (float) $order->get_total();

        $order->update_meta_data('_asset_symbol', $asset);
        $order->update_meta_data('_asset_amount', $amount_asset);
        $order->update_meta_data('_payment_memo', $memo);
        $order->update_meta_data('_qr_code_id', absint($qr_id));
        $order->save();

        if (!$order->has_status(array('on-hold', 'processing', 'completed'))) {
            $order->update_status('on-hold', sprintf(__('Waiting for customer to pay via Binance (%s)', 'wc-binance-pay'), $asset));
            wc_reduce_stock_levels($order_id);
        }

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        );
    }

public function thankyou_page($order_id) {
    // ---- 防重复渲染：若已渲染，直接退出 ----
    if (defined('WC_BP_TY_RENDERED')) { return; }
    define('WC_BP_TY_RENDERED', true);

    $order = wc_get_order($order_id);
    if (!$order || $order->get_payment_method() !== $this->id) return;

    $asset       = strtoupper($order->get_meta('_asset_symbol') ?: 'USDT');
    $amount      = $order->get_meta('_asset_amount');
    $memo        = $order->get_meta('_payment_memo');
    $qr_id       = $order->get_meta('_qr_code_id');
    $qr_url      = $qr_id ? wp_get_attachment_url($qr_id) : '';
    $status_slug = $order->get_status();
    $status_name = function_exists('wc_get_order_status_name') ? wc_get_order_status_name($status_slug) : ucfirst($status_slug);

    $is_on_hold  = $order->has_status('on-hold');
    $is_paid     = $order->has_status(array('processing', 'completed'));
    $view_url    = $order->get_view_order_url();

    if (!$qr_url) {
        echo '<p style="color:red;">Error: Receiving QR not found.</p>';
        return;
    }

    // 资产小图标（可保留/可去掉）
    $logo_usdt    = 'https://dash.payerurl.com/assets/usdt_bep20-987ba845.webp';
    $logo_usdc    = 'https://dash.payerurl.com/assets/usdc_bep20-07937ccc.png';
    $asset_logo   = ($asset === 'USDC') ? $logo_usdc : $logo_usdt;

    // AJAX
    $ajax_url     = admin_url('admin-ajax.php');
    $ajax_url     = is_ssl() ? set_url_scheme($ajax_url, 'https') : set_url_scheme($ajax_url, wp_parse_url(home_url(), PHP_URL_SCHEME) ?: 'http');
    $order_key    = $order->get_order_key();
    $nonce_field  = defined('WC_BINANCE_NONCE_NAME') ? WC_BINANCE_NONCE_NAME : 'wc_binance_pay_nonce';
    $nonce_action = defined('WC_BINANCE_NONCE_ACTION') ? WC_BINANCE_NONCE_ACTION : 'wc_binance_pay_nonce_action';
    $nonce_value  = wp_create_nonce($nonce_action);
    $ajax_action  = defined('WC_BINANCE_AJAX_ACTION') ? WC_BINANCE_AJAX_ACTION : 'wc_binance_pay_check_v5';

    $btn_label     = $is_on_hold ? __('I have paid, check now', 'wc-binance-pay')
                     : sprintf(__('Checking disabled — status: %s', 'wc-binance-pay'), $status_name);
    $disabled_attr = $is_on_hold ? '' : ' disabled="disabled" aria-disabled="true"';
    $title_attr    = $is_on_hold ? '' : ' title="' . esc_attr__('Order is not On-Hold, cannot check.', 'wc-binance-pay') . '"';

    // 样式：去掉大 LOGO，只保留一个 QR 模块 + 右侧信息
    echo '<style>
    .wc-bp-shell{max-width:1080px;margin:28px auto;padding:0 14px;}
    .wc-bp-grid{display:grid;grid-template-columns:1fr 1.2fr;gap:24px;align-items:stretch;}
    @media(max-width:860px){.wc-bp-grid{grid-template-columns:1fr;}}
    .wc-bp-card{background:#fff;border:1px solid #eee4bd;border-radius:16px;box-shadow:0 6px 22px rgba(17,17,17,.06);overflow:hidden;}
    .wc-bp-hd{padding:12px 16px;background:linear-gradient(180deg,#fffdf4,#fff9e1);border-bottom:1px dashed #f2e3a5;display:flex;align-items:center;gap:10px;}
    .wc-bp-title{font-weight:800;color:#111;font-size:15px;display:flex;align-items:center;gap:8px;}
    .wc-bp-bd{padding:18px;}
    .wc-bp-qr-wrap{display:flex;flex-direction:column;align-items:center;text-align:center;gap:12px;}
    .wc-bp-qr-img{width:100%;max-width:320px;height:auto;display:block;border-radius:14px;border:3px solid #f0b90b;box-shadow:0 12px 22px rgba(240,185,11,.18);background:#fff;}
    .wc-bp-qr-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;}
    .wc-bp-small{font-size:12px;color:#777;}
    .wc-bp-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px;}
    .wc-bp-amount{font-size:26px;color:#b98500;font-weight:800;}
    .wc-bp-label{min-width:110px;color:#333;font-weight:700;}
    .wc-bp-memo{font-size:18px;background:#fafafa;border:1px solid #eee;padding:12px 14px;border-radius:10px;display:inline-block;user-select:all;letter-spacing:1px;}
    .wc-bp-badge{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;font-weight:700;font-size:13px;}
    .wc-bp-badge--paid{background:#ecfff1;color:#0a7a2a;border:1px solid #b8efc8;}
    .wc-bp-badge--hold{background:#fff8e6;color:#8a5b00;border:1px solid #f5d99a;}
    .wc-bp-badge--other{background:#f4f6f8;color:#4c5967;border:1px solid #dae1e8;}
    .wc-bp-msg{font-weight:700;}
    .wc-bp-msg.ok{color:#0a7a2a;} .wc-bp-msg.warn{color:#8a5b00;} .wc-bp-msg.err{color:#b00020;}
    .wc-bp-btn{appearance:none;border:none;border-radius:10px;padding:14px 16px;font-size:14px;font-weight:700;cursor:pointer;transition:.18s;width:100%;}
    .wc-bp-btn--copy{background:#f0b90b;color:#111;width:auto;}
    .wc-bp-btn--download{background:#fff;color:#111;border:1px solid #ddd;width:auto;}
    .wc-bp-btn--check{background:#111;color:#fff;}
    .wc-bp-btn--check[disabled]{opacity:.6;cursor:not-allowed;}
    .wc-bp-btn--view{background:#fff;color:#111;border:1px solid #ddd;}
    .wc-bp-btn--view:hover{background:#fafafa;}
    .wc-bp-progress{height:8px;background:#f6f6f6;border-radius:999px;overflow:hidden;display:none;margin-top:10px;}
    .wc-bp-progress>span{display:block;height:100%;width:0%;background:linear-gradient(90deg,#f0b90b,#ffd666);transition:width .25s;}
    /* 固定支付信息标题中的资产图标大小，避免被主题全局样式放大 */
    .wc-bp-asset-logo{
      height: 28px !important;
      max-height: 28px !important;
      width: auto !important;
      display: inline-block !important;
      vertical-align: middle !important;
      object-fit: contain;
    }
    /* 保险：限制标题区任何 img 的最大高 */
    .wc-bp-hd .wc-bp-title img{
      max-height: 28px !important;
      height: 28px !important;
      width: auto !important;
    }
/* ---------- Memo 强提醒（优化版） ---------- */
:root{
  --bp-warn-bg: #fff7d6;
  --bp-warn-border: #f2cd5c;
  --bp-warn-text: #6f4a00;   /* 比之前更深，提升可读性 */
  --bp-warn-ico: #b98500;
  --bp-focus: rgba(240,185,11,.4);
}

/* 暗色模式优化 */
@media (prefers-color-scheme: dark){
  :root{
    --bp-warn-bg: #2a2413;
    --bp-warn-border: #6a5415;
    --bp-warn-text: #f3e4b3;
    --bp-warn-ico: #ffd666;
    --bp-focus: rgba(255,214,102,.35);
  }
}

.wc-bp-alert{
  display:flex;
  gap:12px;
  align-items:flex-start;

  background: var(--bp-warn-bg);
  border: 1px solid var(--bp-warn-border);
  color: var(--bp-warn-text);

  border-radius: 12px;
  padding: 14px 16px;

  font-size: 14px;
  line-height: 1.55;

  /* 轻微进场动画 */
  opacity: 0;
  transform: translateY(4px);
  animation: bpFadeIn .28s ease-out forwards;
}

/* 减少动态偏好：禁用动画 */
@media (prefers-reduced-motion: reduce){
  .wc-bp-alert{ animation: none; opacity:1; transform:none; }
}

@keyframes bpFadeIn{
  to { opacity: 1; transform: none; }
}

.wc-bp-alert strong{
  font-weight: 800;
  color: var(--bp-warn-ico);
}

/* 图标更清晰且不挤占文本 */
.wc-bp-alert-ico{
  flex: 0 0 auto;
  width: 20px;
  height: 20px;
  display:inline-grid;
  place-items:center;
  font-size: 16px;
  line-height: 1;
  margin-top: 2px;

  background: var(--bp-warn-ico);
  color: #111;
  border-radius: 50%;
  box-shadow: 0 1px 0 rgba(0,0,0,.05);
}

/* 内联链接（如果你加了帮助链接） */
.wc-bp-alert a{
  color: inherit;
  text-decoration: underline dotted;
  text-underline-offset: 2px;
}
.wc-bp-alert a:hover{ text-decoration-style: solid; }

/* 移动端留白更友好 */
@media (max-width: 520px){
  .wc-bp-alert{ padding: 12px 14px; border-radius: 10px; }
}

/* 可聚焦（无障碍）：若以 role="alert" 外再包一层可聚焦容器，可复用此样式 */
.wc-bp-alert:focus{
  outline: 2px solid var(--bp-focus);
  outline-offset: 2px;
  box-shadow: 0 0 0 2px var(--bp-focus) inset;
}


    </style>';

    $badge_class = $is_paid ? 'wc-bp-badge--paid' : ($is_on_hold ? 'wc-bp-badge--hold' : 'wc-bp-badge--other');
    $status_tip  = $is_paid ? __('Your payment has been received.', 'wc-binance-pay')
                : ($is_on_hold ? __('Awaiting your Binance Pay transfer with the memo below.', 'wc-binance-pay')
                               : __('Checking is disabled for this status.', 'wc-binance-pay'));

    echo '<div class="wc-bp-shell">
            <div class="wc-bp-grid">

                <!-- 左：唯一的 QR 模块 -->
                <div class="wc-bp-card">
                    <div class="wc-bp-hd">
                        <span class="wc-bp-title">Receiving QR</span>
                    </div>
                    <div class="wc-bp-bd">
                        <div class="wc-bp-qr-wrap">
                            <img src="' . esc_url($qr_url) . '" class="wc-bp-qr-img" alt="Binance QR">
                            <div class="wc-bp-qr-actions">
                                <a class="wc-bp-btn wc-bp-btn--download" href="' . esc_url($qr_url) . '" download="binance-qr.png">Save QR</a>
                                <button class="wc-bp-btn wc-bp-btn--download" onclick="wcBpSaveQRCanvas()">If download fails, Save As</button>
                            </div>
                            <div class="wc-bp-small">On mobile, long-press to save. If “Download” doesn’t work, use “Save As”.</div>
                        </div>
                    </div>
                </div>


                <!-- 右：支付信息 -->
                <div class="wc-bp-card">
                    <div class="wc-bp-hd">
                        <span class="wc-bp-title">Payment Info <img src="' . esc_url($asset_logo) . '" alt="' . esc_attr($asset) . '" height="18" style="vertical-align:middle;"></span>
                    </div>
                    <div class="wc-bp-bd">
                        <div class="wc-bp-row">
                            <span class="wc-bp-badge ' . esc_attr($badge_class) . '">
                                <span>Order status:</span><strong id="wc-bp-status-name">' . esc_html($status_name) . '</strong>
                            </span>
                            <span class="wc-bp-msg ' . ($is_paid ? 'ok' : ($is_on_hold ? 'warn' : 'err')) . '" id="wc-bp-status-msg">'
                                . esc_html($status_tip) . '</span>
                        </div>

                        <div class="wc-bp-row">
                            <div class="wc-bp-label">Amount</div>
                            <div class="wc-bp-amount">' . esc_html(number_format((float)$amount, 6)) . ' ' . esc_html($asset) . '</div>
                        </div>

                        <div class="wc-bp-row">
                            <div class="wc-bp-label">Payment Memo</div>
                            <div>
                                <code class="wc-bp-memo">' . esc_html($memo) . '</code>
                                <button type="button" class="wc-bp-btn wc-bp-btn--copy" onclick="wcBpCopyMemo()">Copy Memo</button>
                            </div>
                        </div>
                        
                        
                        <div class="wc-bp-alert" role="alert" aria-live="polite">
                          <span class="wc-bp-alert-ico" aria-hidden="true">!</span>
                          <div class="wc-bp-alert-text">
                            <strong>Important:</strong>
                            You <span class="wc-bp-underline">must paste the memo above</span> into the “Note/Remark” field in Binance Pay.
                            Payments <span class="wc-bp-underline">without the memo</span> cannot be matched automatically, and the order will remain <em>On-Hold</em>.
                          </div>
                        </div>



                        <form id="binance-check-form" style="width:100%;margin-top:8px;">
                            <input type="hidden" name="' . esc_attr($nonce_field) . '" value="' . esc_attr($nonce_value) . '">
                            <input type="hidden" name="action" value="' . esc_attr($ajax_action) . '">
                            <input type="hidden" name="order_id" value="' . esc_attr($order_id) . '">
                            <input type="hidden" name="order_key" value="' . esc_attr($order_key) . '">

                            <button id="binance-check-btn" type="button" class="wc-bp-btn wc-bp-btn--check" ' . $disabled_attr . $title_attr . '>'
                                . esc_html($btn_label) . '</button>

                            <div class="wc-bp-progress" aria-hidden="true"><span></span></div>
                            <div id="check-status" class="wc-bp-small" style="font-weight:700;"></div>

                            <a href="' . esc_url($view_url) . '" class="wc-bp-btn wc-bp-btn--view" id="wc-bp-view-btn"
                               style="display:' . ($is_paid ? 'inline-block' : 'none') . ';margin-top:10px;text-align:center;width:100%;"
                               rel="nofollow">View order details</a>
                        </form>
                    </div>
                </div>
                


            </div>
        </div>';

    // JS：复制/另存/检查 + 30s 冷却
    echo '<script>
        function wcBpCopyMemo() {
            const text = ' . wp_json_encode((string)$memo) . ';
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function(){ alert("Memo copied!"); })
                .catch(function(){ wcBpCopyFallback(text); });
            } else { wcBpCopyFallback(text); }
        }
        function wcBpCopyFallback(text) {
            const el = document.createElement("textarea");
            el.value = text; document.body.appendChild(el); el.select();
            try { document.execCommand("copy"); alert("Copied!"); } catch(e) {}
            document.body.removeChild(el);
        }
        function wcBpSaveQRCanvas(){
            var img = document.querySelector(".wc-bp-qr-img");
            if (!img) return;
            try{
                var c = document.createElement("canvas");
                c.width = img.naturalWidth || 600; c.height = img.naturalHeight || 600;
                var ctx = c.getContext("2d");
                ctx.fillStyle = "#ffffff"; ctx.fillRect(0,0,c.width,c.height);
                ctx.drawImage(img, 0, 0, c.width, c.height);
                var a = document.createElement("a");
                a.href = c.toDataURL("image/png"); a.download = "binance-qr.png";
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
            }catch(e){ alert("Save failed. Long-press or screenshot to save."); }
        }

        (function(){
            let IS_ON_HOLD = ' . ($is_on_hold ? 'true' : 'false') . ';
            let IS_PAID    = ' . ($is_paid ? 'true' : 'false') . ';
            let STATUS_SLUG = ' . wp_json_encode($status_slug) . ';

            const REQ_URL = ' . wp_json_encode($ajax_url) . ';
            const form    = document.getElementById("binance-check-form");
            const btn     = document.getElementById("binance-check-btn");
            const infoMsg = document.getElementById("wc-bp-status-msg");
            const statusEl= document.getElementById("check-status");
            const barBox  = form ? form.querySelector(".wc-bp-progress") : null;
            const bar     = barBox ? barBox.querySelector("span") : null;
            const statusNameEl = document.getElementById("wc-bp-status-name");
            const viewBtn = document.getElementById("wc-bp-view-btn");

            const COOLDOWN_SECONDS = 30;
            let cooldownTimer = null;

            function humanStatus(s){
                if(!s) return "Completed";
                s=(""+s).toLowerCase();
                switch(s){
                    case "completed": return "Completed";
                    case "processing": return "Processing";
                    case "on-hold": return "On-Hold";
                    case "pending": return "Pending";
                    case "cancelled": return "Cancelled";
                    case "refunded": return "Refunded";
                    case "failed": return "Failed";
                    default: return s.charAt(0).toUpperCase()+s.slice(1);
                }
            }
            function showInfo(cls, msg){
                if (!infoMsg) return;
                infoMsg.className = "wc-bp-msg " + cls;
                infoMsg.textContent = msg || "";
            }
            function showStatus(msg){ if (statusEl) statusEl.textContent = msg || ""; }

            function lockButton(reason){
                if(!btn) return;
                IS_ON_HOLD = false;
                btn.disabled = true;
                btn.setAttribute("aria-disabled","true");
                btn.title = "Order is not On-Hold, cannot check.";
                btn.textContent = "Checking disabled — status: " + humanStatus(reason);
            }
            function startCooldown(){
                if (!btn) return;
                let remain = COOLDOWN_SECONDS;
                const orig = btn.dataset.origLabel || btn.textContent;
                btn.dataset.origLabel = orig;
                btn.disabled = true;
                btn.innerHTML = \'<span class="wc-bp-spin" style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;vertical-align:-2px;margin-right:6px;"></span> Cooling down… (\' + remain + \'s)\';
                if (barBox && bar){ barBox.style.display = "block"; bar.style.width = "0%"; }
                const total = COOLDOWN_SECONDS;
                cooldownTimer = setInterval(function(){
                    remain--;
                    const pct = Math.min(100, Math.round(((total - remain) / total) * 100));
                    if (bar) bar.style.width = pct + "%";
                    if (remain <= 0){
                        clearInterval(cooldownTimer);
                        cooldownTimer = null;
                        btn.disabled = !IS_ON_HOLD;
                        btn.textContent = orig || "I have paid, check now";
                        if (barBox) barBox.style.display = "none";
                    } else {
                        btn.innerHTML = \'<span class="wc-bp-spin" style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;vertical-align:-2px;margin-right:6px;"></span> Cooling down… (\' + remain + \'s)\';
                    }
                }, 1000);
            }

            async function doCheck(){
                if (!IS_ON_HOLD){
                    lockButton(STATUS_SLUG);
                    showInfo("err", "Order is not On-Hold. Checking is disabled.");
                    return;
                }
                const fd = new FormData(form);
                btn.disabled = true;
                btn.dataset.origLabel = btn.textContent;
                btn.innerHTML = \'<span class="wc-bp-spin" style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;vertical-align:-2px;margin-right:6px;"></span> Checking…\';
                showInfo("warn", "Checking…");
                showStatus("");

                try {
                    const r = await fetch(REQ_URL, { method: "POST", body: fd, credentials: "same-origin" });
                    const ct = (r.headers.get("content-type") || "").toLowerCase();
                    if (ct.includes("application/json")){
                        const json = await r.json();
                        if (json && json.success){
                            const data = json.data || {};
                            showStatus(data.message || "Query succeeded.");
                            if (data.done){
                                IS_PAID = true; IS_ON_HOLD = false; STATUS_SLUG = data.status || "completed";
                                if (statusNameEl) statusNameEl.textContent = humanStatus(STATUS_SLUG);
                                showInfo("ok", "Your payment has been received.");
                                lockButton(STATUS_SLUG);
                                if (viewBtn) { viewBtn.style.display = "inline-block"; }
                                setTimeout(function(){ try { location.reload(); } catch(e){} }, 800);
                                return;
                            } else {
                                showInfo("warn", data.message || "Still On-Hold, please ensure the memo/asset/amount are correct.");
                            }
                        } else {
                            const msg = (json && json.data && json.data.message) ? json.data.message :
                                        (json && json.data) ? json.data : "Query failed.";
                            showInfo("err", msg);
                        }
                    } else {
                        showInfo("err", "Non-JSON response. Please try again later.");
                    }
                } catch (e){
                    showInfo("err", "Network error. Please try again later.");
                    if (window.console) console.error("AJAX Error:", e);
                } finally {
                    startCooldown();
                }
            }

            if (btn){
                if (IS_ON_HOLD) {
                    btn.addEventListener("click", function(){ if (!btn.disabled) doCheck(); });
                } else {
                    lockButton(STATUS_SLUG);
                    if (IS_PAID && viewBtn) viewBtn.style.display = "inline-block";
                }
            }
        })();
    </script>';
}



    /** Generate unique memo */
    private function generate_memo(WC_Order $order) {
        $seed = $order->get_order_key() . '|' . $order->get_id() . '|' . wp_generate_password(12, false, false) . '|' . microtime(true);
        return strtoupper(substr(hash('sha256', $seed), 0, 12));
    }

    /* ================== Admin: image field (media uploader for QR) ================== */
    public function generate_image_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $defaults  = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'text',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
        );
        $data = wp_parse_args($data, $defaults);

        ob_start(); ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?> <?php echo $this->get_tooltip_html($data); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <input type="hidden" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" value="<?php echo esc_attr($this->get_option($key)); ?>" />
                    <div id="<?php echo esc_attr($field_key); ?>_preview" style="margin-bottom:10px;">
                        <?php
                        $image_id = $this->get_option($key);
                        if ($image_id) {
                            echo wp_get_attachment_image($image_id, 'medium');
                        } else {
                            echo '<p>' . esc_html__('No QR uploaded.', 'wc-binance-pay') . '</p>';
                        }
                        ?>
                    </div>
                    <button type="button" class="button upload_qr_button" data-field="<?php echo esc_attr($field_key); ?>">
                        <?php echo $image_id ? esc_html__('Replace QR', 'wc-binance-pay') : esc_html__('Upload QR', 'wc-binance-pay'); ?>
                    </button>
                    <button type="button" class="button remove_qr_button" data-field="<?php echo esc_attr($field_key); ?>" <?php echo $image_id ? '' : 'style="display:none;"'; ?>>
                        <?php echo esc_html__('Remove', 'wc-binance-pay'); ?>
                    </button>
                    <p class="description"><?php echo wp_kses_post($data['description']); ?></p>
                </fieldset>
            </td>
        </tr>

        <script>
        jQuery(function($) {
            $(document).on("click", ".upload_qr_button", function(e) {
                e.preventDefault();
                var button = $(this);
                var field = button.data("field");
                var frame = wp.media({
                    title: "<?php echo esc_js(__('Choose Receiving QR', 'wc-binance-pay')); ?>",
                    button: { text: "<?php echo esc_js(__('Use this image', 'wc-binance-pay')); ?>" },
                    multiple: false
                });
                frame.on("select", function() {
                    var attachment = frame.state().get("selection").first().toJSON();
                    $("#" + field).val(attachment.id);
                    $("#" + field + "_preview").html("<img src=\"" + attachment.url + "\" style=\"max-width:200px; height:auto;\" />");
                    button.text("<?php echo esc_js(__('Replace QR', 'wc-binance-pay')); ?>");
                    button.next(".remove_qr_button").show();
                });
                frame.open();
            });

            $(document).on("click", ".remove_qr_button", function(e) {
                e.preventDefault();
                var button = $(this);
                var field = button.data("field");
                $("#" + field).val("");
                $("#" + field + "_preview").html("<p><?php echo esc_js(__('No QR uploaded.', 'wc-binance-pay')); ?></p>");
                button.hide().prev(".upload_qr_button").text("<?php echo esc_js(__('Upload QR', 'wc-binance-pay')); ?>");
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /* ================== Admin field renderer: debug link (new tab) ================== */
    public function generate_api_test_link_html($key, $data) {
        $field_key  = $this->get_field_key($key);
        $nonce_name = defined('WC_BINANCE_NONCE_NAME')   ? WC_BINANCE_NONCE_NAME   : 'wc_binance_pay_nonce';
        $nonce_act  = defined('WC_BINANCE_NONCE_ACTION') ? WC_BINANCE_NONCE_ACTION : 'wc_binance_pay_nonce_action';
        $nonce_val  = wp_create_nonce($nonce_act);
        $ajax_act   = defined('WC_BINANCE_AJAX_TEST')    ? WC_BINANCE_AJAX_TEST    : 'wc_binance_pay_api_test';
        $ajax_url   = admin_url('admin-ajax.php');

        // View latest record only → last=1
        $debug_url  = add_query_arg(array(
            'action'               => $ajax_act,
            $nonce_name            => $nonce_val,
            'last'                 => '1',
        ), $ajax_url);

        ob_start(); ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html($data['title']); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <a href="<?php echo esc_url($debug_url); ?>" target="_blank" class="button button-primary"><?php esc_html_e('Open Debug Window (New Tab)', 'wc-binance-pay'); ?></a>
                    <p class="description"><?php echo wp_kses_post($data['description']); ?></p>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }
}
