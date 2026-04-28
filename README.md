# Trending Summary — RSS 趨勢文章摘要產製系統

從多個 RSS feed 抓取文章與影片，比對 Google Trends 搜尋趨勢後，透過 AI 模板將符合趨勢的文章改寫為高品質繁體中文摘要的 Laravel Package。

## 系統需求

- PHP 8.4+
- Laravel 12+
- 資料庫（MySQL / PostgreSQL / SQLite）
- Queue Driver（database / redis / SQS 等）
- Node.js 20+（前端 build 用）

## 安裝

### Step 1：加入本地套件路徑

在宿主專案的 `composer.json` 加入 repository：

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/trending-summary"
        }
    ]
}
```

> 路徑依實際位置調整。若套件在專案外部，使用絕對路徑或相對路徑如 `../packages/trending-summary`。

### Step 2：安裝套件

```bash
composer require tnlmedia/trending-summary:@dev
```

Laravel 會透過 Package Discovery 自動註冊 ServiceProvider，不需手動設定。

### Step 3：發佈設定檔

```bash
php artisan vendor:publish --tag=trending-summary-config
```

設定檔會發佈至 `config/trending-summary.php`，可依需求調整。

### Step 4：執行 Migration

```bash
php artisan migrate
```

會建立 9 張資料表（預設前綴 `ts_`）：

| 資料表 | 用途 |
|--------|------|
| `ts_article_templates` | 文章模板 |
| `ts_trending_articles` | 主文章表 |
| `ts_trend_keywords` | 趨勢關鍵字 |
| `ts_generated_titles` | AI 標題候選 |
| `ts_article_images` | 文章配圖 |
| `ts_article_subtitles` | 影片字幕 |
| `ts_article_seo` | SEO 資料 |
| `ts_quality_reports` | 品質報告 |
| `ts_publish_records` | 發佈記錄 |

### Step 5：種入預設模板

```bash
php artisan db:seed --class="TnlMedia\TrendingSummary\Database\Seeders\DefaultTemplateSeeder"
```

會建立 5 個內建模板：trending-summary、deep-dive、listicle、comparison、breaking-news。

### Step 6：設定環境變數

在 `.env` 中加入必要設定：

```env
# ── 必要：AI 模型 API Key ──────────────────────────
GEMINI_API_KEY=your-gemini-api-key

# ── 可選：運作模式 ─────────────────────────────────
TRENDING_MODE=review                    # review（預設）| auto
TRENDING_AUTO_PUBLISH_THRESHOLD=80      # 自動發佈品質門檻（0-100）

# ── 可選：排程 ─────────────────────────────────────
TRENDING_SCHEDULE_ENABLED=true          # 啟用自動排程

# ── 可選：SEO ──────────────────────────────────────
TRENDING_SEO_SITE_NAME="My Site"
TRENDING_SEO_SITE_URL=https://example.com
TRENDING_SEO_AUTHOR_NAME="My Organization"

# ── 可選：InkMagine CMS 發佈 ──────────────────────
PUBLISH_INKMAGINE_ENABLED=true
INKMAGINE_GATEWAY_URL=https://gateway.inkmaginecms.com
INKMAGINE_CLIENT_ID=your-client-id
INKMAGINE_CLIENT_SECRET=your-client-secret
INKMAGINE_TEAM_ID=your-team-id

# ── 可選：WordPress 發佈 ──────────────────────────
PUBLISH_WORDPRESS_ENABLED=false
WORDPRESS_SITE_URL=https://your-wordpress.com
WORDPRESS_AUTH_METHOD=application_password
WORDPRESS_USERNAME=admin
WORDPRESS_APP_PASSWORD=xxxx-xxxx-xxxx-xxxx
WORDPRESS_SEO_PLUGIN=yoast              # yoast | rankmath

# ── 可選：InkMagine 圖庫搜尋 ─────────────────────
INKMAGINE_ENABLED=false

# ── 可選：Google Search Console ───────────────────
TRENDING_SEARCH_CONSOLE_ENABLED=false
TRENDING_SEARCH_CONSOLE_SITE_URL=https://example.com
GOOGLE_APPLICATION_CREDENTIALS=/path/to/credentials.json

