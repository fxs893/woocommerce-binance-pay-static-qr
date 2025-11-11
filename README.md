# WooCommerce Binance Pay（静态二维码）  
# WooCommerce Binance Pay (Static QR)

**简介（中文）**  
使用个人币安账户的 **“发送与接收 → 收款”** 二维码收款。买家在 Binance App 扫码并粘贴系统生成的备注（Memo），插件通过 SAPI 读取 **/sapi/v1/pay/transactions**，按 **备注 + 金额 + 币种（USDT/USDC）** 严格校验，仅针对 **Binance Pay（C2C/PAY）**，不校验链上充值。

**Overview (English)**  
Accept payments with your personal Binance **“Send & Receive → Receive”** QR. Customers scan in the Binance App and paste the generated memo. The plugin calls **/sapi/v1/pay/transactions** and strictly verifies **memo + amount + asset (USDT/USDC)**. It checks **Binance Pay (C2C/PAY)** only, not on-chain deposits.

---

## 安装 / Installation

**中文**  
从以下地址获取代码（或直接克隆）：  
<https://github.com/fxs893/woocommerce-binance-pay-static-qr.git>  
将插件放入 `/wp-content/plugins/woocommerce-binance-pay/`，然后在后台 **插件 → 启用**。

**English**  
Get the code (or clone) from:  
<https://github.com/fxs893/woocommerce-binance-pay-static-qr.git>  
Place the plugin under `/wp-content/plugins/woocommerce-binance-pay/` and **activate** it in WordPress **Plugins**.

---

## 配置 / Configuration

**中文**  
1. 进入 **WooCommerce → 设置 → 付款 → Binance Pay (Static QR)** 并启用；  
2. 上传你的 **收款二维码**（Binance App：发送与接收 → 收款）；  
3. 填写 **API Key / Secret**（需能访问 `/sapi/v1/pay/transactions`，且账户已开通 Binance Pay）；  
4. （可选）点击 **Open Debug Window** 检查能否读取最近一笔 Binance Pay 记录。

**English**  
1. Go to **WooCommerce → Settings → Payments → Binance Pay (Static QR)** and enable;  
2. Upload your **Receiving QR** (Binance App: *Send & Receive → Receive*);  
3. Enter **API Key / Secret** (must access `/sapi/v1/pay/transactions`, Binance Pay enabled);  
4. *(Optional)* Click **Open Debug Window** to confirm the latest Binance Pay record is readable.

---

## 使用 / How to Use

**中文**  
在结账页选择 **Binance Pay (USDT/USDC)** 并选择币种 → 下单后在感谢页：  
- 用 Binance 扫描你的二维码；  
- 把页面给出的 **备注（Memo）** 粘贴到 Binance Pay 备注；  
- 付款完成后点击 **“我已支付，检查到账”**；  
- 系统按 **备注 + 金额 + 币种** 自动核验：匹配成功则标记为已支付；多/少付 **≥ 0.5** 时订单保持 **On-Hold** 并提示差额。

**English**  
At checkout choose **Binance Pay (USDT/USDC)** and select the asset → On the Thank You page:  
- Scan the QR with Binance App;  
- Paste the **memo** shown on the page into Binance Pay;  
- After paying, click **“I have paid, check now”**;  
- The plugin verifies **memo + amount + asset**: on match the order is marked paid; if over/under **≥ 0.5**, the order remains **On-Hold** with a note.

---

## 提示 / Tips

**中文**  
未匹配到收款时，请核对是否已粘贴备注、金额是否一致、币种是否一致、以及是否同一币安账户；若接口返回为空，通常是 **Binance Pay 未开通或 API 权限不足**。

**English**  
If no match is found, verify the memo was pasted, the amount and asset match, and you’re using the same Binance account. Empty API results usually mean **Binance Pay isn’t enabled or the API lacks permissions**.

---

## 打赏支持 / Support the Developer

**中文**  
如果这个插件对你有帮助，欢迎使用 Binance Pay 打赏支持 🙏

**English**  
If this plugin helps you, feel free to tip via Binance Pay 🙏

<p align="center">
  <img src="[https://raw.githubusercontent.com/fxs893/woocommerce-binance-pay-static-qr/refs/heads/main/44fb74656fea3699c388d7bf3ca69e5f-225x300.jpg]" alt="Donate via Binance Pay" width="260">
</p>
