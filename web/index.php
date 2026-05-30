<?php

session_start();
require_once __DIR__ . '/config.php';

/**
 * TourManager class to handle all backend operations
 */
class TourManager
{
    private $pdo;
    private $dsn;
    private $user;
    private $pass;
    private $table;

    public function __construct()
    {
        $this->dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        $this->user = DB_USER;
        $this->pass = DB_PASS;
        $this->table = defined('DB_TABLE') ? DB_TABLE : 'tour';
        $this->initDB();
    }

    private function initDB()
    {
        if (!$this->pdo) {
            try {
                $this->pdo = new PDO($this->dsn, $this->user, $this->pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } catch (PDOException $e) {
                die('Database Connection Error: ' . htmlspecialchars($e->getMessage()));
            }
        }
    }

    /**
     * Authenticate user with password hashing and transparent migration
     */
    public function authenticate($code, $password)
    {
        $stmt = $this->pdo->prepare('SELECT pass, data FROM tour WHERE code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        if (!$row)
            return false;

        $storedPass = $row['pass'];

        // Try modern hash verification
        if (password_verify($password, $storedPass)) {
            return $row['data'];
        }

        return false;
    }

    public function createTour($code, $password)
    {
        // Check existence
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->table . ' WHERE code = ?');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() > 0)
            return "exists";

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $tour = ['title' => '', 'code' => $code, 'buildings' => []];
        $json = json_encode($tour, JSON_UNESCAPED_UNICODE);

        $ins = $this->pdo->prepare('INSERT INTO ' . $this->table . ' (code, pass, data) VALUES(?, ?, ?)');
        return $ins->execute([$code, $hash, $json]);
    }

    public function updateTour($code, $title, $buildings)
    {
        $processedBuildings = [];
        foreach ($buildings as $b) {
            $processedBuildings[] = [
                'title' => trim($b['title'] ?? ''),
                'description' => trim($b['description'] ?? ''),
                'world' => $this->addWorldNamespace($b['world'] ?? ''),
                'coordinate' => [
                    (int) ($b['coordinate'][0] ?? 0),
                    (int) ($b['coordinate'][1] ?? 0),
                    (int) ($b['coordinate'][2] ?? 0)
                ]
            ];
        }

        $tour = [
            'title' => trim($title),
            'code' => $code,
            'buildings' => $processedBuildings
        ];
        $json = json_encode($tour, JSON_UNESCAPED_UNICODE);

        $upd = $this->pdo->prepare('UPDATE ' . $this->table . ' SET data = ? WHERE code = ?');
        return $upd->execute([$json, $code]);
    }

    public function getTourData($code)
    {
        $stmt = $this->pdo->prepare('SELECT data FROM ' . $this->table . ' WHERE code = ?');
        $stmt->execute([$code]);
        return $stmt->fetchColumn();
    }

    public function normalizeWorld($world)
    {
        if (!is_string($world) || $world === '')
            return '';
        $world = preg_replace('/^.*:/', '', $world); // Remove namespace if present
        $prefixes = ['worlds2_', 'worlds_', 'minecraft_'];
        foreach ($prefixes as $p) {
            if (strpos($world, $p) === 0)
                return substr($world, strlen($p));
        }
        return $world;
    }

    private function addWorldNamespace($world)
    {
        if (!is_string($world) || $world === '' || strpos($world, ':') !== false)
            return $world;

        // Custom mapping
        if (strpos($world, 'tt_') === 0)
            return 'worlds2_' . $world;
        if (strpos($world, 'resource_') === 0)
            return 'worlds_' . $world;
        if (in_array($world, ['world', 'world_nether', 'world_the_end']))
            return $world;
        if (strpos($world, 'world') === 0)
            return 'minecraft_' . $world;

        return $world;
    }
}

// --- Application Logic ---
$mgr = new TourManager();
$error = '';
$message = $_GET['msg'] ?? '';

// CSRF Handling
if (empty($_SESSION[CSRF_TOKEN_KEY])) {
    $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
}

function validateCSRF()
{
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION[CSRF_TOKEN_KEY]) {
        die('Invalid CSRF Token');
    }
}

// API Endpoint
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['code'])) {
    header('Content-Type: application/json; charset=utf-8');
    $data = $mgr->getTourData(trim($_GET['code']));
    if ($data) {
        echo $data;
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Tour not found']);
    }
    exit;
}

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $code = trim($_POST['tour_code'] ?? '');
    $pass = trim($_POST['tour_pass'] ?? '');
    $data = $mgr->authenticate($code, $pass);
    if ($data) {
        $_SESSION['tour_auth'] = $code;
        header('Location: ./');
        exit;
    }
    $error = 'ログイン情報が正しくありません。';
}

// Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create'])) {
    $code = trim($_POST['new_code'] ?? '');
    $pass = trim($_POST['new_pass'] ?? '');
    if ($code && $pass) {
        $res = $mgr->createTour($code, $pass);
        if ($res === "exists") {
            $error = 'コードが既に存在します。';
        } elseif ($res) {
            $_SESSION['tour_auth'] = $code;
            header('Location: ./');
            exit;
        }
    } else {
        $error = 'コードとパスワードを入力してください。';
    }
}

// Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']) && isset($_SESSION['tour_auth'])) {
    validateCSRF();
    $mgr->updateTour($_SESSION['tour_auth'], $_POST['tour_title'], $_POST['buildings'] ?? []);
    header('Location: ?msg=' . urlencode('保存しました。'));
    exit;
}

// Logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    unset($_SESSION['tour_auth']);
    header('Location: ./');
    exit;
}