# ── 可選：切換 AI Driver ──────────────────────────
# AI_EMBEDDING_DRIVER=openai
# AI_EMBEDDING_MODEL=text-embedding-3-small
# AI_LLM_DRIVER=openai
# AI_LLM_MODEL=gpt-4o
# OPENAI_API_KEY=your-openai-key
```

### Step 7：建置前端 SPA

```bash
cd packages/trending-summary/frontend
npm install
npm run build
```

發佈前端 assets 至宿主專案：

```bash
php artisan vendor:publish --tag=trending-summary-assets
```

前端檔案會發佈至 `public/vendor/trending-summary/`。

---

## 驗證安裝

### 1. 確認 ServiceProvider 載入

```bash
php artisan about
```

應看到 Trending Summary 相關資訊。

### 2. 確認路由註冊

```bash
php artisan route:list --path=trending-summary
```

應列出約 20 條 API 路由與 1 條 Web catch-all 路由。

### 3. 確認模板已種入

```bash
php artisan tinker
>>> \TnlMedia\TrendingSummary\Models\ArticleTemplate::count()
// 應回傳 5
>>> \TnlMedia\TrendingSummary\Models\ArticleTemplate::pluck('slug')
// ['trending-summary', 'deep-dive', 'listicle', 'comparison', 'breaking-news']
```

### 4. 確認 Artisan Commands 可用

```bash
php artisan list articles
```

應看到：
- `articles:sync-trending` — 完整 pipeline
- `articles:fetch-and-filter` — 僅抓取與篩選

---

## 測試流程

### Phase 1：RSS 抓取（不需 AI API Key）

```bash
php artisan articles:fetch-and-filter
```

觀察 console 輸出：
- 步驟 1：RSS 抓取 → 應顯示同步的文章數
- 步驟 2：趨勢關鍵字同步 → 應顯示關鍵字數
- 步驟 3：Search Console → 未啟用時顯示跳過
- 步驟 4/5：Embedding 與 LLM 篩選 → 需要 AI API Key，無 key 時會顯示錯誤但不中斷

驗證資料：

```bash
php artisan tinker
>>> \TnlMedia\TrendingSummary\Models\TrendingArticle::count()
// 應 > 0
>>> \TnlMedia\TrendingSummary\Models\TrendKeyword::count()
// 應 > 0
>>> \TnlMedia\TrendingSummary\Models\TrendingArticle::where('status', 'pending')->count()
// 應等於抓取的文章數
```

### Phase 2：完整 Pipeline（需要 AI API Key）

確認 `.env` 已設定 `GEMINI_API_KEY`，然後：

```bash
# 啟動 queue worker（另開終端）
php artisan queue:work

# 執行完整 pipeline
php artisan articles:sync-trending
```

觀察 console 輸出的 6 個步驟：
1. 📡 RSS 抓取
2. 📈 趨勢關鍵字同步
3. 🔍 Search Console 合併
4. 🧮 Embedding 粗篩 → 部分文章變為 `candidate`
5. 🤖 LLM 精篩 → 部分文章變為 `filtered`
6. 📝 派發 GenerateSummaryJob → queue worker 會執行摘要生成

驗證文章狀態流轉：

```bash
php artisan tinker
>>> \TnlMedia\TrendingSummary\Models\TrendingArticle::selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt', 'status')
// 應看到 pending, candidate, filtered, generated, reviewing 等狀態
```

### Phase 3：API 端點測試

> 注意：預設 API middleware 包含 `auth:sanctum`。測試時可暫時在 `config/trending-summary.php` 中改為 `['api']`。

```bash
# 趨勢關鍵字
curl -s http://localhost:8000/api/trending-summary/trends/keywords | jq .

# 統計數據
curl -s http://localhost:8000/api/trending-summary/trends/stats | jq .

# 文章列表
curl -s http://localhost:8000/api/trending-summary/articles | jq .

# 文章列表（篩選審核中）
curl -s "http://localhost:8000/api/trending-summary/articles?status=reviewing" | jq .

# 模板列表
curl -s http://localhost:8000/api/trending-summary/templates | jq .

# 單篇文章詳情（含所有關聯資料）
curl -s http://localhost:8000/api/trending-summary/articles/1 | jq .

# 系統設定
curl -s http://localhost:8000/api/trending-summary/settings | jq .
```

### Phase 4：前端 SPA

瀏覽 `http://localhost:8000/trending-summary`

應看到：
- **Dashboard**：趨勢關鍵字、待審文章數、已發佈統計
- **Review**（`/trending-summary/review`）：文章列表 + 審核介面
- **Templates**（`/trending-summary/templates`）：模板管理
- **Settings**（`/trending-summary/settings`）：系統設定

### Phase 5：審核流程測試

1. 進入 Review 頁面
2. 選擇一篇 `reviewing` 狀態的文章
3. 檢查：原文 vs 摘要對照、AI 標題候選、配圖、SEO 資料、品質報告
4. 選擇標題 → 點擊「通過」
5. 文章狀態應變為 `approved`
6. 選擇發佈目標 → 點擊「發佈」
7. 確認 queue worker 執行 PublishArticleJob

### Phase 6：套件內部測試

```bash
cd packages/trending-summary
composer install
./vendor/bin/pest
```

目前有 49 個測試（ArticleTemplateService 27 個 + ArticleGeneratorService 22 個），應全部通過。

---

## Artisan Commands

