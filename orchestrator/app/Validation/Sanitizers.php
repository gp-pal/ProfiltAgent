<?php

namespace App\Validation;

class Sanitizers
{
    public function sanitizeText(string $text, bool $preserveNewlines = false): string
    {
        if (empty($text)) {
            return '';
        }

        // Remove HTML tags but preserve content
        $text = strip_tags($text);

        // Remove script and style content that might have been missed
        $text = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $text);
        $text = preg_replace('/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi', '', $text);
        $text = preg_replace('/<noscript\b[^<]*(?:(?!<\/noscript>)<[^<]*)*<\/noscript>/mi', '', $text);

        // Remove potential prompt injection patterns
        $text = $this->neutralizePromptInjection($text);

        // Normalize whitespace
        $text = $preserveNewlines
            ? preg_replace(['/[^\S\n]+/', '/\n{3,}/'], [' ', "\n\n"], $text) ?? $text
            : preg_replace('/\s+/', ' ', $text) ?? $text;

        // Remove trailing spaces before newlines when preserving newlines
        if ($preserveNewlines) {
            $text = preg_replace('/ +\n/', "\n", $text) ?? $text;
        }

        $text = trim($text);

        // Limit length to prevent excessive token usage
        if (strlen($text) > 50000) {
            $text = substr($text, 0, 50000) . '... [truncated]';
        }

        return $text;
    }

    public function sanitizeHtml(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // Remove dangerous elements
        $dangerousTags = [
            'script', 'style', 'noscript', 'iframe', 'frame', 'frameset',
            'object', 'embed', 'applet', 'form', 'input', 'button',
            'textarea', 'select', 'option'
        ];

        foreach ($dangerousTags as $tag) {
            $html = preg_replace("/<{$tag}\b[^>]*>.*?<\/{$tag}>/is", '', $html);
            $html = preg_replace("/<{$tag}\b[^>]*\/>/is", '', $html);
        }

        // Remove dangerous attributes
        $html = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/', '', $html);
        $html = preg_replace('/\s*javascript\s*:\s*[^"\'>\s]+/', '', $html);

        return $html;
    }

    private function neutralizePromptInjection(string $text): string
    {
        // Common prompt injection patterns
        $patterns = [
            '/ignore\s+previous\s+instructions/i' => '[REDACTED INSTRUCTION]',
            '/forget\s+everything/i' => '[REDACTED INSTRUCTION]',
            '/system\s*:\s*you\s+are/i' => '[REDACTED SYSTEM MESSAGE]',
            '/assistant\s*:\s*i\s+will/i' => '[REDACTED ASSISTANT MESSAGE]',
            '/\[system\]/i' => '[REDACTED]',
            '/\[\/system\]/i' => '[REDACTED]',
            '/\[assistant\]/i' => '[REDACTED]',
            '/\[\/assistant\]/i' => '[REDACTED]',
            '/\[user\]/i' => '[REDACTED]',
            '/\[\/user\]/i' => '[REDACTED]'
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    public function sanitizeUrl(string $url): string
    {
        // Basic URL validation and sanitization
        $url = trim($url);

        // Must be HTTP or HTTPS
        if (!preg_match('/^https?:\/\//', $url)) {
            throw new \InvalidArgumentException("Invalid URL scheme: {$url}");
        }

        // Remove potential XSS in URL
        $url = filter_var($url, FILTER_SANITIZE_URL);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException("Invalid URL format: {$url}");
        }

        return $url;
    }

    public function sanitizeFilePath(string $path): string
    {
        // Remove directory traversal attempts
        $path = str_replace(['../', '..\\', '../', '..\\'], '', $path);

        // Remove null bytes
        $path = str_replace("\0", '', $path);

        // Normalize path separators
        $path = str_replace('\\', '/', $path);

        // Remove leading slashes (no absolute paths)
        $path = ltrim($path, '/');

        return $path;
    }
}