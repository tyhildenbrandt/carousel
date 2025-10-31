<?php
require_once 'config.php';

// --- ADD THIS NEW BLOCK ---
// Handle logout
if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}
// --- END NEW BLOCK ---

// Simple authentication
if (!isset($_SESSION['admin_logged_in'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        $db = getDB();
        $stmt = $db->prepare("SELECT password_hash FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
        } else {
            $loginError = 'Invalid credentials';
        }
    }
    
    if (!isset($_SESSION['admin_logged_in'])) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Admin Login</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
                .login-box { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 300px; }
                h2 { margin-bottom: 20px; }
                input { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ddd; border-radius: 4px; }
                button { width: 100%; padding: 12px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; }
                .error { color: red; margin-bottom: 15px; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h2>Admin Login</h2>
                <?php if (isset($loginError)): ?>
                    <div class="error"><?= $loginError ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="text" name="username" placeholder="Username" required>
                    <input type="password" name="password" placeholder="Password" required>
                    <button type="submit" name="login">Login</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

$db = getDB();
$message = '';
$edit_entry_id = (int)($_GET['edit_id'] ?? 0);

// --- ALL FORM HANDLING ---

// Handle result updates for main openings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_result'])) {
    $school = $_POST['school'];
    $hiredCoach = trim($_POST['hired_coach']);
    
    if (!empty($hiredCoach)) {
        $stmt = $db->prepare("UPDATE results SET hired_coach = ?, is_filled = 1, filled_date = NOW() WHERE school = ?");
        $stmt->execute([$hiredCoach, $school]);
        $message = "Updated: $school hired $hiredCoach";
        calculateAllScores($db);
    }
}

// Handle marking a wild card school as OPENED
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_wildcard_opened'])) {
    $school = $_POST['wildcard_school'];
    
    // Add to results table as an open position
    $stmt = $db->prepare("INSERT INTO results (school, is_filled) VALUES (?, 0) ON DUPLICATE KEY UPDATE school = school");
    $stmt->execute([$school]);
    
    // Update wildcard_picks table to mark as correct (+100)
    $stmt = $db->prepare("UPDATE wildcard_picks SET opened = 1, points = 100 WHERE school = ?");
    $stmt->execute([$school]);
    
    $message = "Marked $school as opened (Wild Card)";
    calculateAllScores($db);
}

// Handle adding a new "other" P4 hire for partial credit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_other_hire'])) {
    $school = trim($_POST['other_school']);
    $hiredCoach = trim($_POST['other_coach']);
    
    if (!empty($school) && !empty($hiredCoach)) {
        // Insert this hire into the results table so it can be found
        $stmt = $db->prepare("
            INSERT INTO results (school, hired_coach, is_filled, filled_date) 
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE hired_coach = VALUES(hired_coach), is_filled = 1
        ");
        $stmt->execute([$school, $hiredCoach]);
        
        $message = "Added 'Other' Hire: $school hired $hiredCoach. Scores will update.";
        calculateAllScores($db);
    }
}

// Handle FINALIZING wild cards (marking them as incorrect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize_wildcards'])) {
    // Find all wildcard picks that are still NULL (pending)
    // Set them to opened = 0 (false) and points = -50
    $stmt = $db->prepare("
        UPDATE wildcard_picks 
        SET opened = 0, points = -50 
        WHERE opened IS NULL
    ");
    $stmt->execute();
    
    $count = $stmt->rowCount();
    $message = "Finalized $count pending wildcard picks (-50 points each).";
    
    // Recalculate all scores
    calculateAllScores($db);
}

// --- NEW: Handle Saving an Entry ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_entry'])) {
    $entry_id_to_save = (int)$_POST['entry_id'];
    $nickname = trim($_POST['nickname']);
    $email = trim($_POST['email']);
    $bonus_points = (int)$_POST['bonus_points'];
    
    // Update main entry details
    $stmt = $db->prepare("UPDATE entries SET nickname = ?, email = ?, bonus_points = ? WHERE id = ?");
    $stmt->execute([$nickname, $email, $bonus_points, $entry_id_to_save]);
    
    // Update wildcards
    $wc_schools = $_POST['wildcard_school'] ?? [];
    $wc_opened = $_POST['wildcard_opened'] ?? [];
    foreach ($wc_schools as $wc_id => $school_name) {
        $opened = isset($wc_opened[$wc_id]) ? 1 : 0;
        // Manually set points based on 'opened' status
        $points = 0;
        if ($opened == 1) $points = 100;
        if ($opened == 0 && isset($_POST['wildcard_finalized'][$wc_id])) $points = -50; // Check if it was finalized
        
        $stmt = $db->prepare("UPDATE wildcard_picks SET opened = ?, points = ? WHERE id = ?");
        $stmt->execute([$opened, $points, $wc_id]);
    }

    // Update coach predictions
    $coach_names = $_POST['coach_name'] ?? [];
    foreach ($coach_names as $pred_id => $coach_name) {
        $stmt = $db->prepare("UPDATE coach_predictions SET coach_name = ? WHERE id = ?");
        $stmt->execute([trim($coach_name), $pred_id]);
    }
    
    calculateAllScores($db);
    $message = "Entry #" . $entry_id_to_save . " has been updated successfully.";
    $edit_entry_id = $entry_id_to_save; // Stay on the edit page
}


