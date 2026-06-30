<?php

namespace App\Services\Narration;

use Anthropic\Client;
use Anthropic\Messages\TextBlock;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The shadow narrator (AD-17): asks Haiku to phrase the **same computed deltas**
 * (never raw weather), so its line is grounded by construction. The vendor SDK
 * appears only here. Time-boxed, no retries; on a missing key, no deltas, a
 * timeout, or any error it returns null — never failing or delaying the send.
 * Its output is logged for comparison, not put in the email (deterministic ships).
 */
class ClaudeNarrator implements Narrator
{
    private const SYSTEM = <<<'PROMPT'
        You write one short, calm sentence for a travel weather email about a notable
        change since yesterday's forecast. Never alarmist, never hyped — a quiet,
        reassuring concierge voice. Use ONLY the figures provided; never invent or
        infer numbers, conditions, or days. Output exactly one sentence, no preamble.
        PROMPT;

    public function __construct(private NarrationDiffer $differ) {}

    public function narrate(NarrationContext $context): ?string
    {
        $deltas = $this->differ->diff($context);

        if ($deltas === []) {
            return null;
        }

        $apiKey = config('tripcast.narration.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            return null;
        }

        $timeout = (float) config('tripcast.narration.timeout');

        try {
            $client = new Client(apiKey: $apiKey, requestOptions: ['timeout' => $timeout, 'maxRetries' => 0]);

            $message = $client->messages->create(
                maxTokens: 120,
                messages: [['role' => 'user', 'content' => $this->prompt($deltas, $context->celsius)]],
                model: (string) config('tripcast.narration.model'),
                system: self::SYSTEM,
                requestOptions: ['timeout' => $timeout, 'maxRetries' => 0],
            );

            $text = $this->textOf($message->content);

            return $text === '' ? null : $text;
        } catch (Throwable $e) {
            Log::warning('narration:claude failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * A grounded prompt listing only the computed deltas — the model phrases
     * these, it does not source new figures.
     *
     * @param  list<NarrationDelta>  $deltas
     */
    private function prompt(array $deltas, bool $celsius): string
    {
        $unit = $celsius ? '°C' : '°F';
        $lines = [];

        foreach ($deltas as $delta) {
            $lines[] = $delta->metric === NarrationDelta::METRIC_RAIN
                ? "- {$delta->dayLabel}: rain chance {$delta->from}% -> {$delta->to}%"
                : "- {$delta->dayLabel}: high {$delta->from}{$unit} -> {$delta->to}{$unit}";
        }

        return "Notable changes since yesterday:\n".implode("\n", $lines)
            ."\n\nWrite one calm sentence about the most notable change.";
    }

    /**
     * Concatenate the text of every text block in the response.
     *
     * @param  list<mixed>  $content
     */
    private function textOf(array $content): string
    {
        $text = '';

        foreach ($content as $block) {
            if ($block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return trim($text);
    }
}
