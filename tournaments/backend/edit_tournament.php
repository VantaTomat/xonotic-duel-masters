<?php
// update_tournament.php
declare(strict_types=1);

include_once("../../_intro.php");
include_once("../../classes/tournament.php");
include_once("../../classes/game.php");

header('Content-Type: application/json');

// ---------------------------------------------------------------------
// Tunable limits
// ---------------------------------------------------------------------
const MAX_NAME_LEN         = 60;
const MAX_DESC_LEN         = 30000;
const MAX_SERVER_LEN       = 100;
const MAX_TPR_SECONDS      = 2592000; // 30 days
const MAX_TBM_SECONDS      = 604800;  // 7 days
const MAX_PRIZE           = 1000000; // 1 million USD, adjust as needed
const PRIZE_DECIMALS      = 2;       // standard fiat precision (cents)
const MIN_UPDATE_INTERVAL  = 5;       // seconds between submissions per session
const MAX_JOIN_CODE_SLOTS  = 512;

const ALLOWED_CURRENCIES  = ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];
const ALLOWED_SEED_TYPES  = [2, 3];
const ALLOWED_TEAM_COUNTS = [4, 8, 16, 32, 64, 128];

// Letters, numbers, #, whitespace, and basic punctuation.
const CLEAN_TEXT_REGEX = '/^[\p{L}\p{N}# \r\n:.,|!\'?()_-]+$/u';

// ---------------------------------------------------------------------
// Helpers (kept identical in name/shape to create_tournament.php)
// ---------------------------------------------------------------------

