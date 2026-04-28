<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 路由設定
    |--------------------------------------------------------------------------
    */
    'route_prefix' => 'trending-summary',
    'api_prefix' => 'api/trending-summary',
    'middleware' => ['web', 'auth'],
    'api_middleware' => ['api', 'auth:sanctum'],

    /*
    |--------------------------------------------------------------------------
    | RSS Feed 來源
    |--------------------------------------------------------------------------
    |
    | 每個來源可指定 name（來源名稱）、url（RSS feed URL）、
    | content_type（預設內容類型：article 或 video）。
    |
    */
    'feeds' => [
        'sources' => [
            [
                'name' => 'Business Insider Articles',
                'url' => 'https://feeds.businessinsider.com/custom/international-feed-articles',
                'content_type' => 'article',
            ],
            [
                'name' => 'Business Insider Videos',
                'url' => 'https://feeds.businessinsider.com/custom/international-feed-videos',
                'content_type' => 'video',
            ],
            [
                'name' => 'Business Insider Slideshows',
                'url' => 'https://feeds.businessinsider.com/custom/international-feed-slideshows',
                'content_type' => 'article',
            ],
            [
                'name' => 'Roomie',
                'url' => 'https://www.roomie.jp/_rm_rmk_taiwan/rm_taiwan_distribution.php',
                'content_type' => 'article',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Trends
    |--------------------------------------------------------------------------
    */
    'trends' => [
        'rss_url' => 'https://trends.google.com.tw/trending/rss?geo=TW',
        'cache_ttl' => 3600, // 秒
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Search Console（可選）
    |--------------------------------------------------------------------------
    */
    'search_console' => [
        'enabled' => env('TRENDING_SEARCH_CONSOLE_ENABLED', false),
        'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS'),
        'site_url' => env('TRENDING_SEARCH_CONSOLE_SITE_URL'),
        'days' => (int) env('TRENDING_SEARCH_CONSOLE_DAYS', 7),
        'limit' => (int) env('TRENDING_SEARCH_CONSOLE_LIMIT', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI 模型設定（每個角色可獨立指定 driver + model）
    |--------------------------------------------------------------------------
    |
    | 每個角色（role）獨立設定 driver 與 model，可自由替換。
    | driver 選項：'gemini' | 'openai' | 'anthropic' | 'openai-compatible'
    |
    | 預設全部使用 Gemini。
    | 宿主專案可在 config 中覆寫任一角色的 driver/model，
    | 也可透過 ServiceProvider 直接綁定自訂實作覆寫整個介面。
    |
    */
    'ai' => [

        // ── 全域 driver 連線設定 ──────────────────────────────
        'drivers' => [

            'gemini' => [
                'api_key' => env('GEMINI_API_KEY'),
                'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
                'timeout' => 60,
            ],

            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'timeout' => 60,
            ],

            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
                'timeout' => 60,
            ],

            'openai-compatible' => [
                'api_key' => env('OPENAI_COMPATIBLE_API_KEY', ''),
                'base_url' => env('OPENAI_COMPATIBLE_BASE_URL'),
                'timeout' => 120,
            ],
        ],

        // ── 各角色的模型指派 ──────────────────────────────────

        // 1. Embedding（向量嵌入）— 文章與趨勢關鍵字的相似度粗篩
        'embedding' => [
            'driver' => env('AI_EMBEDDING_DRIVER', 'gemini'),
            'model' => env('AI_EMBEDDING_MODEL', 'text-embedding-004'),
            'dimensions' => 768,
            'threshold' => 0.75,
        ],

        // 2. LLM（文字生成）— 語意精篩、摘要生成、標題提案、SEO、FAQ
        //    各子任務可進一步覆寫 driver/model（不覆寫則繼承此預設值）
        'llm' => [
            'driver' => env('AI_LLM_DRIVER', 'gemini'),
            'model' => env('AI_LLM_MODEL', 'gemini-2.5-flash'),
            'temperature' => 0.7,
            'max_tokens' => 4096,

            // 子任務覆寫（可選）
            'roles' => [
                'relevance' => [            // 語意精篩（低溫度求穩定）
                    // 'temperature' => 0.3,
                ],
                'generation' => [           // 摘要生成
                    // 'model' => 'gemini-2.5-pro',  // 需要更高品質時切換
                ],
                'title' => [                // 標題提案（高溫度求創意）
                    // 'temperature' => 0.9,
                ],
                'seo' => [                  // SEO 欄位 + FAQ 產生
                    // 'model' => 'gemini-2.5-pro',
                ],
                'quality' => [              // 品質檢查
                    // 'model' => 'gemini-2.5-pro',
                ],
            ],
        ],

        // 3. Translation（翻譯）— 文章翻譯 + 字幕翻譯
        'translation' => [
            'driver' => env('AI_TRANSLATION_DRIVER', 'gemini'),
            'model' => env('AI_TRANSLATION_MODEL', 'gemini-2.5-flash'),
            'temperature' => 0.3,
            'max_tokens' => 2048,
            'target_language' => 'zh-TW',
            'skip_if_chinese' => true,
            'batch_size' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 圖片來源設定
    |--------------------------------------------------------------------------
    */
    'images' => [
        'cna_domains' => [
            'imgcdn.cna.com.tw',
            'www.cna.com.tw',
        ],
        'inkmagine' => [
            'enabled' => env('INKMAGINE_ENABLED', false),
            'gateway_url' => env('INKMAGINE_GATEWAY_URL', 'https://gateway.inkmaginecms.com'),
            'client_id' => env('INKMAGINE_CLIENT_ID'),
            'client_secret' => env('INKMAGINE_CLIENT_SECRET'),
            'team_id' => env('INKMAGINE_TEAM_ID'),
            'preferred_version' => 'social', // social | desktop | mobile | list
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 排程設定
    |--------------------------------------------------------------------------
    */
    'schedule' => [
        'enabled' => env('TRENDING_SCHEDULE_ENABLED', true),
        'frequency' => 'hourly', // hourly | every_two_hours | daily
    ],

    /*
    |--------------------------------------------------------------------------
    | 快取設定
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'driver' => env('TRENDING_CACHE_DRIVER', 'database'),
        'embedding_ttl' => 86400,    // 24 小時
        'translation_ttl' => 604800, // 7 天
        'trends_ttl' => 3600,        // 1 小時
    ],

    /*
    |--------------------------------------------------------------------------
    | 資料表前綴
    |--------------------------------------------------------------------------
    */
    'table_prefix' => env('TRENDING_TABLE_PREFIX', 'ts_'),

    /*
    |--------------------------------------------------------------------------
    | 影片字幕翻譯設定
    |--------------------------------------------------------------------------
    */
    'subtitle' => [
        'enabled' => env('TRENDING_SUBTITLE_ENABLED', true),

        // 支援的原始字幕格式
        'supported_formats' => ['srt', 'vtt'],

        // 翻譯設定（翻譯引擎走 ai.translation 角色設定）
        'translation' => [
            'max_chars_per_line' => 18,       // 單行字幕最大字數
            'batch_size' => 25,               // 每批翻譯句數
            'context_window' => 3,            // 翻譯時帶入前後句數
        ],

        // 輸出格式
        'output_formats' => ['srt', 'vtt'],
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO 自動補全設定
    |--------------------------------------------------------------------------
    */
    'seo' => [
        'enabled' => env('TRENDING_SEO_ENABLED', true),

        // 站台資訊（用於 JSON-LD publisher / OG site_name）
        'site_name' => env('TRENDING_SEO_SITE_NAME', 'Trending Summary'),
        'site_url' => env('TRENDING_SEO_SITE_URL'),
        'site_logo' => env('TRENDING_SEO_SITE_LOGO'),
        'locale' => 'zh_TW',

        // Meta 限制
        'meta_title_max' => 60,
        'meta_description_max' => 160,

        // Slug 設定
        'slug_style' => 'english',       // 'english'（翻譯為英文）| 'pinyin'（拼音）
        'slug_max_words' => 8,

        // Canonical
        'canonical_to_original' => true,  // true: 指向原文 URL，false: 指向自家 URL

        // JSON-LD
        'json_ld_enabled' => true,
        'author_type' => 'Organization', // 'Organization' | 'Person'
        'author_name' => env('TRENDING_SEO_AUTHOR_NAME', 'Trending Summary'),

        // AI Overview 優化（GEO）
        'aio' => [
            'enabled' => true,
            'direct_answer_block' => true,       // 自動產生文首直接回答段落
            'direct_answer_sentences' => 4,      // 直接回答段落句數（3-5）
            'question_headings' => true,         // H2/H3 自動改寫為問句
            'faq_enabled' => true,               // 自動產生 FAQ 段落 + FAQPage Schema
            'faq_count' => 4,                    // FAQ 問答數量（3-5）
            'faq_schema_enabled' => true,        // 產生 FAQPage JSON-LD
            'min_content_length' => 300,         // 摘要最低字數（過短不易被 AIO 引用）
            'scannable_formats' => true,         // 自動加入條列/表格等可掃描格式
            'freshness_reminder_days' => 90,     // 內容新鮮度提醒週期（天）
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 發佈目標設定
    |--------------------------------------------------------------------------
    */
    'publishing' => [
        // 預設發佈目標（可在前端覆寫）
        'default_targets' => ['inkmagine'],  // ['inkmagine'] | ['wordpress'] | ['inkmagine', 'wordpress']

        // 預設發佈狀態
        'default_status' => 'published',     // 'published' | 'draft'

        // InkMagine CMS
        'inkmagine' => [
            'enabled' => env('PUBLISH_INKMAGINE_ENABLED', true),
            'gateway_url' => env('INKMAGINE_GATEWAY_URL', 'https://gateway.inkmaginecms.com'),
            'client_id' => env('INKMAGINE_CLIENT_ID'),
            'client_secret' => env('INKMAGINE_CLIENT_SECRET'),
            'team_id' => env('INKMAGINE_TEAM_ID'),
            'article_type' => env('INKMAGINE_ARTICLE_TYPE', 'news'),
            'default_author_id' => env('INKMAGINE_DEFAULT_AUTHOR_ID'),
            'status_mapping' => [
                'published' => 'published',
                'draft' => 'draft',
            ],
            // 分類對應：本地分類 slug → InkMagine term ID
            'term_mapping' => [
                // 'technology' => 'inkmagine-term-uuid-1',
                // 'business' => 'inkmagine-term-uuid-2',
            ],
        ],

        // WordPress
        'wordpress' => [
            'enabled' => env('PUBLISH_WORDPRESS_ENABLED', false),
            'site_url' => env('WORDPRESS_SITE_URL'),
            'auth_method' => env('WORDPRESS_AUTH_METHOD', 'application_password'),
            // 'application_password' | 'jwt' | 'oauth'
            'username' => env('WORDPRESS_USERNAME'),
            'application_password' => env('WORDPRESS_APP_PASSWORD'),
            'jwt_endpoint' => env('WORDPRESS_JWT_ENDPOINT'),
            'seo_plugin' => env('WORDPRESS_SEO_PLUGIN', 'yoast'),
            // 'yoast' | 'rankmath' | 'none'
            'default_author_id' => env('WORDPRESS_DEFAULT_AUTHOR_ID', 1),
            // 分類對應：本地分類 slug → WordPress category ID
            'category_mapping' => [
                // 'technology' => 5,
                // 'business' => 8,
            ],
            // 標籤對應：趨勢關鍵字自動建立為 WordPress tag
            'auto_create_tags' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 運作模式
    |--------------------------------------------------------------------------
    |
    | 'review' — 審核模式（預設）：AI 產出需經人工審核才發佈
    | 'auto'   — 自動模式：品質分數達門檻即自動發佈
    |
    */
    'mode' => env('TRENDING_MODE', 'review'), // 'review' | 'auto'
    'auto_publish_threshold' => (int) env('TRENDING_AUTO_PUBLISH_THRESHOLD', 80), // 0-100

];
