# YS TranslatePress Addons

TranslatePress 免費版的增強套件 — 由 YANGSHEEP DESIGN 開發與維護。

搭配 [TranslatePress Multilingual](https://wordpress.org/plugins/translatepress-multilingual/) 使用，為多語網站提供解鎖語言數量、Mac ChatGPT 翻譯、SEO、語言切換器、語言偵測、選單與內容語言控制、翻譯匯出匯入等強化功能。

## Fleet fork

- 搭配 TranslatePress Multilingual 3.4 驗證。在「多語增強」啟用需要的模組。翻譯者角色使用與官方外掛相同的 `translate_strings` 權限，管理員可於 WordPress 使用者頁指派。翻譯者可到「翻譯網站」，管理員可在編輯器預覽常見角色。
- Google Cloud Translation Basic v2 由 TranslatePress 核心設定金鑰與用量。啟用「Google 翻譯術語」後可在核心的術語表設定固定用詞。術語隨新翻譯套用，取決於供應商保留相應保護標記。以真實金鑰試點時應驗證關鍵術語，並保留人工審閱流程。
- 在「SEO 增強」啟用翻譯 slug 和自動翻譯 slug，新發布的公開文章、頁面及自訂內容可由 Google v2 搭配 WP-Cron 補譯。另可透過管理端的 Mac 翻譯流程領取待譯網址並在審核後寫回。既有人工 slug、已翻譯的舊 slug、分類和基底依原本設定讀取。轉移前應備份並逐一驗證已收錄路徑。
- IP 偵測可使用 WooCommerce 已設定的本機 GeoIP 資料庫。若站台僅能經過可信任且覆寫該標頭的 Cloudflare 入口，可在 wp-config.php 設定 `YS_TP_TRUST_CF_IPCOUNTRY` 為 true。其他可信任的國碼來源可使用 `ys_tp_detect_country` filter。
- 每語言獨立網域與跨網域登入未實作。依賴該功能的站台需繼續使用原外掛。

## 功能總覽

| 模組 | 說明 |
|------|------|
| 解鎖語言數量 | 移除免費版「僅 1 個次要語言」限制，可新增無限多語言 |
| 語言切換器 | 全新設計的切換短代碼：固定浮動、彈出視窗、下拉選單等樣式 |
| 語言自動偵測 | 依瀏覽器語言／IP 自動建議語言，重新設計的精緻提示介面 |
| 選單語言控制 | 針對每個選單項目設定在哪些語言顯示或隱藏 |
| 內容語言規則 | 頁面／文章／自訂內容可排除或指定語言顯示，並可設定切換語言時的智慧重導 |
| Mac ChatGPT 翻譯 | 管理員可設定公開頁面翻譯排程與自動寫回。Mac 透過受限 SSH 領取工作，站台也可啟用在已上線頁面儲存時補譯。 |
| SEO 增強 | 翻譯網址 slug、hreflang 與多語 sitemap（相容 Yoast／RankMath） |
| 翻譯匯出／匯入 | 全站或單頁匯出翻譯文字，外部編修後再匯入 |

每個模組皆可於後台「多語增強 → 總覽」獨立啟用或停用。

## 系統需求

- WordPress 6.0 以上
- PHP 8.0 以上
- TranslatePress Multilingual（免費版）已安裝並啟用
- 已設定為「漂亮的永久連結」（Pretty Permalinks）

## Mac ChatGPT 翻譯

- 維運人員先設定受限 SSH 與 Mac 排程。管理員可在「多語增強」總覽啟用或暫停，並在設定頁調整掃描間隔及自動寫回。
- 使用支援頁面補譯的站端程式和 Mac 排程時，可透過站台設定 `YS_TP_MAC_UPDATE_PAGES` 啟用已開放語言的更新補譯。
- 翻譯僅處理公開頁面，Mac 會把符合條件的待譯文字交給 ChatGPT。

## 開發者

**YANGSHEEP DESIGN** — https://yangsheep.com.tw