| 命令 | 說明 |
|------|------|
| `articles:sync-trending` | 執行完整 pipeline：RSS → 趨勢 → 篩選 → 摘要生成 |
| `articles:fetch-and-filter` | 僅執行 RSS 抓取與兩層篩選（不生成摘要） |

## 排程

當 `TRENDING_SCHEDULE_ENABLED=true` 時，`articles:sync-trending` 會自動排程執行。

頻率由 `config('trending-summary.schedule.frequency')` 控制：
- `hourly` — 每小時（預設）
- `every_two_hours` — 每兩小時
- `daily` — 每天

確保宿主專案的 cron 已設定：

```
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

---

## 常見問題排錯

| 問題 | 排查方式 |
|------|---------|
| 路由 404 | `php artisan route:list --path=trending-summary` 確認路由有註冊 |
| Migration 失敗 | 確認已執行 `vendor:publish --tag=trending-summary-config` |
| RSS 抓取 0 篇 | 檢查 config 中的 feed URLs 是否可達，可用 `curl` 測試 |
| AI 呼叫失敗 | 確認 `GEMINI_API_KEY` 已設定且有效 |
| Embedding 篩選全部 rejected | 調低 `config('trending-summary.ai.embedding.threshold')`（預設 0.75） |
| Queue Job 沒執行 | 確認 `php artisan queue:work` 正在運行 |
| 前端空白頁 | 確認已執行 `npm run build` 並 `vendor:publish --tag=trending-summary-assets` |
| 前端 API 401 | 預設 middleware 含 `auth:sanctum`，測試時可暫改為 `['api']` |
| 自動發佈沒觸發 | 確認 `TRENDING_MODE=auto` 且文章 quality_score ≥ threshold |
| 字幕翻譯失敗 | 檢查 `storage/logs/laravel.log` 中的 SubtitleService 錯誤 |

---

## AI Driver 切換

預設使用 Gemini，可透過環境變數切換：

```env
# 全部切換為 OpenAI
AI_EMBEDDING_DRIVER=openai
AI_EMBEDDING_MODEL=text-embedding-3-small
AI_LLM_DRIVER=openai
AI_LLM_MODEL=gpt-4o
AI_TRANSLATION_DRIVER=openai
AI_TRANSLATION_MODEL=gpt-4o
OPENAI_API_KEY=sk-...

# 或混合使用：Embedding 用 OpenAI，LLM 用 Gemini
AI_EMBEDDING_DRIVER=openai
AI_EMBEDDING_MODEL=text-embedding-3-small
AI_LLM_DRIVER=gemini
AI_LLM_MODEL=gemini-2.5-flash
```

各 LLM 子任務也可獨立覆寫，在 `config/trending-summary.php` 的 `ai.llm.roles` 中設定：

```php
'roles' => [
    'generation' => [
        'model' => 'gemini-2.5-pro',  // 摘要生成用更高品質模型
    ],
    'relevance' => [
        'temperature' => 0.3,          // 篩選用低溫度求穩定
    ],
    'title' => [
        'temperature' => 0.9,          // 標題提案用高溫度求創意
    ],
],
```

---

## 目錄結構

```
packages/trending-summary/
├── config/                          # 設定檔
├── database/
│   ├── migrations/                  # 9 張資料表 Migration
│   └── seeders/                     # DefaultTemplateSeeder
├── frontend/                        # Vue 3 SPA
│   ├── src/
│   │   ├── api/                     # API Client Layer（7 個模組）
│   │   ├── components/              # Vue 元件（14 個）
│   │   ├── pages/                   # 頁面元件（4 個）
│   │   ├── router/                  # Vue Router
│   │   ├── stores/                  # Pinia Stores（3 個）
│   │   └── types/                   # TypeScript 型別定義
│   ├── package.json
│   ├── vite.config.ts
│   └── tsconfig.json
├── resources/views/                 # Blade SPA 入口
├── routes/                          # API + Web 路由
├── src/
│   ├── Console/                     # Artisan Commands（2 個）
│   ├── Contracts/                   # 介面定義（6 個）
│   ├── DataTransferObjects/         # DTO（PublishResult）
│   ├── Events/                      # Events（3 個）
│   ├── Facades/                     # Facade
│   ├── Http/
│   │   ├── Controllers/Api/         # API Controllers（7 個）
│   │   ├── Requests/                # Form Requests（3 個）
│   │   └── Resources/               # API Resources（3 個）
│   ├── Jobs/                        # Queue Jobs（4 個）
│   ├── Models/                      # Eloquent Models（9 個）
│   ├── Services/                    # 核心服務（14 個）
│   │   ├── Drivers/                 # AI Drivers（2 個）
│   │   └── Publishers/              # 發佈器（2 個）
│   └── TrendingSummaryServiceProvider.php
├── tests/                           # Pest 測試
├── composer.json
└── README.md
```
