<?php
require_once 'config.php';

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


// --- DATA FETCHING FOR PAGE ---

// Get all results (the "answer key")
$results = $db->query("SELECT * FROM results ORDER BY school")->fetchAll();

// Get all *unique* wild card picks that haven't been marked as opened
$wildcardSchools = $db->query("
    SELECT DISTINCT school 
    FROM wildcard_picks 
    WHERE school NOT IN (SELECT school FROM results)
    ORDER BY school
")->fetchAll(PDO::FETCH_COLUMN);

// Get statistics
$stats = [
    'total_entries' => $db->query("SELECT COUNT(*) FROM entries")->fetchColumn(),
    'filled_positions' => $db->query("SELECT COUNT(*) FROM results WHERE is_filled = 1")->fetchColumn(),
    'total_positions' => $db->query("SELECT COUNT(*) FROM results")->fetchColumn()
];

// --- CORE SCORING LOGIC ---

/**
 * Recalculates scores for ALL entries.
 * This is the new, corrected function.
 */
function calculateAllScores($db) {
    // Get all entries
    $entries = $db->query("SELECT id FROM entries")->fetchAll(PDO::FETCH_COLUMN);
    
    // --- Cache all results in an associative array for fast lookup ---
    // Key: School Name => Value: Hired Coach Name
    $allHires = $db->query("SELECT school, hired_coach FROM results WHERE is_filled = 1")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
                   
    // --- Cache all coach destinations ---
    // Key: Coach Name => Value: School Name
    $coachDestinations = $db->query("SELECT hired_coach, school FROM results WHERE is_filled = 1 AND hired_coach IS NOT NULL")
                          ->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($entries as $entryId) {
        $totalScore = 0;
        
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
            
            // Update wildcard points in DB (this is safe, we just fetched it)
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
            
            // SCENARIO 1: Exact Match (Correct school, correct coach)
            if (isset($allHires[$predictedSchool]) && strtolower(trim($allHires[$predictedSchool])) === strtolower(trim($predictedCoach))) {
                if ($pred['is_wildcard']) {
                    $points = 300; // Jackpot
                } else {
                    $points = 200; // Existing opening correct
                }
            } 
            // SCENARIO 2: No exact match. Check for Partial Credit.
            // (Did the predicted coach take *any other* P4 job?)
            elseif (isset($coachDestinations[$predictedCoach])) {
                // Yes, the coach *did* move, just to the wrong school.
                
                // Was this an existing opening prediction?
                if (!$pred['is_wildcard']) {
                    $points = 100; // Half credit for existing opening
                } 
                // This was a wildcard prediction.
                else {
                    // We need to know if the user's wildcard school opened.
                    // Find the matching wildcard pick from our earlier fetch.
                    $openedStatus = null;
                    foreach ($userWildcardPicks as $wcPick) {
                        if ($wcPick['school'] === $predictedSchool) {
                            $openedStatus = $wcPick['opened'];
                            break;
                        }
                    }

                    if ($openedStatus == 1) {
                        $points = 150; // Wildcard opened, but wrong coach
                    } else {
                        $points = 100; // Wildcard didn't open, but coach moved
                    }
                }
            }
            // SCENARIO 3: No match, no partial credit. Points remain 0.
            
            // Update the points for this specific prediction
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
        }
        td strong {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        input[type="text"] {
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Admin Panel</h1>
            <form method="POST" style="display: inline;">
                <button type="submit" name="logout" class="btn logout" onclick="<?php session_destroy(); ?>">Logout</button>
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
        
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
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
        
        <div class="section">
            <a href="leaderboard.php" class="btn">View Public Leaderboard</a>
            <a href="view_entries.php" class="btn" style="margin-left: 10px;">View All Entries</a>
        </div>
    </div>
</body>
</html>
