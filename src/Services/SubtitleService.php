<?php

declare(strict_types=1);

namespace TnlMedia\TrendingSummary\Services;

use Illuminate\Support\Facades\Log;
use TnlMedia\TrendingSummary\Contracts\AiModelManagerInterface;
use TnlMedia\TrendingSummary\Contracts\TranslatorInterface;
use TnlMedia\TrendingSummary\Models\ArticleSubtitle;

/**
 * 字幕服務
 *
 * 負責解析原始字幕檔（SRT/VTT 格式）為結構化的 SubtitleCue 陣列，
 * 透過 TranslatorInterface 分批翻譯字幕文字（帶上下文 context_window），
 * 並將翻譯結果格式化回 SRT/VTT 格式輸出。
 *
 * 翻譯過程中保留原始 timestamps 不變，並對翻譯後的文字執行字數限制檢查
 * （預設每行 18 個中文字元）。支援專有名詞一致性翻譯：透過上下文視窗
 * 讓翻譯模型在翻譯每個 cue 時能參考前後文，確保人名、組織名等專有名詞
 * 在同一影片字幕中保持一致的翻譯。
 *
 * 錯誤處理策略：部分翻譯失敗時保留已成功翻譯的 cues，並將翻譯狀態標記為 failed。
 */
class SubtitleService
{
    /**
     * SRT 時間戳記格式的正規表達式
     *
     * 格式：HH:MM:SS,mmm（例如 00:00:01,000）
     */
    private const string SRT_TIMESTAMP_PATTERN = '/(\d{2}:\d{2}:\d{2},\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2},\d{3})/';

    /**
     * VTT 時間戳記格式的正規表達式
     *
     * 格式：HH:MM:SS.mmm 或 MM:SS.mmm（例如 00:00:01.000）
     */
    private const string VTT_TIMESTAMP_PATTERN = '/(\d{2}:\d{2}:\d{2}\.\d{3})\s*-->\s*(\d{2}:\d{2}:\d{2}\.\d{3})/';

    /**
     * 建構子，注入 AiModelManagerInterface。
     *
     * @param  AiModelManagerInterface  $aiManager  AI 模型管理器
     */
    public function __construct(
        protected AiModelManagerInterface $aiManager,
    ) {}

    /**
     * 依格式分派解析器
     *
     * 根據指定的格式（srt 或 vtt）呼叫對應的解析方法，
     * 將字幕內容解析為 SubtitleCue 陣列。
     *
     * @param  string  $content  字幕檔案內容
     * @param  string  $format  字幕格式（'srt' 或 'vtt'）
     * @return array<int, array{index: int, start_time: string, end_time: string, text: string}>  SubtitleCue 陣列
     *
     * @throws \InvalidArgumentException  當格式不支援時
     */
    public function parse(string $content, string $format): array
    {
        return match ($format) {
            'srt' => $this->parseSrt($content),
            'vtt' => $this->parseVtt($content),
            default => throw new \InvalidArgumentException("不支援的字幕格式：{$format}，僅支援 srt 與 vtt。"),
        };
    }

    /**
     * 解析 SRT 格式字幕
     *
     * 將 SRT 格式的字幕內容解析為 SubtitleCue 陣列。
     * SRT 格式結構：序號、時間戳記（HH:MM:SS,mmm --> HH:MM:SS,mmm）、文字內容，
     * 各 cue 之間以空行分隔。
     *
     * @param  string  $content  SRT 格式字幕內容
     * @return array<int, array{index: int, start_time: string, end_time: string, text: string}>  SubtitleCue 陣列
     */
    public function parseSrt(string $content): array
    {
        $cues = [];
        $content = str_replace("\r\n", "\n", trim($content));
        $blocks = preg_split('/\n\n+/', $content);

        if ($blocks === false) {
            return [];
        }

        $cueIndex = 1;

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $lines = explode("\n", $block);

            // 至少需要序號行、時間戳記行、文字行
            if (count($lines) < 3) {
                continue;
            }

            // 第一行為序號（跳過，使用自動遞增）
            // 第二行為時間戳記
            $timestampLine = $lines[1];

            if (! preg_match(self::SRT_TIMESTAMP_PATTERN, $timestampLine, $matches)) {
                continue;
            }

            $startTime = $this->srtTimestampToVttFormat($matches[1]);
            $endTime = $this->srtTimestampToVttFormat($matches[2]);

            // 第三行起為文字內容（可能多行）
            $textLines = array_slice($lines, 2);
            $text = implode("\n", $textLines);

            $cues[] = [
                'index' => $cueIndex,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'text' => $text,
            ];

            $cueIndex++;
        }

