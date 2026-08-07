<?php
// create_tournament.php

include_once("../../_intro.php");
include_once("../../classes/tournament.php");
include_once("../../classes/game.php");

header('Content-Type: application/json');

// ---------------------------------------------------------------------
// Tunable limits
// ---------------------------------------------------------------------
const MAX_NAME_LEN        = 60;
const MAX_DESC_LEN        = 30000;
const MAX_SERVER_LEN      = 100;
const MAX_TPR_SECONDS     = 2592000; // 30 days
const MAX_TBM_SECONDS     = 604800;  // 7 days
const MAX_PRIZE           = 1000000; // 1 million USD, adjust as needed
const PRIZE_DECIMALS      = 2;       // standard fiat precision (cents)
const MIN_CREATE_INTERVAL = 5;       // seconds between submissions per session

const ALLOWED_CURRENCIES  = ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];
const ALLOWED_SEED_TYPES  = [2, 3];
const ALLOWED_TEAM_COUNTS = [4, 8, 16, 32, 64, 128];


// Alternate, if spaces/newlines should be allowed in names/descriptions:
const CLEAN_TEXT_REGEX = '/^[\p{L}\p{N}# \r\n:.,|!\'?()_-]+$/u';

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------

/**
 * Returns $v if it is a scalar (string/int/float/bool), otherwise $default.
 * Prevents type-confusion attacks where a JSON array/object is submitted
 * in place of an expected string/number (e.g. {"server": ["x"]}), which
 * would otherwise throw TypeErrors or silently misbehave downstream.
 */
function scalarOrEmpty($v, $default = '') {
    return is_scalar($v) ? $v : $default;
}

function isCleanText($str, $maxLen) {
    return is_string($str)
        && $str !== ''
        && mb_strlen($str) <= $maxLen
        && preg_match(CLEAN_TEXT_REGEX, $str) === 1;
}

function toSeconds($value, $unit) {
    $value = (int)$value;
    if ($value < 1) return 0;
    $unit = strtolower(trim((string)$unit));
    return $unit === 'minutes' ? $value * 60 : $value * 3600;
}

function fail($data, $status = 'error', $msg = '', $extra = []) {
    $data['status'] = $status;
    if ($msg !== '') {
        $data['msg'] = spanerror($msg);
    }
    foreach ($extra as $k => $v) {
        $data[$k] = $v;
    }
    echo json_encode($data);
    exit();
}

// ---------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------
if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
    exit();
}
$user_id = (int)$_SESSION['user_id'];

// ---------------------------------------------------------------------
// Parse input -- reject anything that isn't a JSON object
// ---------------------------------------------------------------------
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
    exit();
}

// ---------------------------------------------------------------------
// CSRF -- verified before anything else is processed, constant-time compare
// ---------------------------------------------------------------------
$csrf_token = scalarOrEmpty($input['csrf_token'] ?? '');
if (!is_string($csrf_token) || $csrf_token === '' ||
    !isset($_SESSION['csrftokken']) ||
    !hash_equals((string)$_SESSION['csrftokken'], $csrf_token)) {
    die(json_encode(['status' => 'error', 'msg' => spanerror("Invalid CSRF token.")]));
}

$tournamentObj = new Tournament($mysqli);

// ---------------------------------------------------------------------
// Optional lightweight rate limit (per session)
// ---------------------------------------------------------------------
if (isset($_SESSION['last_tournament_create']) &&
    (time() - $_SESSION['last_tournament_create']) < MIN_CREATE_INTERVAL) {
    fail(['status' => '', 'msg' => ''], 'error2', "You're doing that too quickly. Please wait a moment and try again.");
}

