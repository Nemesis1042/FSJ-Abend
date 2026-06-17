<?php
ob_start();
error_reporting(0);

if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

define('DB_PATH', __DIR__ . '/fsj_vorab.db');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        // Auto-create tables on first connect
        $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
            code       TEXT NOT NULL PRIMARY KEY,
            state      TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS votes (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            room_code    TEXT    NOT NULL,
            question_idx INTEGER NOT NULL,
            voter_name   TEXT    NOT NULL,
            voted_for    TEXT    NOT NULL,
            UNIQUE (room_code, question_idx, voter_name)
        )");
    }
    return $pdo;
}

function jsonOut(array $data): void {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data);
    exit;
}

function errorOut(string $msg, int $code = 400): void {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    ob_end_clean();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorOut('Only POST allowed', 405);
}

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);
if (!isset($body['action'])) {
    errorOut('Missing action');
}

$action = $body['action'];

try {
    $pdo = getDB();

    switch ($action) {

        case 'health': {
            $dbOk = false;
            $dbError = null;
            try {
                $pdo->query('SELECT 1');
                $dbOk = true;
            } catch (Throwable $e) {
                $dbError = $e->getMessage();
            }
            jsonOut([
                'success' => true,
                'status'  => $dbOk ? 'ok' : 'degraded',
                'db'      => $dbOk ? 'connected' : 'error',
                'db_error'=> $dbError,
                'php'     => PHP_VERSION,
                'time'    => date('c'),
            ]);
        }

        case 'cleanup': {
            $delVotes = $pdo->prepare("DELETE FROM votes WHERE room_code IN (
                SELECT code FROM rooms WHERE created_at < datetime('now', '-24 hours')
            )");
            $delVotes->execute();

            $delRooms = $pdo->prepare("DELETE FROM rooms WHERE created_at < datetime('now', '-24 hours')");
            $delRooms->execute();
            $deleted = $delRooms->rowCount();

            jsonOut(['success' => true, 'deleted' => $deleted]);
        }

        case 'create_room': {
            $players = $body['players'] ?? [];
            $questions = $body['questions'] ?? [];
            $colors = $body['colors'] ?? [];

            if (empty($players)) errorOut('players required');
            if (empty($questions)) errorOut('questions required');

            // Generate unique 5-char code
            $code = '';
            $attempts = 0;
            do {
                $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $code = '';
                for ($i = 0; $i < 5; $i++) {
                    $code .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $check = $pdo->prepare("SELECT code FROM rooms WHERE code = ?");
                $check->execute([$code]);
                $attempts++;
            } while ($check->fetch() && $attempts < 20);

            // status flow: lobby -> voting -> reveal -> ended
            $state = json_encode([
                'players' => $players,
                'questions' => $questions,
                'colors' => $colors,
                'status' => 'lobby',
                'revealIdx' => 0,
                'joinedPlayers' => [],
                'doneVoters' => [],
            ]);

            $stmt = $pdo->prepare("INSERT INTO rooms (code, state, created_at, updated_at) VALUES (?, ?, datetime('now'), datetime('now'))");
            $stmt->execute([$code, $state]);

            jsonOut(['success' => true, 'code' => $code]);
        }

        case 'get_room': {
            $code = $body['code'] ?? '';
            if (!$code) errorOut('code required');

            $stmt = $pdo->prepare("SELECT code, state FROM rooms WHERE code = ?");
            $stmt->execute([$code]);
            $room = $stmt->fetch();
            if (!$room) errorOut('Room not found', 404);

            $state = json_decode($room['state'], true);
            $revealIdx = $state['revealIdx'] ?? 0;

            // Get vote counts for the question currently being revealed
            $voteStmt = $pdo->prepare("SELECT voted_for, COUNT(*) as cnt FROM votes WHERE room_code = ? AND question_idx = ? GROUP BY voted_for");
            $voteStmt->execute([$code, $revealIdx]);
            $voteCounts = [];
            while ($row = $voteStmt->fetch()) {
                $voteCounts[$row['voted_for']] = (int)$row['cnt'];
            }

            jsonOut([
                'success' => true,
                'room' => [
                    'code' => $room['code'],
                    'state' => $state,
                    'voteCounts' => $voteCounts,
                ],
            ]);
        }

        case 'update_room': {
            $code = $body['code'] ?? '';
            $fields = $body['fields'] ?? [];
            if (!$code) errorOut('code required');

            $stmt = $pdo->prepare("SELECT state FROM rooms WHERE code = ?");
            $stmt->execute([$code]);
            $room = $stmt->fetch();
            if (!$room) errorOut('Room not found', 404);

            $state = json_decode($room['state'], true);
            foreach ($fields as $key => $value) {
                $state[$key] = $value;
            }

            $upd = $pdo->prepare("UPDATE rooms SET state = ?, updated_at = datetime('now') WHERE code = ?");
            $upd->execute([json_encode($state), $code]);

            jsonOut(['success' => true]);
        }

        case 'vote': {
            $code = $body['code'] ?? '';
            $questionIdx = $body['questionIdx'] ?? 0;
            $voterName = $body['voterName'] ?? '';
            $votedFor = $body['votedFor'] ?? '';

            if (!$code) errorOut('code required');
            if (!$voterName) errorOut('voterName required');
            if (!$votedFor) errorOut('votedFor required');

            $stmt = $pdo->prepare("INSERT OR REPLACE INTO votes (room_code, question_idx, voter_name, voted_for)
                VALUES (?, ?, ?, ?)");
            $stmt->execute([$code, (int)$questionIdx, $voterName, $votedFor]);

            jsonOut(['success' => true]);
        }

        case 'get_votes': {
            $code = $body['code'] ?? '';
            $questionIdx = $body['questionIdx'] ?? 0;
            if (!$code) errorOut('code required');

            $stmt = $pdo->prepare("SELECT voter_name, voted_for FROM votes WHERE room_code = ? AND question_idx = ?");
            $stmt->execute([$code, (int)$questionIdx]);
            $votes = $stmt->fetchAll();

            jsonOut(['success' => true, 'votes' => $votes]);
        }

        case 'mark_voter_done': {
            $code = $body['code'] ?? '';
            $voterName = $body['voterName'] ?? '';
            if (!$code) errorOut('code required');
            if (!$voterName) errorOut('voterName required');

            $stmt = $pdo->prepare("SELECT state FROM rooms WHERE code = ?");
            $stmt->execute([$code]);
            $room = $stmt->fetch();
            if (!$room) errorOut('Room not found', 404);

            $state = json_decode($room['state'], true);
            $done = $state['doneVoters'] ?? [];
            if (!in_array($voterName, $done, true)) $done[] = $voterName;
            $state['doneVoters'] = $done;

            $upd = $pdo->prepare("UPDATE rooms SET state = ?, updated_at = datetime('now') WHERE code = ?");
            $upd->execute([json_encode($state), $code]);

            jsonOut(['success' => true]);
        }

        case 'end_game': {
            $code = $body['code'] ?? '';
            if (!$code) errorOut('code required');

            $stmt = $pdo->prepare("SELECT state FROM rooms WHERE code = ?");
            $stmt->execute([$code]);
            $room = $stmt->fetch();
            if (!$room) errorOut('Room not found', 404);

            $state = json_decode($room['state'], true);
            $state['status'] = 'ended';

            $upd = $pdo->prepare("UPDATE rooms SET state = ?, updated_at = datetime('now') WHERE code = ?");
            $upd->execute([json_encode($state), $code]);

            jsonOut(['success' => true]);
        }

        default:
            errorOut('Unknown action');
    }

} catch (Throwable $e) {
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        errorOut($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 500);
    }
    errorOut('Server error', 500);
}
