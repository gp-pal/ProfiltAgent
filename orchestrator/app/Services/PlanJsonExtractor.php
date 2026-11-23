<?php

namespace App\Services;

/**
 * Extracts a DSL plan JSON object from a raw LLM response, tolerating the common
 * ways a model wraps or truncates it: fenced code blocks, and objects cut off
 * mid-action by a token limit. Pure string handling — no state, no I/O.
 */
class PlanJsonExtractor
{
    public static function extract(string $response): string
    {
        $response = trim($response);

        // Try to find a complete, valid JSON object.
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $json = $matches[0];
            json_decode($json);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        // If the JSON appears truncated, try to complete it.
        if (preg_match('/^\{.*$/s', $response)) {
            $completed = self::attemptCompletion($response);
            if ($completed && $completed !== $response) {
                return $completed;
            }
        }

        // Try to find it within a fenced code block.
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches)) {
            return $matches[1];
        }

        return $response;
    }

    private static function attemptCompletion(string $partialJson): string
    {
        // Count braces/brackets to see what still needs closing.
        $openBraces = substr_count($partialJson, '{') - substr_count($partialJson, '}');
        $openBrackets = substr_count($partialJson, '[') - substr_count($partialJson, ']');

        if ($openBraces > 0 || $openBrackets > 0) {
            $completed = $partialJson;

            // Ends with an incomplete action like {"do": " or {"do": "ext
            if (preg_match('/\{\s*"do"\s*:\s*"[^"]*$/', $completed)) {
                if (preg_match('/\{\s*"do"\s*:\s*"ext$/', $completed)) {
                    $completed = preg_replace('/\{\s*"do"\s*:\s*"ext$/', '{"do": "extract_links", "save_as": "links_raw", "limit": 200}', $completed);
                } elseif (preg_match('/\{\s*"do"\s*:\s*"scr$/', $completed)) {
                    $completed = preg_replace('/\{\s*"do"\s*:\s*"scr$/', '{"do": "screenshot_fullpage", "path": "runs/screenshots/home_full.png"}', $completed);
                } elseif (preg_match('/\{\s*"do"\s*:\s*"ret$/', $completed)) {
                    $completed = preg_replace('/\{\s*"do"\s*:\s*"ret$/', '{"do": "return", "data": ["main_html", "iframes_html", "links_raw"]}', $completed);
                } else {
                    $completed .= '"}';
                }
            }
            // Ends with an incomplete object like {"do": "goto"
            elseif (preg_match('/\{\s*"do"\s*:\s*"[^"]*"[^}]*$/', $completed)) {
                $completed .= '}';
            }

            for ($i = 0; $i < $openBrackets; $i++) {
                $completed .= ']';
            }
            for ($i = 0; $i < $openBraces; $i++) {
                $completed .= '}';
            }

            json_decode($completed);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $completed;
            }
        }

        return $partialJson;
    }
}
