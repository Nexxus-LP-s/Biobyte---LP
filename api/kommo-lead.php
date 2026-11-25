<?php
/**
 * Endpoint seguro: recebe o formulário da LP SACIH e cria o lead no Kommo (API v4).
 *
 * - O token do Kommo fica só no servidor (kommo-config.php), nunca no navegador.
 * - Cria lead + contato (nome, telefone, e-mail, cargo) + empresa (hospital) e grava UTMs.
 * - Adiciona uma nota no lead com todos os dados enviados (inclusive UTMs, página e referência).
 * - Se o Kommo recusar algum campo, tenta de novo com o mínimo e mantém tudo na nota.
 * - Se o Kommo falhar, guarda o lead em /api/_dados (pasta bloqueada) para não perder nada.
 *
 * Diagnóstico do servidor: abra /api/kommo-lead.php?diagnostico no navegador.
 */

define('KOMMO_LEAD_ENDPOINT', true);

header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const DATA_DIR = __DIR__ . '/_dados';
const RATE_LIMIT_MAX = 5;          // envios por IP...
const RATE_LIMIT_WINDOW = 600;     // ...a cada 10 minutos

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load_config(): ?array
{
    $file = __DIR__ . '/kommo-config.php';
    if (!is_file($file)) {
        return null;
    }
    $cfg = require $file;
    if (!is_array($cfg)) {
        return null;
    }
    $ok = !empty($cfg['subdomain']) && !empty($cfg['access_token'])
        && (int)($cfg['pipeline_id'] ?? 0) > 0 && (int)($cfg['status_id'] ?? 0) > 0;
    return $ok ? $cfg : null;
}

function api_base(array $cfg): string
{
    if (!empty($cfg['api_base'])) { // usado apenas em testes
        return rtrim($cfg['api_base'], '/');
    }
    $sub = preg_replace('/[^a-z0-9\-]/i', '', $cfg['subdomain']);
    return 'https://' . $sub . '.kommo.com';
}

/** Chamada HTTP à API do Kommo. Retorna [status, corpo decodificado, corpo bruto]. */
function kommo_request(array $cfg, string $method, string $path, ?array $payload = null): array
{
    $url = api_base($cfg) . $path;
    $headers = [
        'Authorization: Bearer ' . $cfg['access_token'],
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Biobyte-LP/1.0',
    ];
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = $raw === false ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $raw = 'curl: ' . curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s?/', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        if ($raw === false) {
            $raw = 'stream: falha de conexão';
        }
    }
    $decoded = json_decode((string)$raw, true);
    return [$status, is_array($decoded) ? $decoded : null, (string)$raw];
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ensure_data_dir(): bool
{
    return is_dir(DATA_DIR) || @mkdir(DATA_DIR, 0750, true);
}

function rate_limited(): bool
{
    if (!ensure_data_dir()) {
        return false; // sem pasta gravável, não bloqueia o lead
    }
    $file = DATA_DIR . '/rl_' . hash('sha256', client_ip()) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string)@file_get_contents($file), true) ?: [];
    }
    $hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t > $now - RATE_LIMIT_WINDOW));
    if (count($hits) >= RATE_LIMIT_MAX) {
        return true;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return false;
}

function clean(?string $v, int $max = 200): string
{
    $v = (string)$v;
    $v = strip_tags($v);
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
    $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
    return mb_substr($v, 0, $max, 'UTF-8');
}

function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) === 10 || strlen($digits) === 11) {
        return '+55' . $digits;
    }
    if (substr($digits, 0, 2) === '55' && (strlen($digits) === 12 || strlen($digits) === 13)) {
        return '+' . $digits;
    }
    return $phone;
}

function origin_allowed(array $cfg): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return true; // navegadores enviam Origin em POST via fetch; sem ele, segue as demais proteções
    }
    $allowed = array_map(fn($o) => rtrim(strtolower($o), '/'), $cfg['allowed_origins'] ?? []);
    if ($allowed) {
        return in_array(rtrim(strtolower($origin), '/'), $allowed, true);
    }
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $serverHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    return $originHost !== '' && $originHost === $serverHost;
}