// ---------------------------------------------------------------------
// Confirm the session user still exists as a member
// ---------------------------------------------------------------------
$stmt = $mysqli->prepare("SELECT member_id FROM {$dbprefix}members WHERE member_id = ?");
if (!$stmt) {
    exit();
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if (!$result || $result->num_rows === 0) {
    exit();
}
$stmt->close();

$data = ['status' => '', 'msg' => ''];

// ---------------------------------------------------------------------
// Pull + type-guard every field before validating it
// ---------------------------------------------------------------------
$tournamentname             = trim((string)scalarOrEmpty($input['tournamentname'] ?? ''));
$game                       = scalarOrEmpty($input['game'] ?? '');
$extrainfo                  = trim((string)scalarOrEmpty($input['extrainfo'] ?? ''));
$t_prize_raw                = scalarOrEmpty($input['t_prize'] ?? '');
$tPrizeCurrency             = (string)scalarOrEmpty($input['tPrizeCurrency'] ?? 'BTC', 'BTC');
$server                     = trim((string)scalarOrEmpty($input['server'] ?? ''));
$TPR                        = scalarOrEmpty($input['TPR'] ?? '');
$TPRA                       = strtolower(trim((string)scalarOrEmpty($input['TPRA'] ?? 'hours', 'hours')));
$time_between_matches       = scalarOrEmpty($input['time_between_matches'] ?? '');
$time_between_matches_unit  = strtolower(trim((string)scalarOrEmpty($input['time_between_matches_unit'] ?? 'hours', 'hours')));
$eliminations_input         = (string)scalarOrEmpty($input['eliminations_input'] ?? '');
$seedtype_input             = scalarOrEmpty($input['seedtype_input'] ?? '');
$totalteams_input           = scalarOrEmpty($input['totalteams_input'] ?? '');
$playersperteam_input       = scalarOrEmpty($input['playersperteam_input'] ?? '');
$tournamentaccess_input     = (string)scalarOrEmpty($input['tournamentaccess_input'] ?? '3', '3');
$registration_status_raw    = scalarOrEmpty($input['registration_status'] ?? 1, 1);
$registration_status        = is_numeric($registration_status_raw) ? (int)$registration_status_raw : -1;

// ---------------------------------------------------------------------
// Tournament name -- letters, numbers, # only
// ---------------------------------------------------------------------
if (!isCleanText($tournamentname, MAX_NAME_LEN)) {
    fail($data, 'error',
        "Tournament Name is required and may only contain letters, numbers, and #.",
        ['plc' => "#tournamentname-error", 'inp' => "#tournamentname", 'bor' => "#tournamentname"]);
}

// ---------------------------------------------------------------------
// Game -- strict whitelist match
// ---------------------------------------------------------------------
$gameObj = new Game($mysqli);
$arrGamesPlayed = $gameObj->getGameList();

if (!is_array($arrGamesPlayed) || !is_string($game) || !in_array($game, $arrGamesPlayed, true)) {
    fail($data, 'error', "You selected an invalid game.", ['plc' => "#game-error"]);
}

// ---------------------------------------------------------------------
// Description -- letters, numbers, # only
// ---------------------------------------------------------------------
if (!isCleanText($extrainfo, MAX_DESC_LEN)) {
    fail($data, 'error',
        "Tournament Rules are required and may only contain letters, numbers, and #.",
        ['plc' => "#extrainfo-error", 'inp' => "#extrainfo", 'bor' => "#extrainfo"]);
}

// ---------------------------------------------------------------------
// Prize -- numeric, non-negative, bounded, fixed precision (fiat)
// ---------------------------------------------------------------------
$t_prize_str = trim((string)$t_prize_raw);
if ($t_prize_str !== "") {
    // Fiat validation: allow only 2 decimal places, positive numbers
    if (!is_numeric($t_prize_str) ||
        !preg_match('/^\d+(\.\d{1,2})?$/', $t_prize_str) || // max 2 decimal places for fiat
        (float)$t_prize_str < 0 ||
        (float)$t_prize_str > MAX_PRIZE) {
        fail($data, 'error', "Prize amount is not a valid format. Use up to 2 decimal places (e.g., 100.00).",
            ['plc' => "#t_prize-error", 'inp' => "#t_prize", 'bor' => "#t_prize"]);
    }
    $t_prize = round((float)$t_prize_str, PRIZE_DECIMALS);
} else {
    $t_prize = 0.0;
}

// ---------------------------------------------------------------------
// Server -- required, bounded length, restricted character set
// ---------------------------------------------------------------------
if ($server === "" ||
    mb_strlen($server) > MAX_SERVER_LEN ||
    preg_match('/^[\p{L}\p{N}# \r\n:.,|!\'?()_-]+$/', $server) !== 1) {
    fail($data, 'error', "You selected an invalid server.", ['plc' => "#server-error", 'bor' => "#server"]);
}




// ---------------------------------------------------------------------
// Prize currency -- strict whitelist (fiat currencies)
// ---------------------------------------------------------------------
if (!in_array($tPrizeCurrency, ALLOWED_CURRENCIES, true)) {
    fail($data, 'error', "You selected an invalid prize currency.");
}

// ---------------------------------------------------------------------
// Registration status: 0 = closed, 1 = open
// ---------------------------------------------------------------------
if (!in_array($registration_status, [0, 1], true)) {
    fail($data, 'error', "You selected an invalid registration status.",
        ['plc' => "#registrationstatus-error", 'inp' => "#registrationstatus", 'bor' => "#registrationstatus"]);
}

// ---------------------------------------------------------------------
// Time between rounds
// ---------------------------------------------------------------------
if (!is_numeric($TPR) || (int)$TPR < 1 || (float)$TPR != (int)$TPR) {
    fail($data, 'error', "Time-Between-Rounds has to be a real value (with hours).",
        ['plc' => "#TPR-error", 'inp' => "#TPR", 'bor' => "#TPR"]);
}
if ($TPRA !== "hours" && $TPRA !== "minutes") {
    fail($data, 'error', "You selected an invalid time-between-rounds unit.",
        ['plc' => "#TPR-error", 'inp' => "#TPR", 'bor' => "#TPR"]);
}

// ---------------------------------------------------------------------
// Time between each match
// ---------------------------------------------------------------------
if (!is_numeric($time_between_matches) || (int)$time_between_matches < 1 ||
    (float)$time_between_matches != (int)$time_between_matches) {
    fail($data, 'error', "Time between each match has to be a real value.",
        ['plc' => "#TBM-error", 'inp' => "#TBM", 'bor' => "#TBM"]);
}
if ($time_between_matches_unit !== "hours" && $time_between_matches_unit !== "minutes") {
    fail($data, 'error', "You selected an invalid time-between-matches unit.",
        ['plc' => "#TBM-error", 'inp' => "#TBM", 'bor' => "#TBM"]);
}

$TPR_seconds = toSeconds($TPR, $TPRA);
$TBM_seconds = toSeconds($time_between_matches, $time_between_matches_unit);

// Bound the *converted* values so an attacker can't submit an absurd
// hour/minute count and produce an overflow-scale or nonsensical schedule.
if ($TPR_seconds < 1 || $TPR_seconds > MAX_TPR_SECONDS) {
    fail($data, 'error', "Time-Between-Rounds is out of the allowed range.",
        ['plc' => "#TPR-error", 'inp' => "#TPR", 'bor' => "#TPR"]);
}
if ($TBM_seconds < 1 || $TBM_seconds > MAX_TBM_SECONDS) {
    fail($data, 'error', "Time between each match is out of the allowed range.",
        ['plc' => "#TBM-error", 'inp' => "#TBM", 'bor' => "#TBM"]);
}

// ---------------------------------------------------------------------
// Eliminations
// ---------------------------------------------------------------------
if ($eliminations_input !== "1") {
    fail($data, 'error2', "You selected an invalid eliminations type option.");
}

// ---------------------------------------------------------------------
// Seed type
// ---------------------------------------------------------------------
if (!is_numeric($seedtype_input) || !in_array((int)$seedtype_input, ALLOWED_SEED_TYPES, true)) {
    fail($data, 'error2', "You selected an invalid seed type option.");
}
$seedtype_input = (int)$seedtype_input;

// ---------------------------------------------------------------------
// Total teams
// ---------------------------------------------------------------------
if (!is_numeric($totalteams_input) || !in_array((int)$totalteams_input, ALLOWED_TEAM_COUNTS, true)) {
    fail($data, 'error2', "You selected an invalid Max Teams/players option.");
}
$totalteams_input = (int)$totalteams_input;

// ---------------------------------------------------------------------
// Players per team
// ---------------------------------------------------------------------
if (!is_numeric($playersperteam_input) || (int)$playersperteam_input < 1 || (int)$playersperteam_input > 5) {
    fail($data, 'error2', "You selected an invalid players-per-team option.");
}
$playersperteam_input = (int)$playersperteam_input;

// ---------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------
if ($tournamentaccess_input !== "1" && $tournamentaccess_input !== "3") {
    fail($data, 'error2', "You selected an invalid access type option.");
}

// ---------------------------------------------------------------------
// Date inputs
// ---------------------------------------------------------------------
$day_raw   = scalarOrEmpty($input['day'] ?? 0, 0);
$month_raw = scalarOrEmpty($input['month'] ?? 0, 0);
$year_raw  = scalarOrEmpty($input['year'] ?? 0, 0);
$hour_raw  = scalarOrEmpty($input['starthour'] ?? 0, 0);
$min_raw   = scalarOrEmpty($input['startminute'] ?? 0, 0);
$ampm      = strtoupper(trim((string)scalarOrEmpty($input['startampm'] ?? '')));
$timezone  = trim((string)scalarOrEmpty($input['startimezone'] ?? ''));

$date_error = false;

if (!is_numeric($day_raw) || !is_numeric($month_raw) || !is_numeric($year_raw)) {
    $date_error = true;
} else {
    $day   = (int)$day_raw;
    $month = (int)$month_raw;
    $year  = (int)$year_raw;
    // Sanity-bound the year so DateTime can't be handed a pathological value
    $currentYear = (int)date('Y');
    if ($year < $currentYear || $year > $currentYear + 5) {
        $date_error = true;
    }
    if (!checkdate($month, $day, $year)) {
        $date_error = true;
    }
}

if (!is_numeric($hour_raw) || (int)$hour_raw < 1 || (int)$hour_raw > 12) {
    $date_error = true;
} else {
    $hour = (int)$hour_raw;
}

if (!is_numeric($min_raw) || (int)$min_raw < 0 || (int)$min_raw > 59) {
    $date_error = true;
} else {
    $minute = (int)$min_raw;
}

if ($ampm !== "AM" && $ampm !== "PM") {
    $date_error = true;
}

if ($date_error === true) {
    fail($data, 'error2', "You selected an invalid start date.");
}

// Validate timezone
if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
    $timezone = date_default_timezone_get();
}

