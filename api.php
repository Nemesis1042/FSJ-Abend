<?php
ob_start();
error_reporting(0);

if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// DB credentials — override via environment variables
define('MYDB_HOST', $_ENV['MYDB_HOST'] ?? 'localhost');
define('MYDB_NAME', $_ENV['MYDB_NAME'] ?? 'mydb');
define('MYDB_USER', $_ENV['MYDB_USER'] ?? 'myuser');
define('MYDB_PASS', $_ENV['MYDB_PASS'] ?? 'mypass');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . MYDB_HOST . ';dbname=' . MYDB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, MYDB_USER, MYDB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
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
            // Create tables if not exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
                code VARCHAR(6) NOT NULL PRIMARY KEY,
                state MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS votes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                room_code VARCHAR(6) NOT NULL,
                question_idx INT NOT NULL,
                voter_name VARCHAR(100) NOT NULL,
                voted_for VARCHAR(100) NOT NULL,
                UNIQUE KEY uq_vote (room_code, question_idx, voter_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Delete old votes first (FK-like)
            $delVotes = $pdo->prepare("DELETE v FROM votes v
                INNER JOIN rooms r ON v.room_code = r.code
                WHERE r.created_at < NOW() - INTERVAL 24 HOUR");
            $delVotes->execute();

            $delRooms = $pdo->prepare("DELETE FROM rooms WHERE created_at < NOW() - INTERVAL 24 HOUR");
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

            $state = json_encode([
                'players' => $players,
                'questions' => $questions,
                'colors' => $colors,
                'status' => 'lobby',
                'currentQ' => 0,
                'joinedPlayers' => [],
            ]);

            $stmt = $pdo->prepare("INSERT INTO rooms (code, state, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
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
            $currentQ = $state['currentQ'] ?? 0;

            // Get vote counts for current question
            $voteStmt = $pdo->prepare("SELECT voted_for, COUNT(*) as cnt FROM votes WHERE room_code = ? AND question_idx = ? GROUP BY voted_for");
            $voteStmt->execute([$code, $currentQ]);
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

            $upd = $pdo->prepare("UPDATE rooms SET state = ?, updated_at = NOW() WHERE code = ?");
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

            $stmt = $pdo->prepare("INSERT INTO votes (room_code, question_idx, voter_name, voted_for)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE voted_for = VALUES(voted_for)");
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

        case 'next_question': {
            $code = $body['code'] ?? '';
            if (!$code) errorOut('code required');

            $stmt = $pdo->prepare("SELECT state FROM rooms WHERE code = ?");
            $stmt->execute([$code]);
            $room = $stmt->fetch();
            if (!$room) errorOut('Room not found', 404);

            $state = json_decode($room['state'], true);
            $questions = $state['questions'] ?? [];
            $currentQ = ($state['currentQ'] ?? 0) + 1;
            $state['currentQ'] = $currentQ;

            if ($currentQ >= count($questions) - 1) {
                $state['status'] = 'ended';
            } else {
                $state['status'] = 'playing';
            }

            $upd = $pdo->prepare("UPDATE rooms SET state = ?, updated_at = NOW() WHERE code = ?");
            $upd->execute([json_encode($state), $code]);

            jsonOut(['success' => true, 'state' => $state]);
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

            $upd = $pdo->prepare("UPDATE rooms SET state = ?, updated_at = NOW() WHERE code = ?");
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