// Load current data
$tourData = false;
if (isset($_SESSION['tour_auth'])) {
    $dataJson = $mgr->getTourData($_SESSION['tour_auth']);
    $tourData = $dataJson ? json_decode($dataJson, true) : false;
}

?><!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ツアー管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .building-card {
            transition: all 0.2s;
            position: relative;
        }

        .remove-btn {
            position: absolute;
            top: 10px;
            right: 10px;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container py-5">
        <?php if (!$tourData): ?>
            <div class="row justify-content-center">
                <div class="col-md-5">
                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <h2 class="card-title h4 mb-4">ツアーにログイン</h2>
                            <form method="post">
                                <div class="mb-3">
                                    <label class="form-label">コード</label>
                                    <input name="tour_code" class="form-control" required autocomplete="username">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">パスワード</label>
                                    <input name="tour_pass" type="password" class="form-control" required
                                        autocomplete="current-password">
                                </div>
                                <button name="login" class="btn btn-primary w-100">ログイン</button>
                            </form>
                        </div>
                    </div>

                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h2 class="card-title h4 mb-4">新規ツアー作成</h2>
                            <form method="post">
                                <div class="mb-3">
                                    <label class="form-label">コード</label>
                                    <input name="new_code" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">パスワード</label>
                                    <input name="new_pass" type="password" class="form-control" required>
                                </div>
                                <button name="create" class="btn btn-success w-100">作成してログイン</button>
                            </form>
                        </div>
                    </div>
                    <?php if ($error): ?>
                        <div class="alert alert-danger mt-3"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>ツアー編集 <small class="text-muted h6">(Code: <?= htmlspecialchars($_SESSION['tour_auth']) ?>)</small></h1>
                <form method="post">
                    <button name="logout" class="btn btn-outline-danger btn-sm">ログアウト</button>
                </form>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="post" id="mainForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION[CSRF_TOKEN_KEY] ?>">

                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <label class="form-label font-weight-bold">ツアータイトル</label>
                        <input name="tour_title" class="form-control form-control-lg"
                            value="<?= htmlspecialchars($tourData['title'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h4 mb-0">チェックポイント</h2>
                    <button type="button" id="addBuilding" class="btn btn-primary btn-sm">+ チェックポイント追加</button>
                </div>

                <div id="buildingContainer">
                    <?php foreach ($tourData['buildings'] ?? [] as $i => $b): ?>
                        <?= renderBuildingCard($i, $b, $mgr) ?>
                    <?php endforeach; ?>
                </div>

                <div class="container d-flex justify-content-end gap-2">
                    <button name="save" class="btn btn-success px-5">保存</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- Building Template -->
    <template id="buildingTemplate">
        <?= renderBuildingCard('__INDEX__', [], $mgr) ?>
    </template>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const container = document.getElementById('buildingContainer');
        const template = document.getElementById('buildingTemplate');

        document.getElementById('addBuilding')?.addEventListener('click', () => {
            const index = container.children.length;
            const content = template.innerHTML.replace(/__INDEX__/g, index);
            const wrapper = document.createElement('div');
            wrapper.innerHTML = content;
            const newCard = wrapper.firstElementChild;
            container.appendChild(newCard);
            newCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });

        container?.addEventListener('click', (e) => {
            if (e.target.classList.contains('remove-btn') || e.target.closest('.remove-btn')) {
                const card = e.target.closest('.building-card');
                if (confirm('このチェックポイントを削除しますか？')) {
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.9)';
                    setTimeout(() => card.remove(), 200);
                }
            }
        });
    </script>
</body>

</html>
<?php
/**
 * Helper to render a building card (used for both initial load and JS template)
 */
function renderBuildingCard($i, $data, $mgr)
{
    $title = htmlspecialchars($data['title'] ?? '');
    $desc = htmlspecialchars($data['description'] ?? '');
    $world = $mgr->normalizeWorld($data['world'] ?? '');
    $coords = $data['coordinate'] ?? [0, 0, 0];

    $worlds = [
        'resource_world' => '資源ワールド',
        'resource_nether' => '資源ネザー',
        'resource_end' => '資源エンド',
        'tt_world' => 'TTワールド',
        'tt_nether' => 'TTネザー',
        'tt_end' => 'TTエンド',
        'world' => 'メインワールド',
        'world_nether' => 'メインネザー',
        'world_the_end' => 'メインエンド'
    ];

    ob_start();
    ?>
    <div class="card building-card shadow-sm mb-3">
        <button type="button" class="btn btn-danger btn-sm remove-btn" title="削除">×</button>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small text-muted">チェックポイント名</label>
                    <input name="buildings[<?= $i ?>][title]" class="form-control" value="<?= $title ?>" required>

                    <label class="form-label small text-muted mt-2">ワールド</label>
                    <select name="buildings[<?= $i ?>][world]" class="form-select" required>
                        <?php foreach ($worlds as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($world === $val) ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label small text-muted">解説</label>
                    <textarea name="buildings[<?= $i ?>][description]" class="form-control" rows="2"
                        required><?= $desc ?></textarea>

                    <div class="row g-2 mt-2">
                        <div class="col-4">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted">X</span>
                                <input type="number" name="buildings[<?= $i ?>][coordinate][0]" class="form-control"
                                    value="<?= (int) $coords[0] ?>" required>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted">Y</span>
                                <input type="number" name="buildings[<?= $i ?>][coordinate][1]" class="form-control"
                                    value="<?= (int) $coords[1] ?>" required>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-light text-muted">Z</span>
                                <input type="number" name="buildings[<?= $i ?>][coordinate][2]" class="form-control"
                                    value="<?= (int) $coords[2] ?>" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
?>