// Convert 12-hour to 24-hour
if ($ampm === "AM" && $hour == 12) {
    $hour = 0;
} elseif ($ampm === "PM" && $hour != 12) {
    $hour += 12;
}

// Create UTC timestamp -- guarded against DateTime exceptions
try {
    $dtLocal = new DateTime(
        sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute),
        new DateTimeZone($timezone)
    );
} catch (Exception $e) {
    fail($data, 'error2', "You selected an invalid start date.");
}

$dtUtc = clone $dtLocal;
$dtUtc->setTimezone(new DateTimeZone('UTC'));
$formattedDate = $dtUtc->getTimestamp();

if ($formattedDate < time()) {
    fail($data, 'error2', "Tournament start date should not be in the past.");
}

// Private tournaments generate join codes
$use_codes = ($tournamentaccess_input === "1");

// ---------------------------------------------------------------------
// Build the insert -- every value here is now a validated, correctly
// typed value (not raw user input), even where the pre-cast value would
// have "looked" fine to a loose comparison.
// ---------------------------------------------------------------------
$arrColumns = [
    "member_id",
    "gamesplayed_id",
    "name",
    "seedtype",
    "startdate",
    "registration_status",
    "eliminations",
    "playersperteam",
    "maxteams",
    "description",
    "t_prize",
    "t_prize_currency",
    "TPR",
    "time_between_matches",
    "server",
    "password",
    "access",
    "timezone"
];

