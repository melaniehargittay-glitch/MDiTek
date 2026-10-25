<?php

require_once("../globals.php");
header('Content-Type: application/json');

/**
 * MDItek SOAP AI endpoint — uses Anthropic Claude API
 *
 * Configure API key via ONE of:
 *   1) Environment variable:        ANTHROPIC_API_KEY=sk-ant-...
 *   2) Local config file (not committed to git):
 *        interface/custom/ai_soap_config.php
 *        containing: $AI_SOAP_ANTHROPIC_API_KEY = 'sk-ant-...';
 *   3) OpenEMR global:              $GLOBALS['anthropic_api_key']
 */

function json_out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function read_raw_input(): array
{
    $raw = file_get_contents("php://input");
    if ($raw === false || trim($raw) === '') {
        json_out(["error" => "Empty request body"], 400);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_out(["error" => "Invalid JSON body"], 400);
    }
    return $decoded;
}

function get_api_key(): string
{
    // Hardcoded key — works immediately with no extra config files
    $hardcoded = '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';
    if ($hardcoded !== '') return $hardcoded;

    // Optional: environment variable override
    $envKey = getenv('ANTHROPIC_API_KEY');
    if (is_string($envKey) && trim($envKey) !== '') {
        return trim($envKey);
    }

    // Optional: local config file (interface/custom/ai_soap_config.php)
    $configPath = __DIR__ . '/ai_soap_config.php';
    if (file_exists($configPath)) {
        include $configPath;
        if (isset($AI_SOAP_ANTHROPIC_API_KEY) && trim($AI_SOAP_ANTHROPIC_API_KEY) !== '') {
            return trim($AI_SOAP_ANTHROPIC_API_KEY);
        }
    }

    json_out(["error" => "AI not configured."], 500);
}

function get_specialty_context(string $specialty): string
{
    $map = [
        "chiro"       => "You are a chiropractor writing a clinical SOAP note.",
        "primarycare" => "You are a primary care physician writing a SOAP note.",
        "internalmed" => "You are an internal medicine physician writing a SOAP note.",
        "familymed"   => "You are a family medicine physician writing a SOAP note.",
        "urgentcare"  => "You are an urgent care clinician writing a SOAP note.",
        "pain"        => "You are a pain management clinician writing a SOAP note.",
        "ortho"       => "You are an orthopedic clinician writing a SOAP note.",
        "neurology"   => "You are a neurologist writing a SOAP note.",
        "pmr"         => "You are a PM&R clinician writing a SOAP note.",
        "psychiatry"  => "You are a psychiatrist writing a SOAP note.",
        "psychology"  => "You are a psychologist writing a SOAP note.",
        "other"       => "You are a medical clinician writing a SOAP note.",
    ];
    return $map[$specialty] ?? $map["other"];
}

function build_prompt(string $specialty, string $transcript, array $current, array $structured): string
{
    $context = get_specialty_context($specialty);

    return $context . "\n\n" .
        "Generate a professional SOAP note. Return ONLY valid JSON — no markdown, no explanation.\n\n" .
        "Requirements:\n" .
        "- Concise, clinically appropriate language\n" .
        "- Insurance-safe and medico-legally defensible phrasing\n" .
        "- Preserve facts from transcript and structured inputs\n" .
        "- Do not invent diagnoses or findings not supported by the data\n\n" .
        "Return exactly:\n" .
        "{\"subjective\":\"...\",\"objective\":\"...\",\"assessment\":\"...\",\"plan\":\"...\"}\n\n" .
        "Transcript:\n" . ($transcript ?: "(none)") . "\n\n" .
        "Structured inputs:\n" . json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n" .
        "Current notes:\n" . json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function call_claude(string $apiKey, string $prompt): string
{
    $payload = [
        "model"      => "claude-haiku-4-5-20251001",
        "max_tokens" => 1024,
        "messages"   => [
            ["role" => "user", "content" => $prompt]
        ]
    ];

    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "x-api-key: " . $apiKey,
            "anthropic-version: 2023-06-01"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        json_out(["error" => "AI request failed", "details" => $curlErr], 502);
    }
    if ($status < 200 || $status >= 300) {
        $decoded = json_decode($response, true);
        $msg = $decoded['error']['message'] ?? $response;
        json_out(["error" => "AI provider error ($status): $msg"], 502);
    }

    $decoded = json_decode($response, true);
    $content = $decoded['content'][0]['text'] ?? null;

    if (!is_string($content) || trim($content) === '') {
        json_out(["error" => "AI response was empty or malformed"], 502);
    }

    return trim($content);
}

function parse_ai_json(string $text): array
{
    // Try direct parse
    $parsed = json_decode($text, true);
    if (is_array($parsed)) {
        return [
            "subjective" => (string)($parsed["subjective"] ?? ""),
            "objective"  => (string)($parsed["objective"]  ?? ""),
            "assessment" => (string)($parsed["assessment"] ?? ""),
            "plan"       => (string)($parsed["plan"]       ?? "")
        ];
    }

    // Try extracting JSON from markdown/text
    if (preg_match('/\{[\s\S]*\}/s', $text, $m)) {
        $parsed = json_decode($m[0], true);
        if (is_array($parsed)) {
            return [
                "subjective" => (string)($parsed["subjective"] ?? ""),
                "objective"  => (string)($parsed["objective"]  ?? ""),
                "assessment" => (string)($parsed["assessment"] ?? ""),
                "plan"       => (string)($parsed["plan"]       ?? "")
            ];
        }
    }

    // Fallback: put raw text in subjective
    return ["subjective" => $text, "objective" => "", "assessment" => "", "plan" => ""];
}

// ── MAIN ──────────────────────────────────────────────────
$input      = read_raw_input();
$specialty  = (string)($input['specialty']  ?? 'other');
$transcript = trim((string)($input['transcript'] ?? ''));
$current    = is_array($input['current']    ?? null) ? $input['current']    : [];
$structured = is_array($input['structured'] ?? null) ? $input['structured'] : [];

if ($transcript === '' && empty(array_filter($structured))) {
    json_out(["error" => "No transcript or structured data provided"], 400);
}

$apiKey = get_api_key();
$prompt = build_prompt($specialty, $transcript, $current, $structured);
$aiText = call_claude($apiKey, $prompt);
$result = parse_ai_json($aiText);

json_out($result, 200);