/**
 * Returns $v if it is a scalar (string/int/float/bool), otherwise $default.
 * Prevents type-confusion attacks where a JSON array/object is submitted
 * in place of an expected string/number.
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

function generateJoinCodesArray(int $numSlots): array {
    $numSlots = min($numSlots, MAX_JOIN_CODE_SLOTS);
    $codes = [];
    $attempt = 0;
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $max = strlen($chars) - 1;

    while (count($codes) < $numSlots && ++$attempt <= $numSlots * 6) {
        $str = '';
        for ($i = 0; $i < 8; $i++) {
            $str .= $chars[random_int(0, $max)];
        }
        if (!in_array($str, $codes, true)) {
            $codes[] = $str;
        }
    }
    return array_values($codes);
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
if (isset($_SESSION['last_tournament_update']) &&
    (time() - $_SESSION['last_tournament_update']) < MIN_UPDATE_INTERVAL) {
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
// Load the tournament being edited, and confirm the session user is
// actually allowed to edit it (owner, or admin). This replaces the old
// hardcoded "only user_id === 1 may call this endpoint" check, which
// blocked every real tournament owner from editing their own event.
// ---------------------------------------------------------------------
$tID = (int)scalarOrEmpty($input['t_id'] ?? 0);
if ($tID <= 0) {
    exit();
}

$tStmt = $mysqli->prepare("SELECT * FROM {$dbprefix}tournaments WHERE tournament_id = ? LIMIT 1");
$tStmt->bind_param("i", $tID);
$tStmt->execute();
$tResult = $tStmt->get_result();

if (!$tResult || $tResult->num_rows === 0) {
    exit();
}
$tournamentInfo = $tResult->fetch_assoc();
$tStmt->close();

$isAdmin = ($user_id === 1);
$isOwner = isset($tournamentInfo['member_id']) && (int)$tournamentInfo['member_id'] === $user_id;

if (!$isAdmin && !$isOwner) {
    http_response_code(403);
    exit();
}

$originalMaxTeams        = (int)($tournamentInfo['maxteams'] ?? 0);
$originalSeedtype        = (int)($tournamentInfo['seedtype'] ?? 0);
$originalStartTimestamp  = (int)($tournamentInfo['startdate'] ?? 0);
$originalTPRSeconds      = (int)($tournamentInfo['TPR'] ?? 0);
$originalPlayersPerTeam  = (int)($tournamentInfo['playersperteam'] ?? 1);
$originalAccess          = (int)($tournamentInfo['access'] ?? 3);
$existingCodes           = $tournamentInfo['codes'] ?? '';

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
// Tournament name
// ---------------------------------------------------------------------
if (!isCleanText($tournamentname, MAX_NAME_LEN)) {
    fail($data, 'error',
        "Tournament Name is required and contains invalid characters.",
        ['plc' => "#tournamentname-error", 'inp' => "#tournamentname", 'bor' => "#tournamentname"]);
}

// ---------------------------------------------------------------------
// Game -- strict whitelist match (string, same as create_tournament.php)
// ---------------------------------------------------------------------
$gameObj = new Game($mysqli);
$arrGamesPlayed = $gameObj->getGameList();

if (!is_array($arrGamesPlayed) || !is_string($game) || !in_array($game, $arrGamesPlayed, true)) {
    fail($data, 'error', "You selected an invalid game.", ['plc' => "#game-error"]);
}

// ---------------------------------------------------------------------
// Description
// ---------------------------------------------------------------------
if (!isCleanText($extrainfo, MAX_DESC_LEN)) {
    fail($data, 'error',
        "Tournament Rules are required and contain invalid characters.",
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

if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
    $timezone = date_default_timezone_get();
}

if ($ampm === "AM" && $hour == 12) {
    $hour = 0;
} elseif ($ampm === "PM" && $hour != 12) {
    $hour += 12;
}

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
$startTimestamp = $dtUtc->getTimestamp();

// ---------------------------------------------------------------------
// Derived update logic
// ---------------------------------------------------------------------
$newMaxTeams   = $totalteams_input;
$playersPerTeam = $playersperteam_input;
$seedtype      = $seedtype_input;
$newAccess     = (int)$tournamentaccess_input;

$shouldRebuildBracket = ($originalMaxTeams !== $newMaxTeams)
    || ($originalSeedtype !== $seedtype)
    || ($originalStartTimestamp !== $startTimestamp)
    || ($originalTPRSeconds !== $TPR_seconds);

$needsCodes = ($newAccess === 1) && (
    $originalAccess !== 1
    || $originalMaxTeams !== $newMaxTeams
    || $originalPlayersPerTeam !== $playersPerTeam
    || empty($existingCodes)
);

$generatedCodesJson = null;
if ($needsCodes) {
    $numSlots = max(1, $newMaxTeams * $playersPerTeam);
    $generatedCodesJson = json_encode(generateJoinCodesArray($numSlots));
}

// ---------------------------------------------------------------------
// Update -- wrapped in a transaction since it can touch multiple tables
// ---------------------------------------------------------------------
$mysqli->begin_transaction();

try {
    $sql = "UPDATE {$dbprefix}tournaments
            SET gamesplayed_id       = ?,
                name                 = ?,
                seedtype             = ?,
                startdate            = ?,
                registration_status  = ?,
                eliminations         = ?,
                playersperteam       = ?,
                maxteams             = ?,
                description          = ?,
                t_prize              = ?,
                t_prize_currency     = ?,
                TPR                  = ?,
                time_between_matches = ?,
                server               = ?,
                access               = ?,
                timezone             = ?"
        . ($needsCodes ? ", codes = ?" : "") .
        " WHERE tournament_id = ?";

    $stmt = $mysqli->prepare($sql);
    $eliminations_int = (int)$eliminations_input;

    if ($needsCodes) {
        $stmt->bind_param(
            'ssiiiiiisdsiisissi',
            $game, $tournamentname, $seedtype, $startTimestamp, $registration_status,
            $eliminations_int, $playersPerTeam, $newMaxTeams, $extrainfo, $t_prize,
            $tPrizeCurrency, $TPR_seconds, $TBM_seconds, $server, $newAccess, $timezone,
            $generatedCodesJson, $tID
        );
    } else {
        $stmt->bind_param(
            'ssiiiiiisdsiisisi',
            $game, $tournamentname, $seedtype, $startTimestamp, $registration_status,
            $eliminations_int, $playersPerTeam, $newMaxTeams, $extrainfo, $t_prize,
            $tPrizeCurrency, $TPR_seconds, $TBM_seconds, $server, $newAccess, $timezone, $tID
        );
    }

    if (!$stmt->execute()) {
        throw new RuntimeException("Tournament update failed: " . $stmt->error);
    }
    $stmt->close();

    // Team count changes: remove or add team rows
    if ($originalMaxTeams !== $newMaxTeams) {
        if ($originalMaxTeams > $newMaxTeams) {
            $numToRemove = $originalMaxTeams - $newMaxTeams;

            $removeStmt = $mysqli->prepare("SELECT tournamentteam_id FROM {$dbprefix}tournamentteams WHERE tournament_id = ? ORDER BY seed DESC LIMIT ?");
            $removeStmt->bind_param('ii', $tID, $numToRemove);
            $removeStmt->execute();
            $removeResult = $removeStmt->get_result();

            $delPlayersStmt = $mysqli->prepare("DELETE FROM {$dbprefix}tournamentplayers WHERE team_id = ?");
            $delTeamStmt = $mysqli->prepare("DELETE FROM {$dbprefix}tournamentteams WHERE tournamentteam_id = ?");

            while ($row = $removeResult->fetch_assoc()) {
                $removeTeamID = (int)$row['tournamentteam_id'];
                $delPlayersStmt->bind_param('i', $removeTeamID);
                $delPlayersStmt->execute();

                $delTeamStmt->bind_param('i', $removeTeamID);
                $delTeamStmt->execute();
            }
            $removeStmt->close();
            $delPlayersStmt->close();
            $delTeamStmt->close();
        } else {
            $numToAdd = $newMaxTeams - $originalMaxTeams;
            $nextSeed = $originalMaxTeams + 1;

            $addStmt = $mysqli->prepare("INSERT INTO {$dbprefix}tournamentteams (tournament_id, seed, name) VALUES (?, ?, ?)");

            for ($i = 0; $i < $numToAdd; $i++) {
                $teamName = "Team {$nextSeed}";
                $addStmt->bind_param('iis', $tID, $nextSeed, $teamName);
                if (!$addStmt->execute()) {
                    throw new RuntimeException("Team insert failed: " . $addStmt->error);
                }
                $nextSeed++;
            }
            $addStmt->close();
        }
    }

    if ($shouldRebuildBracket) {
        // Wipe the old matches, then let Tournament::resetMatches() -- the
        // same routine create_tournament.php relies on -- rebuild the
        // bracket and pair teams. It reads round/team data off arrObjInfo,
        // so re-select() the row first to pick up the values we just wrote.
        $delStmt = $mysqli->prepare("DELETE FROM {$dbprefix}tournamentmatch WHERE tournament_id = ?");
        $delStmt->bind_param('i', $tID);
        $delStmt->execute();
        $delStmt->close();

        // Pool-based tournaments don't use a bracket.
        if ($seedtype !== 3) {
            $tournamentObj->select($tID);
            if (!$tournamentObj->resetMatches()) {
                throw new RuntimeException("Bracket rebuild failed.");
            }
        }
    }

    $mysqli->commit();

    $_SESSION['last_tournament_update'] = time();

    $data['status'] = 'success';
    $data['msg'] = 'Tournament updated successfully.';

    if ($needsCodes && $generatedCodesJson !== null) {
        $data['codes'] = json_decode($generatedCodesJson, true);
    }

    echo json_encode($data);
    exit();

} catch (Throwable $e) {
    $mysqli->rollback();
    fail($data, 'error2', "Unable to update the tournament. Please contact the website administrator.");
}