$arrValues = [
    $user_id,
    $game,
    $tournamentname,
    $seedtype_input,
    $formattedDate,
    $registration_status,
    (int)$eliminations_input,
    $playersperteam_input,
    $totalteams_input,
    $extrainfo,
    $t_prize,
    $tPrizeCurrency,
    $TPR_seconds,
    $TBM_seconds,
    $server,
    '',
    (int)$tournamentaccess_input,
    $timezone
];

$codes_array = null;

if ($use_codes) {
    $num_slots = $totalteams_input * $playersperteam_input;
    if ($num_slots < 1) {
        $num_slots = $totalteams_input;
    }

    $max_allowed = 512;
    if ($num_slots > $max_allowed) {
        $num_slots = $max_allowed;
    }

    $codes_array = [];
    $attempt = 0;

    while (count($codes_array) < $num_slots) {
        $attempt++;
        if ($attempt > $num_slots * 6) {
            break;
        }

        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $max = strlen($chars) - 1;
        $c = '';
        for ($i = 0; $i < 8; $i++) {
            $c .= $chars[random_int(0, $max)];
        }

        if (!in_array($c, $codes_array, true)) {
            $codes_array[] = $c;
        }
    }

    $codes_json = json_encode(array_values($codes_array));
    $arrColumns[] = "codes";
    $arrValues[] = $codes_json;
}

// ---------------------------------------------------------------------
// Insert
// ---------------------------------------------------------------------
if ($tournamentObj->addNew($arrColumns, $arrValues)) {

    // $tournament_id = $mysqli->insert_id;
    
    $result = $mysqli->query("SELECT tournament_id FROM ".$dbprefix."tournaments ORDER BY tournament_id DESC LIMIT 1");
	$row = $result->fetch_assoc();
	$tournament_id = $row['tournament_id'];

    $_SESSION['last_tournament_create'] = time();

    $data['status'] = 'success';
    $data['msg'] = $tournament_id;

    if ($use_codes && is_array($codes_array)) {
        $data['codes'] = $codes_array;
    }

    echo json_encode($data);
    exit();

} else {
    fail($data, 'error2', "Unable to create the tournament. Please contact the website administrator.");
}