function save_failed_lead(array $lead, string $reason, ?array $cfg): void
{
    if (ensure_data_dir()) {
        $line = json_encode(['data' => date('c'), 'motivo' => $reason, 'lead' => $lead], JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents(DATA_DIR . '/leads-nao-enviados-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
    $to = $cfg['fallback_email'] ?? '';
    if ($to && filter_var($to, FILTER_VALIDATE_EMAIL) && function_exists('mail')) {
        $txt = "O Kommo não recebeu um lead da LP SACIH. Dados:\n\n" . lead_note($lead) . "\n\nMotivo técnico: " . $reason;
        @mail($to, '=?UTF-8?B?' . base64_encode('[LP SACIH] Lead não enviado ao Kommo') . '?=', $txt,
            "Content-Type: text/plain; charset=UTF-8\r\n");
    }
}

function lead_note(array $l): string
{
    $rows = [
        'Origem: Landing Page SACIH',
        'Nome: ' . $l['nome'],
        'Hospital/Instituição: ' . $l['hospital'],
        'E-mail: ' . $l['email'],
        'Telefone/WhatsApp: ' . $l['telefone'],
        'Cargo/área: ' . ($l['cargo'] !== '' ? $l['cargo'] : '-'),
        '',
    ];
    foreach (UTM_KEYS as $k) {
        $rows[] = strtoupper($k) . ': ' . ($l[$k] !== '' ? $l[$k] : '-');
    }
    foreach (['gclid', 'fbclid'] as $k) {
        if ($l[$k] !== '') {
            $rows[] = strtoupper($k) . ': ' . $l[$k];
        }
    }
    $rows[] = 'Página: ' . ($l['pagina'] !== '' ? $l['pagina'] : '-');
    $rows[] = 'Referência: ' . ($l['referrer'] !== '' ? $l['referrer'] : '-');
    $rows[] = 'Enviado em: ' . date('d/m/Y H:i');
    return implode("\n", $rows);
}

const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

date_default_timezone_set('America/Sao_Paulo');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---------- Diagnóstico (GET ?diagnostico) ----------
if ($method === 'GET' && isset($_GET['diagnostico'])) {
    $cfg = load_config();
    $out = [
        'php_versao' => PHP_VERSION,
        'php_ok' => version_compare(PHP_VERSION, '7.4', '>='),
        'curl' => function_exists('curl_init'),
        'pasta_dados_gravavel' => ensure_data_dir() && is_writable(DATA_DIR),
        'configurado' => $cfg !== null,
    ];
    if ($cfg !== null && !rate_limited()) {
        [$st] = kommo_request($cfg, 'GET', '/api/v4/account');
        $out['kommo_conexao'] = $st === 200 ? 'ok' : ('falhou (HTTP ' . $st . ')');
    }
    respond(200, $out);
}

// ---------- CORS (a página fica em outro domínio, ex.: GitHub Pages) ----------
function send_cors(?array $cfg): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $cfg !== null && origin_allowed($cfg)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Content-Type');
        header('Access-Control-Max-Age: 600');
    }
}

if ($method === 'OPTIONS') {
    send_cors(load_config());
    http_response_code(204);
    exit;
}

if ($method !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'erro' => 'metodo_nao_permitido']);
}

// ---------- Recebimento ----------
$cfg = load_config();
send_cors($cfg);

if ($cfg !== null && !origin_allowed($cfg)) {
    respond(403, ['ok' => false, 'erro' => 'origem_nao_permitida']);
}

// Os dados chegam codificados (base64 de um JSON) no campo "d", para não serem barrados
// pelo firewall do servidor (ModSecurity) quando contêm URLs, apóstrofos etc.
// Envio comum (campos soltos) continua aceito.
$in = $_POST;
if (isset($_POST['d']) && is_string($_POST['d'])) {
    $decoded = json_decode((string)base64_decode($_POST['d'], true), true);
    if (!is_array($decoded)) {
        respond(422, ['ok' => false, 'erro' => 'dados_invalidos', 'campos' => ['payload']]);
    }
    $in = array_map(fn($v) => is_scalar($v) ? (string)$v : '', $decoded);
}

// Honeypot: bot preencheu o campo invisível → finge sucesso e descarta.
if (trim((string)($in['empresa_site'] ?? '')) !== '') {
    respond(200, ['ok' => true]);
}