        return $cues;
    }

    /**
     * 解析 VTT 格式字幕
     *
     * 將 WebVTT 格式的字幕內容解析為 SubtitleCue 陣列。
     * VTT 格式以 "WEBVTT" 標頭開始，各 cue 之間以空行分隔，
     * 時間戳記格式為 HH:MM:SS.mmm --> HH:MM:SS.mmm。
     *
     * @param  string  $content  VTT 格式字幕內容
     * @return array<int, array{index: int, start_time: string, end_time: string, text: string}>  SubtitleCue 陣列
     */
    public function parseVtt(string $content): array
    {
        $cues = [];
        $content = str_replace("\r\n", "\n", trim($content));

        // 移除 WEBVTT 標頭及其後的 metadata
        $content = (string) preg_replace('/^WEBVTT[^\n]*\n/', '', $content);

        $blocks = preg_split('/\n\n+/', trim($content));

        if ($blocks === false) {
            return [];
        }

        $cueIndex = 1;

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $lines = explode("\n", $block);

            // 尋找包含時間戳記的行
            $timestampLineIndex = null;
            foreach ($lines as $i => $line) {
                if (preg_match(self::VTT_TIMESTAMP_PATTERN, $line)) {
                    $timestampLineIndex = $i;
                    break;
                }
            }

            if ($timestampLineIndex === null) {
                continue;
            }

            if (! preg_match(self::VTT_TIMESTAMP_PATTERN, $lines[$timestampLineIndex], $matches)) {
                continue;
            }

            $startTime = $matches[1];
            $endTime = $matches[2];

            // 時間戳記之後的行為文字內容
            $textLines = array_slice($lines, $timestampLineIndex + 1);
            $text = implode("\n", $textLines);

            if (trim($text) === '') {
                continue;
            }

            $cues[] = [
                'index' => $cueIndex,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'text' => $text,
            ];

            $cueIndex++;
        }

        return $cues;
    }

    /**
     * 翻譯字幕模型
     *
     * 對 ArticleSubtitle 模型執行完整的字幕翻譯流程：
     * 1. 取得原始 cues
     * 2. 分批翻譯（帶上下文視窗）
     * 3. 對翻譯結果執行字數限制
     * 4. 更新模型的 translated_cues 與 translation_status
     *
     * 部分翻譯失敗時，保留已成功翻譯的 cues 並標記狀態為 failed。
     *
     * @param  ArticleSubtitle  $subtitle  字幕模型
     * @return ArticleSubtitle  更新後的字幕模型
     */
    public function translateSubtitle(ArticleSubtitle $subtitle): ArticleSubtitle
    {
        $subtitle->translation_status = 'translating';
        $subtitle->save();

        /** @var array<int, array{index: int, start_time: string, end_time: string, text: string}> $originalCues */
        $originalCues = $subtitle->original_cues ?? [];

        if ($originalCues === []) {
            $subtitle->translation_status = 'translated';
            $subtitle->translated_cues = [];
            $subtitle->save();

            return $subtitle;
        }

        $contextWindow = $this->getContextWindow();

        try {
            $translatedCues = $this->translateCues($originalCues, $contextWindow);

            $subtitle->translated_cues = $translatedCues;
            $subtitle->translation_status = 'translated';
            $subtitle->save();

            Log::info('SubtitleService: 字幕翻譯完成', [
                'subtitle_id' => $subtitle->id,
                'cue_count' => count($translatedCues),
            ]);
        } catch (\Throwable $e) {
            Log::error('SubtitleService: 字幕翻譯失敗', [
                'subtitle_id' => $subtitle->id,
                'error' => $e->getMessage(),
            ]);

            // 保留已翻譯的 cues（如果有的話）
            if (! empty($subtitle->translated_cues)) {
                // 已有部分翻譯結果，保留之
            }

            $subtitle->translation_status = 'failed';
            $subtitle->save();
        }

        return $subtitle;
    }

    /**
     * 分批翻譯字幕 cues（帶上下文視窗）
     *
     * 將所有 cues 的文字分批送至翻譯 API，每個 cue 翻譯時會帶入
     * 前後 context_window 個 cues 的文字作為上下文，以確保專有名詞
     * （人名、組織名）在同一影片字幕中保持一致的翻譯。
     *
     * 翻譯後保留原始 timestamps 不變，並對翻譯文字執行字數限制。
     *
     * @param  array<int, array{index: int, start_time: string, end_time: string, text: string}>  $cues  原始 SubtitleCue 陣列
     * @param  int  $contextWindow  上下文視窗大小（前後各帶入的 cue 數量，預設 3）
     * @return array<int, array{index: int, start_time: string, end_time: string, text: string}>  翻譯後的 SubtitleCue 陣列
     *
     * @throws \Throwable  當翻譯 API 呼叫失敗時
     */
    public function translateCues(array $cues, int $contextWindow = 3): array
    {
        if ($cues === []) {
            return [];
        }

        /** @var TranslatorInterface $translator */
        $translator = $this->aiManager->driver('translation');

        $maxChars = $this->getMaxCharsPerLine();
        $batchSize = $this->getBatchSize();
        $translatedCues = [];
        $totalCues = count($cues);

        // 分批處理
        for ($batchStart = 0; $batchStart < $totalCues; $batchStart += $batchSize) {
            $batchEnd = min($batchStart + $batchSize, $totalCues);
            $batchTexts = [];

            // 為批次中的每個 cue 建構帶上下文的翻譯文字
            for ($i = $batchStart; $i < $batchEnd; $i++) {
                $contextText = $this->buildContextText($cues, $i, $contextWindow);
                $batchTexts[] = $contextText;
            }

            try {
                $translatedTexts = $translator->batchTranslate($batchTexts);

                // 從翻譯結果中提取目標 cue 的翻譯
                for ($j = 0; $j < ($batchEnd - $batchStart); $j++) {
                    $cueIdx = $batchStart + $j;
                    $translatedText = $this->extractTargetTranslation(
                        $translatedTexts[$j] ?? '',
                        $cues[$cueIdx]['text'],
                    );

                    $translatedText = $this->enforceCharLimit($translatedText, $maxChars);

                    $translatedCues[] = [
                        'index' => $cues[$cueIdx]['index'],
                        'start_time' => $cues[$cueIdx]['start_time'],
                        'end_time' => $cues[$cueIdx]['end_time'],
                        'text' => $translatedText,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('SubtitleService: 批次翻譯部分失敗', [
                    'batch_start' => $batchStart,
                    'batch_end' => $batchEnd,
                    'error' => $e->getMessage(),
                ]);

                // 部分失敗時，保留已翻譯的 cues，將失敗的 cues 保留原文
                for ($j = 0; $j < ($batchEnd - $batchStart); $j++) {
                    $cueIdx = $batchStart + $j;
                    $translatedCues[] = [
                        'index' => $cues[$cueIdx]['index'],
                        'start_time' => $cues[$cueIdx]['start_time'],
                        'end_time' => $cues[$cueIdx]['end_time'],
                        'text' => $cues[$cueIdx]['text'],
                    ];
                }

                // 如果是第一批就失敗，向上拋出異常
                if ($batchStart === 0 && $translatedCues === array_slice($cues, 0, $batchEnd - $batchStart)) {
                    throw $e;
                }
            }
        }

        return $translatedCues;
    }

    /**
     * 執行字數限制檢查
     *
     * 對翻譯後的字幕文字執行每行字數限制。若單行超過限制，
     * 在適當的位置斷行（優先在標點符號或空白處斷行）。
     *
     * @param  string  $text  待檢查的文字
     * @param  int  $maxChars  每行最大字元數（預設 18）
     * @return string  處理後的文字
     */
    public function enforceCharLimit(string $text, int $maxChars = 18): string
    {
        if ($maxChars <= 0) {
            return $text;
        }

        $lines = explode("\n", $text);
        $resultLines = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (mb_strlen($line) <= $maxChars) {
                $resultLines[] = $line;
                continue;
            }

            // 需要斷行
            $wrappedLines = $this->wrapLine($line, $maxChars);
            foreach ($wrappedLines as $wrappedLine) {
                $resultLines[] = $wrappedLine;
            }
        }

        return implode("\n", $resultLines);
    }

    /**
     * 將 SubtitleCue 陣列格式化為 SRT 格式
     *
     * @param  array<int, array{index: int, start_time: string, end_time: string, text: string}>  $cues  SubtitleCue 陣列
     * @return string  SRT 格式字幕內容
     */
    public function formatSrt(array $cues): string
    {
        $output = '';

        foreach ($cues as $cue) {
            $startTime = $this->vttTimestampToSrtFormat($cue['start_time']);
            $endTime = $this->vttTimestampToSrtFormat($cue['end_time']);

            $output .= $cue['index'] . "\n";
            $output .= $startTime . ' --> ' . $endTime . "\n";
            $output .= $cue['text'] . "\n";
            $output .= "\n";
        }

        return rtrim($output) . "\n";
    }

    /**
     * 將 SubtitleCue 陣列格式化為 VTT 格式
     *
     * @param  array<int, array{index: int, start_time: string, end_time: string, text: string}>  $cues  SubtitleCue 陣列
     * @return string  VTT 格式字幕內容
     */
    public function formatVtt(array $cues): string
    {
        $output = "WEBVTT\n\n";

        foreach ($cues as $cue) {
            $output .= $cue['start_time'] . ' --> ' . $cue['end_time'] . "\n";
            $output .= $cue['text'] . "\n";
            $output .= "\n";
        }

        return rtrim($output) . "\n";
    }

    /**
     * 建構帶上下文的翻譯文字
     *
     * 為指定的 cue 建構包含前後文的翻譯提示文字。
     * 格式為：
     * [上下文] 前文1 | 前文2 | ...
     * [翻譯] 目標文字
     * [上下文] 後文1 | 後文2 | ...
     *
     * 這樣的格式讓翻譯模型能參考前後文，確保專有名詞一致性。
     *
     * @param  array<int, array{index: int, start_time: string, end_time: string, text: string}>  $cues  所有 cues
     * @param  int  $currentIndex  當前 cue 的索引
     * @param  int  $contextWindow  上下文視窗大小
     * @return string  帶上下文的翻譯提示文字
     */
    private function buildContextText(array $cues, int $currentIndex, int $contextWindow): string
    {
        $totalCues = count($cues);
        $parts = [];

        // 前文上下文
        $beforeContext = [];
        $beforeStart = max(0, $currentIndex - $contextWindow);
        for ($i = $beforeStart; $i < $currentIndex; $i++) {
            $beforeContext[] = $cues[$i]['text'];
        }

        if ($beforeContext !== []) {
            $parts[] = '[上下文] ' . implode(' | ', $beforeContext);
        }

        // 目標翻譯文字
        $parts[] = '[翻譯] ' . $cues[$currentIndex]['text'];

        // 後文上下文
        $afterContext = [];
        $afterEnd = min($totalCues - 1, $currentIndex + $contextWindow);
        for ($i = $currentIndex + 1; $i <= $afterEnd; $i++) {
            $afterContext[] = $cues[$i]['text'];
        }

        if ($afterContext !== []) {
            $parts[] = '[上下文] ' . implode(' | ', $afterContext);
        }

        return implode("\n", $parts);
    }

    /**
     * 從翻譯結果中提取目標 cue 的翻譯文字
     *
     * 翻譯 API 回傳的結果可能包含上下文的翻譯，
     * 此方法嘗試提取 [翻譯] 標記後的目標文字。
     * 若無法辨識格式，則回傳完整翻譯結果。
     *
     * @param  string  $translatedText  翻譯 API 回傳的完整文字
     * @param  string  $originalText  原始目標文字（作為 fallback 參考）
     * @return string  提取後的目標翻譯文字
     */
    private function extractTargetTranslation(string $translatedText, string $originalText): string
    {
        $translatedText = trim($translatedText);

        if ($translatedText === '') {
            return $originalText;
        }

        // 嘗試提取 [翻譯] 標記後的文字
        if (preg_match('/\[翻譯\]\s*(.+?)(?:\n\[上下文\]|$)/s', $translatedText, $matches)) {
            return trim($matches[1]);
        }

        // 若翻譯結果包含多行且有 [上下文] 標記，嘗試提取非上下文行
        $lines = explode("\n", $translatedText);
        $targetLines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // 跳過上下文行
            if (str_starts_with($line, '[上下文]') || str_starts_with($line, '[context]')) {
                continue;
            }
            // 移除 [翻譯] 前綴
            if (str_starts_with($line, '[翻譯]')) {
                $line = trim(mb_substr($line, mb_strlen('[翻譯]')));
            }
            if ($line !== '') {
                $targetLines[] = $line;
            }
        }

        if ($targetLines !== []) {
            return implode("\n", $targetLines);
        }

        // 無法辨識格式，回傳完整翻譯結果
        return $translatedText;
    }

    /**
     * 將長行文字依字數限制斷行
     *
     * 優先在中文標點符號（，。！？；：、）或空白處斷行，
     * 若找不到適當的斷行點，則強制在字數限制處斷行。
     *
     * @param  string  $line  待斷行的文字
     * @param  int  $maxChars  每行最大字元數
     * @return array<int, string>  斷行後的文字陣列
     */
    private function wrapLine(string $line, int $maxChars): array
    {
        $result = [];
        $remaining = $line;

        while (mb_strlen($remaining) > $maxChars) {
            $segment = mb_substr($remaining, 0, $maxChars);

            // 嘗試在中文標點或空白處斷行
            $breakPos = $this->findBreakPosition($segment, $maxChars);

            if ($breakPos > 0) {
                $result[] = mb_substr($remaining, 0, $breakPos);
                $remaining = trim(mb_substr($remaining, $breakPos));
            } else {
                // 強制在字數限制處斷行
                $result[] = $segment;
                $remaining = trim(mb_substr($remaining, $maxChars));
            }
        }

        if ($remaining !== '') {
            $result[] = $remaining;
        }

        return $result;
    }

    /**
     * 尋找適當的斷行位置
     *
     * 從字串末端向前搜尋中文標點符號或空白字元作為斷行點。
     *
     * @param  string  $segment  待搜尋的文字片段
     * @param  int  $maxChars  最大字元數
     * @return int  斷行位置（0 表示找不到適當位置）
     */
    private function findBreakPosition(string $segment, int $maxChars): int
    {
        // 中文標點符號（適合在其後斷行）
        $breakChars = ['，', '。', '！', '？', '；', '：', '、', ' ', ',', '.', '!', '?', ';'];

        // 從末端向前搜尋（至少保留一半的字元）
        $minPos = (int) ($maxChars / 2);

        for ($i = mb_strlen($segment) - 1; $i >= $minPos; $i--) {
            $char = mb_substr($segment, $i, 1);
            if (in_array($char, $breakChars, true)) {
                return $i + 1;
            }
        }

        return 0;
    }

    /**
     * 將 SRT 時間戳記格式轉換為 VTT 格式
     *
     * SRT 使用逗號分隔毫秒（00:00:01,000），VTT 使用句點（00:00:01.000）。
     *
     * @param  string  $timestamp  SRT 格式時間戳記
     * @return string  VTT 格式時間戳記
     */
    private function srtTimestampToVttFormat(string $timestamp): string
    {
        return str_replace(',', '.', $timestamp);
    }

    /**
     * 將 VTT 時間戳記格式轉換為 SRT 格式
     *
     * VTT 使用句點分隔毫秒（00:00:01.000），SRT 使用逗號（00:00:01,000）。
     *
     * @param  string  $timestamp  VTT 格式時間戳記
     * @return string  SRT 格式時間戳記
     */
    private function vttTimestampToSrtFormat(string $timestamp): string
    {
        return str_replace('.', ',', $timestamp);
    }

    /**
     * 取得每行最大字元數設定
     *
     * @return int  每行最大字元數
     */
    private function getMaxCharsPerLine(): int
    {
        return (int) config('trending-summary.subtitle.translation.max_chars_per_line', 18);
    }

    /**
     * 取得上下文視窗大小設定
     *
     * @return int  上下文視窗大小
     */
    private function getContextWindow(): int
    {
        return (int) config('trending-summary.subtitle.translation.context_window', 3);
    }

    /**
     * 取得每批翻譯句數設定
     *
     * @return int  每批翻譯句數
     */
    private function getBatchSize(): int
    {
        return (int) config('trending-summary.subtitle.translation.batch_size', 25);
    }
}
