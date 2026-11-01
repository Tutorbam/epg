<?php
// Genera epg.xml (solo canali IT) leggendo i dati da variabili d'ambiente.
// Pensato per GitHub Actions: nessun dato sensibile nel codice.

set_time_limit(0);

$portal_url  = getenv('PORTAL_URL');
$mac_address = getenv('MAC_ADDRESS');
$epg_period_hours   = (int)(getenv('EPG_PERIOD') ?: 48);
$epg_short_fallback = true;
$epg_short_size     = 20;

if (empty($portal_url) || empty($mac_address)) {
    fwrite(STDERR, "Mancano i secrets PORTAL_URL e/o MAC_ADDRESS.\n");
    exit(1);
}

$base_url   = rtrim($portal_url, '/') . '/portal.php';
$portal_host = parse_url($portal_url, PHP_URL_HOST);
$user_agent = 'Mozilla/5.0 (QtEmbedded; U; Linux; C) AppleWebKit/533.3 (KHTML, like Gecko) MAG200 stbapp ver: 2 rev: 250 Safari/533.3';

function mask($s)
{
    global $portal_host, $mac_address;
    return str_ireplace(array($portal_host, $mac_address, urlencode($mac_address)), '***', (string)$s);
}

function stalker_call($base_url, $query, $token, $mac, $ua)
{
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    }

    if (empty($token)) {
        $headers = array("Cookie: mac=" . urlencode($mac), "User-Agent: " . $ua);
    } else {
        $headers = array(
            "Authorization: Bearer " . $token,
            "Cookie: mac=" . urlencode($mac) . "; stb_lang=en; timezone=Europe/Rome",
            "User-Agent: " . $ua
        );
    }

    curl_setopt($ch, CURLOPT_URL, $base_url . '?' . $query . '&JsHttpRequest=1-xml');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);

    $GLOBALS['last_call'] = array(
        'action' => $query,
        'http'   => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'error'  => curl_error($ch),
        'size'   => is_string($response) ? strlen($response) : 0
    );

    return json_decode($response, true);
}