// --- CORE SCORING LOGIC ---

/**
 * Recalculates scores for ALL entries.
 */
function calculateAllScores($db) {
    // MODIFIED: Fetch bonus_points along with id
    $entries = $db->query("SELECT id, bonus_points FROM entries")->fetchAll(PDO::FETCH_ASSOC);
    
    $allHires = $db->query("SELECT school, hired_coach FROM results WHERE is_filled = 1")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
                   
    $coachDestinations = $db->query("SELECT hired_coach, school FROM results WHERE is_filled = 1 AND hired_coach IS NOT NULL")
                          ->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($entries as $entry) {
        $entryId = $entry['id'];
        $totalScore = (int)$entry['bonus_points'];
        
        // === 1. Score Wild Card picks ===
        $wildcards = $db->prepare("SELECT school, opened FROM wildcard_picks WHERE entry_id = ?");
        $wildcards->execute([$entryId]);
        
        $userWildcardPicks = $wildcards->fetchAll();

        foreach ($userWildcardPicks as $wc) {
            $points = 0;
            
            if ($wc['opened'] === null) {
                $points = 0; // Pending
            } elseif ($wc['opened'] == 1) {
                $points = 100; // Correct
            } else { // opened == 0
                $points = -50; // Incorrect
            }
            
            // This is just a *calculation* pass. The admin save logic handles the *actual* setting.
            // We still need to update points in the DB for this run.
            $db->prepare("UPDATE wildcard_picks SET points = ? WHERE entry_id = ? AND school = ?")
               ->execute([$points, $entryId, $wc['school']]);
            
            $totalScore += $points;
        }
        
        // === 2. Score Coach predictions ===
        $predictions = $db->prepare("SELECT id, school, coach_name, is_wildcard FROM coach_predictions WHERE entry_id = ?");
        $predictions->execute([$entryId]);
        
        foreach ($predictions->fetchAll() as $pred) {
            $points = 0;
            $predictedSchool = $pred['school'];
            $predictedCoach = $pred['coach_name'];
            
            if (isset($allHires[$predictedSchool]) && strtolower(trim($allHires[$predictedSchool])) === strtolower(trim($predictedCoach))) {
                if ($pred['is_wildcard']) {
                    $points = 300;
                } else {
                    $points = 200;
                }
            } 
            elseif (isset($coachDestinations[$predictedCoach])) {
                if (!$pred['is_wildcard']) {
                    $points = 100;
                } 
                else {
                    $openedStatus = null;
                    foreach ($userWildcardPicks as $wcPick) {
                        if ($wcPick['school'] === $predictedSchool) {
                            $openedStatus = $wcPick['opened'];
                            break;
                        }
                    }

                    if ($openedStatus == 1) {
                        $points = 150;
                    } else {
                        $points = 100;
                    }
                }
            }
            
            $db->prepare("UPDATE coach_predictions SET points = ? WHERE id = ?")
               ->execute([$points, $pred['id']]);
               
            $totalScore += $points;
        }
        
        // === 3. Update entry total score ===
        $db->prepare("UPDATE entries SET total_score = ? WHERE id = ?")
           ->execute([$totalScore, $entryId]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Coaching Carousel Game</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .stats {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        .stat-box {
            background: #667eea;
            color: white;
            padding: 20px;
            border-radius: 8px;
            flex: 1;
            text-align: center;
        }
        .stat-number {
            font-size: 32px;
            font-weight: 700;
        }
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
            margin-top: 5px;
        }
        .message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .section {
            background: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h2 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }
        td strong {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        input[type="text"], input[type="email"], input[type="number"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .btn {
            padding: 8px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
        }
        .btn:hover {
            background: #5568d3;
        }
        .btn-danger {
            background: #dc3545;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
        }
        .btn-small {
            padding: 6px 15px;
            font-size: 14px;
        }
        .filled {
            background: #d4edda;
        }
        .logout {
            float: right;
            background: #6c757d;
        }
        select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-right: 10px;
        }
        .form-group {
            display: flex; 
            align-items: center; 
            gap: 10px;
        }
        .form-group input[type="text"] {
            flex: 1;
        }
        /* New Styles for List/Edit */
        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .search-form input {
            flex-grow: 1;
        }
        .pagination {
            display: flex;
            gap: 5px;
            list-style: none;
            padding: 0;
            margin-top: 20px;
        }
        .pagination a, .pagination span {
            display: block;
            padding: 8px 12px;
            background: #f0f0f0;
            border: 1px solid #ddd;
            text-decoration: none;
            color: #667eea;
            border-radius: 4px;
        }
        .pagination a:hover {
            background: #e9e9e9;
        }
        .pagination .active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .edit-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-row {
            margin-bottom: 15px;
        }
        .form-row label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .wildcard-badge {
            background: #ffc107;
            color: #000;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .points-positive { color: #28a745; font-weight: 600; }
        .points-negative { color: #dc3545; font-weight: 600; }
        .points-zero { color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
    
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php 
        // =================================================================
        // MAIN PAGE ROUTER: Show Edit Page or Dashboard
        // =================================================================
        if ($edit_entry_id > 0): 
            // ---
            // --- EDIT ENTRY VIEW
            // ---
            
            // Get all entry data
            $stmt = $db->prepare("SELECT * FROM entries WHERE id = ?");
            $stmt->execute([$edit_entry_id]);
            $entry = $stmt->fetch();
            
            $stmt = $db->prepare("SELECT * FROM wildcard_picks WHERE entry_id = ? ORDER BY school");
            $stmt->execute([$edit_entry_id]);
            $wildcards = $stmt->fetchAll();
            
            $stmt = $db->prepare("SELECT * FROM coach_predictions WHERE entry_id = ? ORDER BY is_wildcard, school");
            $stmt->execute([$edit_entry_id]);
            $predictions = ['existing' => [], 'wildcard' => []];
            foreach ($stmt->fetchAll() as $pred) {
                if ($pred['is_wildcard']) {
                    $predictions['wildcard'][] = $pred;
                } else {
                    $predictions['existing'][] = $pred;
                }
            }

        ?>
            <a href="admin.php" class="back-link">← Back to Dashboard</a>
            <h1>Edit Entry #<?= $entry['id'] ?></h1>
            
            <form method="POST">
                <input type="hidden" name="entry_id" value="<?= $entry['id'] ?>">
                
                <div class="section">
                    <h2>Entry Details</h2>
                    <div class="edit-grid">
                        <div class="form-row">
                            <label for="nickname">Nickname</label>
                            <input type="text" id="nickname" name="nickname" value="<?= htmlspecialchars($entry['nickname']) ?>">
                        </div>
                        <div class="form-row">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($entry['email']) ?>">
                        </div>
                        <div class="form-row">
                            <label for="bonus_points">Bonus Points</label>
                            <input type="number" id="bonus_points" name="bonus_points" value="<?= (int)$entry['bonus_points'] ?>">
                        </div>
                        <div class="form-row">
                            <label>Total Score</label>
                            <input type="text" value="<?= number_format($entry['total_score']) ?>" readonly style="background: #f0f0f0;">
                        </div>
                    </div>
                </div>
                
                <div class="section">
                    <h2>Wild Card Picks</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>School</th>
                                <th>Opened? (Check for +100)</th>
                                <th>Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($wildcards as $wc): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?= displayLogo($wc['school'], 35) ?>
                                            <span><?= htmlspecialchars($wc['school']) ?></span>
                                            <input type="hidden" name="wildcard_school[<?= $wc['id'] ?>]" value="<?= htmlspecialchars($wc['school']) ?>">
                                            <?php if ($wc['opened'] === 0) echo '<input type="hidden" name="wildcard_finalized['.$wc['id'].']" value="1">'; ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <input type="checkbox" name="wildcard_opened[<?= $wc['id'] ?>]" value="1" <?= $wc['opened'] == 1 ? 'checked' : '' ?>>
                                    </td>
                                    <td>
                                        <?php
                                            $pointsClass = $wc['points'] > 0 ? 'points-positive' : ($wc['points'] < 0 ? 'points-negative' : 'points-zero');
                                            echo '<span class="' . $pointsClass . '">' . $wc['points'] . '</span>';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="section">
                    <h2>Coach Predictions</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>School</th>
                                <th>Predicted Coach</th>
                                <th>Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Existing Openings -->
                            <?php foreach ($predictions['existing'] as $pred): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?= displayLogo($pred['school'], 35) ?>
                                            <span><?= htmlspecialchars($pred['school']) ?></span>
                                        </strong>
                                    </td>
                                    <td>
                                        <input type="text" name="coach_name[<?= $pred['id'] ?>]" value="<?= htmlspecialchars($pred['coach_name']) ?>">
                                    </td>
                                    <td>
                                        <?php
                                            $pointsClass = $pred['points'] > 0 ? 'points-positive' : 'points-zero';
                                            echo '<span class="' . $pointsClass . '">' . $pred['points'] . '</span>';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <!-- Wildcard Predictions -->
                            <?php foreach ($predictions['wildcard'] as $pred): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?= displayLogo($pred['school'], 35) ?>
                                            <span><?= htmlspecialchars($pred['school']) ?></span>
                                            <span class="wildcard-badge">WILD CARD</span>
                                        </strong>
                                    </td>
                                    <td>
                                        <input type="text" name="coach_name[<?= $pred['id'] ?>]" value="<?= htmlspecialchars($pred['coach_name']) ?>">
                                    </td>
                                    <td>
                                        <?php
                                            $pointsClass = $pred['points'] > 0 ? 'points-positive' : 'points-zero';
                                            echo '<span class="' . $pointsClass . '">' . $pred['points'] . '</span>';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" name="save_entry" class="btn btn-success" style="font-size: 18px; padding: 12px 30px;">
                    Save Changes
                </button>
            </form>

        <?php 
        else: 
            // ---
            // --- MAIN DASHBOARD & ENTRIES LIST VIEW
            // ---
            
            // --- DATA FETCHING FOR DASHBOARD ---
            $stats = [
                'total_entries' => $db->query("SELECT COUNT(*) FROM entries")->fetchColumn(),
                'filled_positions' => $db->query("SELECT COUNT(*) FROM results WHERE is_filled = 1")->fetchColumn(),
                'total_positions' => $db->query("SELECT COUNT(*) FROM results")->fetchColumn()
            ];
            $results = $db->query("SELECT * FROM results ORDER BY school")->fetchAll();
            $wildcardSchools = $db->query("
                SELECT DISTINCT school 
                FROM wildcard_picks 
                WHERE school NOT IN (SELECT school FROM results)
                ORDER BY school
            ")->fetchAll(PDO::FETCH_COLUMN);

            // --- DATA FETCHING FOR ENTRIES LIST ---
            $search_email = $_GET['search'] ?? '';
            $page = (int)($_GET['page'] ?? 1);
            $per_page = 25;
            $offset = ($page - 1) * $per_page;
            
            $params = [];
            $where_clause = '';
            if (!empty($search_email)) {
                $where_clause = "WHERE email LIKE ?";
                $params[] = '%' . $search_email . '%';
            }
            
            $count_stmt = $db->prepare("SELECT COUNT(*) FROM entries $where_clause");
            $count_stmt->execute($params);
            $total_entries = $count_stmt->fetchColumn();
            $total_pages = ceil($total_entries / $per_page);
            
            $entries_stmt = $db->prepare("
                SELECT id, email, nickname, total_score 
                FROM entries 
                $where_clause
                ORDER BY total_score DESC, submission_date ASC
                LIMIT ? OFFSET ?
            ");
            // Add limit and offset to params
            $params[] = $per_page;
            $params[] = $offset;
            // We need to tell PDO the types
            $entries_stmt->bindValue(count($params) - 1, $per_page, PDO::PARAM_INT);
            $entries_stmt->bindValue(count($params), $offset, PDO::PARAM_INT);
            $entries_stmt->execute($params);
            $entries_list = $entries_stmt->fetchAll();

        ?>
            <div class="header">
                <h1>Admin Panel</h1>
                <form method="POST" style="display: inline;">
                    <button type="submit" name="logout" class="btn logout">Logout</button>
                </form>
                
                <div class="stats">
                    <div class="stat-box">
                        <div class="stat-number"><?= $stats['total_entries'] ?></div>
                        <div class="stat-label">Total Entries</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?= $stats['filled_positions'] ?> / <?= $stats['total_positions'] ?></div>
                        <div class="stat-label">Positions Filled</div>
                    </div>
                </div>
            </div>
            
            <div class="section">
                <h2>Finalize Wild Card Results</h2>
                <p style="margin-bottom: 15px; color: #333;">
                    At the end of the carousel, click this to mark all 
                    <strong>pending</strong> wild cards as incorrect (did not open) 
                    and apply the -50 point penalty.
                </p>
                <form method="POST">
                    <button type="submit" name="finalize_wildcards" class="btn btn-danger" 
                            onclick="return confirm('Are you sure? This will apply -50 points to all pending wildcards.')">
                        Finalize All Pending Wildcards
                    </button>
                </form>
            </div>

            <div class="section">
                <h2>Add 'Other' P4 Hire (for Partial Credit)</h2>
                <p style="margin-bottom: 15px; color: #333;">
                    Use this if a predicted coach takes a P4 job that wasn't an opening 
                    (e.g., Dabo to Alabama). This is required for partial credit to work.
                </p>
                <form method="POST" class="form-group">
                    <input type="text" name="other_school" placeholder="School Name (e.g., Alabama)" 
                           style="flex: 1;" required>
                    <input type="text" name="other_coach" 
                           placeholder="Coach Name (e.g., Dabo Swinney (HC - Clemson))" 
                           style="flex: 2;" required>
                    <button type="submit" name="add_other_hire" class="btn">Add Hire</button>
                </form>
            </div>

            <div class="section">
                <h2>Mark Wild Card School as Opened</h2>
                <form method="POST" class="form-group">
                    <select name="wildcard_school" required>
                        <option value="">Select a school...</option>
                        <?php foreach ($wildcardSchools as $school): ?>
                            <option value="<?= htmlspecialchars($school) ?>"><?= htmlspecialchars($school) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="mark_wildcard_opened" class="btn">Mark as Opened</button>
                </form>
            </div>
            
            <div class="section">
                <h2>Update Coaching Hires</h2>
                <table>
                    <thead>
                        <tr>
                            <th>School</th>
                            <th>Hired Coach</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $result): ?>
                            <tr class="<?= $result['is_filled'] ? 'filled' : '' ?>">
                                <td>
                                    <strong>
                                        <?= displayLogo($result['school'], 35) ?>
                                        <span><?= htmlspecialchars($result['school']) ?></span>
                                    </strong>
                                </td>
                                <td>
                                    <?php if ($result['is_filled']): ?>
                                        <?= htmlspecialchars($result['hired_coach']) ?>
                                    <?php else: ?>
                                        <form method="POST" class="form-group">
                                            <input type="hidden" name="school" value="<?= htmlspecialchars($result['school']) ?>">
                                            <input type="text" name="hired_coach" placeholder="Enter coach name" required>
                                            <button type="submit" name="update_result" class="btn btn-small">Update</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $result['is_filled'] ? '✅ Filled' : '⏳ Open' ?>
                                </td>
                                <td>
                                    <?php if ($result['is_filled']): ?>
                                        <?= date('M j, Y', strtotime($result['filled_date'])) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- NEW: ALL ENTRIES SECTION -->
            <div class="section">
                <h2>All Entries (<?= $total_entries ?>)</h2>
                
                <form method="GET" class="search-form">
                    <input type="text" name="search" placeholder="Search by email..." value="<?= htmlspecialchars($search_email) ?>">
                    <button type="submit" class="btn">Search</button>
                    <?php if (!empty($search_email)): ?>
                        <a href="admin.php" class="btn" style="background: #6c757d;">Clear</a>
                    <?php endif; ?>
                </form>
                
                <table>
                    <thead>
                        <tr>
                            <th>Nickname</th>
                            <th>Email</th>
                            <th>Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entries_list)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; color: #666;">No entries found.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($entries_list as $entry): ?>
                            <tr>
                                <td>
                                    <a href="admin.php?edit_id=<?= $entry['id'] ?>" style="font-weight: 600; text-decoration: none; color: #667eea;">
                                        <?= htmlspecialchars($entry['nickname']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($entry['email']) ?></td>
                                <td style="font-weight: 600; color: #333;"><?= number_format($entry['total_score']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li>
                            <a href="?page=<?= $i ?>&search=<?= htmlspecialchars($search_email) ?>" class="<?= $i == $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </div>
            
            <div class="section">
                <a href="leaderboard.php" class="btn" target="_blank">View Public Leaderboard</a>
            </div>

        <?php endif; // End main page router ?>
        
    </div>
</body>
</html>