$lead = [
    'nome'     => clean($in['nome'] ?? '', 120),
    'hospital' => clean($in['hospital'] ?? '', 160),
    'email'    => strtolower(clean($in['email'] ?? '', 160)),
    'telefone' => clean($in['telefone'] ?? '', 40),
    'cargo'    => clean($in['cargo'] ?? '', 160),
    'gclid'    => clean($in['gclid'] ?? '', 300),
    'fbclid'   => clean($in['fbclid'] ?? '', 300),
    'pagina'   => clean($in['pagina'] ?? '', 500),
    'referrer' => clean($in['referrer'] ?? '', 500),
];
foreach (UTM_KEYS as $k) {
    $lead[$k] = clean($in[$k] ?? '', 300);
}

$errors = [];
foreach (['nome', 'hospital', 'email', 'telefone'] as $req) {
    if ($lead[$req] === '') {
        $errors[] = $req;
    }
}
if ($lead['email'] !== '' && !filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}
if ($lead['telefone'] !== '' && strlen(preg_replace('/\D+/', '', $lead['telefone'])) < 8) {
    $errors[] = 'telefone';
}
if ($errors) {
    respond(422, ['ok' => false, 'erro' => 'dados_invalidos', 'campos' => array_values(array_unique($errors))]);
}

if (rate_limited()) {
    respond(429, ['ok' => false, 'erro' => 'muitas_tentativas']);
}

if ($cfg === null) {
    save_failed_lead($lead, 'integracao_nao_configurada', null);
    respond(503, ['ok' => false, 'erro' => 'integracao_nao_configurada']);
}

// ---------- Montagem do lead para o Kommo ----------
$phone = normalize_phone($lead['telefone']);

$buildPayload = function (bool $full) use ($cfg, $lead, $phone): array {
    $contactFields = [
        ['field_code' => 'PHONE', 'values' => [['value' => $phone, 'enum_code' => 'WORK']]],
        ['field_code' => 'EMAIL', 'values' => [['value' => $lead['email'], 'enum_code' => 'WORK']]],
    ];
    if ($full && $lead['cargo'] !== '') {
        $contactFields[] = ['field_code' => 'POSITION', 'values' => [['value' => $lead['cargo']]]];
    }

    $contact = ['name' => $lead['nome'], 'custom_fields_values' => $contactFields];
    $item = [
        'name' => 'LP SACIH — ' . $lead['hospital'],
        'pipeline_id' => (int)$cfg['pipeline_id'],
        'status_id' => (int)$cfg['status_id'],
        '_embedded' => [
            'contacts' => [$contact],
            'companies' => [['name' => $lead['hospital']]],
        ],
    ];

    $resp = (int)($cfg['responsible_user_id'] ?? 0);
    if ($resp > 0) {
        $item['responsible_user_id'] = $resp;
        $item['_embedded']['contacts'][0]['responsible_user_id'] = $resp;
    }

    $tags = array_values(array_filter(array_map('strval', $cfg['tags'] ?? [])));
    if ($tags) {
        $item['_embedded']['tags'] = array_map(fn($t) => ['name' => $t], $tags);
    }

    if ($full) {
        $utmFields = [];
        foreach (UTM_KEYS as $k) {
            if ($lead[$k] !== '') {
                $utmFields[] = ['field_code' => strtoupper($k), 'values' => [['value' => $lead[$k]]]];
            }
        }
        if ($utmFields) {
            $item['custom_fields_values'] = $utmFields;
        }
    }
    return [$item];
};

// 1ª tentativa: completa (UTMs nos campos de rastreamento + cargo). 2ª: mínima, se o Kommo recusar campos.
[$status, $data, $raw] = kommo_request($cfg, 'POST', '/api/v4/leads/complex', $buildPayload(true));
$attempt = 'completa';
if ($status === 400) {
    [$status, $data, $raw] = kommo_request($cfg, 'POST', '/api/v4/leads/complex', $buildPayload(false));
    $attempt = 'minima';
}

$leadId = (is_array($data) && isset($data[0]['id'])) ? (int)$data[0]['id'] : 0;

if ($status < 200 || $status >= 300 || $leadId <= 0) {
    save_failed_lead($lead, 'kommo_http_' . $status . ': ' . mb_substr($raw, 0, 500, 'UTF-8'), $cfg);
    respond(502, ['ok' => false, 'erro' => 'falha_crm']);
}

// Nota com todos os dados (não bloqueia o sucesso se falhar).
kommo_request($cfg, 'POST', '/api/v4/leads/notes', [[
    'entity_id' => $leadId,
    'note_type' => 'common',
    'params' => ['text' => lead_note($lead)],
]]);

respond(200, ['ok' => true, 'lead_id' => $leadId, 'modo' => $attempt]);