function xml_esc($s)
{
    return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// 1. Autenticazione
$data  = stalker_call($base_url, "type=stb&action=handshake", '', $mac_address, $user_agent);
$token = isset($data['js']['token']) ? $data['js']['token'] : '';
if (empty($token)) {
    $d = $GLOBALS['last_call'];
    fwrite(STDERR, "Autenticazione fallita. HTTP {$d['http']} - " . mask($d['error']) . "\n");
    fwrite(STDERR, "Possibile blocco degli IP di GitHub da parte del portale.\n");
    exit(1);
}

// 2. Generi
$genres_data = stalker_call($base_url, "type=itv&action=get_genres", $token, $mac_address, $user_agent);
$genres_map = [];
if (isset($genres_data['js']) && is_array($genres_data['js'])) {
    foreach ($genres_data['js'] as $genre) {
        if (isset($genre['id']) && isset($genre['title'])) {
            $genres_map[$genre['id']] = $genre['title'];
        }
    }
}

// 3. Canali
$channel_data = stalker_call($base_url, "type=itv&action=get_all_channels", $token, $mac_address, $user_agent);
$channels = isset($channel_data['js']['data']) ? $channel_data['js']['data'] : [];
if (empty($channels)) {
    $d = $GLOBALS['last_call'];
    fwrite(STDERR, "Nessun canale ricevuto. HTTP {$d['http']}, {$d['size']} byte. " . mask($d['error']) . "\n");
    exit(1);
}

// 4. Filtro IT (identico allo script locale, così gli ID coincidono con la M3U)
$selected = [];
foreach ($channels as $channel) {
    $name = isset($channel['name']) ? $channel['name'] : 'Canale Sconosciuto';
    $id   = isset($channel['id']) ? $channel['id'] : '';
    if (empty($id)) continue;

    $group = 'Generale';
    if (!empty($channel['category_name'])) {
        $group = $channel['category_name'];
    } elseif (!empty($channel['tv_genre_name'])) {
        $group = $channel['tv_genre_name'];
    } elseif (!empty($channel['tv_genre_id']) && isset($genres_map[$channel['tv_genre_id']])) {
        $group = $genres_map[$channel['tv_genre_id']];
    }

    if (stripos($name, 'IT:') !== false ||
        stripos($name, '|IT|') !== false ||
        stripos($name, '┃IT┃') !== false ||
        stripos($group, 'ITALIA') !== false ||
        stripos($group, 'ITALY') !== false ||
        stripos($group, ' ITA ') !== false) {
        $selected[$id] = $name;
    }
}

if (empty($selected)) {
    fwrite(STDERR, "Nessun canale italiano trovato: non genero l'EPG.\n");
    exit(1);
}
echo "Canali IT: " . count($selected) . "\n";

// 5. EPG
$normalize = function ($p) {
    if (!is_array($p) || empty($p['name'])) return null;
    $start = !empty($p['start_timestamp']) ? (int)$p['start_timestamp'] : (!empty($p['time']) ? strtotime($p['time']) : 0);
    $stop  = !empty($p['stop_timestamp'])  ? (int)$p['stop_timestamp']  : (!empty($p['time_to']) ? strtotime($p['time_to']) : 0);
    if ($start <= 0) return null;
    if ($stop <= $start) {
        $stop = !empty($p['duration']) ? $start + (int)$p['duration'] : $start + 3600;
    }
    return array(
        'start' => $start,
        'stop'  => $stop,
        'title' => $p['name'],
        'desc'  => isset($p['descr']) ? $p['descr'] : '',
        'cat'   => isset($p['category']) ? $p['category'] : ''
    );
};

$programmes = [];

$epg_resp = stalker_call($base_url, "type=itv&action=get_epg_info&period=" . $epg_period_hours, $token, $mac_address, $user_agent);
$epg_raw = [];
if (isset($epg_resp['js']['data']) && is_array($epg_resp['js']['data'])) {
    $epg_raw = $epg_resp['js']['data'];
} elseif (isset($epg_resp['js']) && is_array($epg_resp['js'])) {
    $epg_raw = $epg_resp['js'];
}

foreach ($epg_raw as $ch_id => $list) {
    if (!isset($selected[$ch_id]) || !is_array($list)) continue;
    foreach ($list as $p) {
        $n = $normalize($p);
        if ($n) $programmes[$ch_id][] = $n;
    }
}
echo "get_epg_info: " . count($programmes) . " canali con dati\n";

if ($epg_short_fallback) {
    $extra = 0;
    foreach ($selected as $ch_id => $name) {
        if (!empty($programmes[$ch_id])) continue;
        $r = stalker_call($base_url, "type=itv&action=get_short_epg&ch_id=" . urlencode($ch_id) . "&size=" . $epg_short_size, $token, $mac_address, $user_agent);
        $list = isset($r['js']) && is_array($r['js']) ? $r['js'] : [];
        if (isset($list['data']) && is_array($list['data'])) $list = $list['data'];
        foreach ($list as $p) {
            $n = $normalize($p);
            if ($n) $programmes[$ch_id][] = $n;
        }
        if (!empty($programmes[$ch_id])) $extra++;
    }
    echo "get_short_epg: $extra canali aggiuntivi\n";
}

// 6. XMLTV (senza loghi: contengono l'indirizzo del portale)
$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
$xml .= '<tv generator-info-name="stalker-epg-php">' . "\n";

foreach ($selected as $ch_id => $name) {
    $xml .= '  <channel id="' . xml_esc($ch_id) . '">' . "\n";
    $xml .= '    <display-name>' . xml_esc($name) . '</display-name>' . "\n";
    $xml .= '  </channel>' . "\n";
}

$total = 0;
foreach ($programmes as $ch_id => $list) {
    usort($list, function ($a, $b) { return $a['start'] <=> $b['start']; });
    foreach ($list as $p) {
        $xml .= '  <programme start="' . gmdate('YmdHis', $p['start']) . ' +0000" stop="' . gmdate('YmdHis', $p['stop']) . ' +0000" channel="' . xml_esc($ch_id) . '">' . "\n";
        $xml .= '    <title>' . xml_esc($p['title']) . '</title>' . "\n";
        if ($p['desc'] !== '') $xml .= '    <desc>' . xml_esc($p['desc']) . '</desc>' . "\n";
        if ($p['cat']  !== '') $xml .= '    <category>' . xml_esc($p['cat']) . '</category>' . "\n";
        $xml .= '  </programme>' . "\n";
        $total++;
    }
}
$xml .= '</tv>' . "\n";

if ($total === 0) {
    fwrite(STDERR, "Nessun programma ricevuto: non sovrascrivo epg.xml.\n");
    exit(1);
}

file_put_contents(__DIR__ . '/epg.xml', $xml);
echo "epg.xml creato: $total programmi su " . count($programmes) . " canali\n";
