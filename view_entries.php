<?php
require_once 'config.php';

// Simple auth check
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

$db = getDB();
$message = '';
$edit_entry_id = (int)($_GET['entry_id'] ?? 0);

// --- Handle Saving an Entry ---
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
        
        // Manually check if this wildcard was marked "not opened"
        // This is a bit tricky, but we check if it's *not* set in opened[]
        // and *was* previously finalized (opened = 0)
        $is_finalized = isset($_POST['wildcard_finalized'][$wc_id]);
        
        $points = 0;
        if ($opened == 1) {
            $points = 100;
        } elseif ($is_finalized) { // If it was already marked as 0, keep it -50
            $points = -50;
        }
        
        // Only update 'opened' if it's 1, otherwise set it to 0 only if finalized
        $opened_status = ($opened == 1) ? 1 : ($is_finalized ? 0 : null);

        $stmt = $db->prepare("UPDATE wildcard_picks SET opened = ?, points = ? WHERE id = ?");
        $stmt->execute([$opened_status, $points, $wc_id]);
    }

    // Update coach predictions
    $coach_names = $_POST['coach_name'] ?? [];
    foreach ($coach_names as $pred_id => $coach_name) {
        $stmt = $db->prepare("UPDATE coach_predictions SET coach_name = ? WHERE id = ?");
        $stmt->execute([trim($coach_name), $pred_id]);
    }
    
    calculateAllScores($db); // Recalculate all scores
    $message = "Entry #" . $entry_id_to_save . " has been updated successfully.";
    $edit_entry_id = $entry_id_to_save; // Stay on the edit page
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Entries - Admin</title>
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
        .back-link {
            display: inline-block;
            color: #667eea;
            text-decoration: none;
            margin-bottom: 15px;
            font-weight: 600;
        }
        .back-link:hover {
            text-decoration: underline;
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
        .message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
        .btn-success {
            background: #28a745;
        }
        .btn-success:hover {
            background: #218838;
        }
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
        .points-positive { color: #28a745; font-weight: 600; }
        .points-negative { color: #dc3545; font-weight: 600; }
        .points-zero { color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="admin.php" class="back-link">← Back to Admin Panel</a>
            <h1>View All Entries</h1>
        </div>
        
        <?php if ($message): ?>
            <div class="message"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php 
        // =================================================================
        // MAIN PAGE ROUTER: Show Edit Page or List
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
            <form method="POST" action="view_entries.php?entry_id=<?= $edit_entry_id ?>">
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
            // --- ENTRIES LIST VIEW (with Search & Pagination)
            // ---
            
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
            
            // Add limit and offset to params *after* count
            // $params[] = $per_page; // These are handled below now
            // $params[] = $offset;

            $entries_stmt = $db->prepare("
                SELECT id, email, nickname, total_score 
                FROM entries 
                $where_clause
                ORDER BY total_score DESC, submission_date ASC
                LIMIT ? OFFSET ?
            ");
            
            // --- THIS IS THE FIX ---
            $param_index = 1;
            
            // Bind the search param if it exists
            if (!empty($search_email)) {
                // $params[0] holds the search string
                $entries_stmt->bindValue($param_index++, $params[0], PDO::PARAM_STR);
            }
            
            // Bind LIMIT (which is $per_page)
            $entries_stmt->bindValue($param_index++, $per_page, PDO::PARAM_INT);
            
            // Bind OFFSET (which is $offset)
            $entries_stmt->bindValue($param_index++, $offset, PDO::PARAM_INT);
            // --- END FIX ---
            
            $entries_stmt->execute();
            $entries_list = $entries_stmt->fetchAll();

        ?>
            <!-- ALL ENTRIES SECTION -->
            <div class="section">
                <h2>All Entries (<?= $total_entries ?>)</h2>
                
                <form method="GET" class="search-form" action="view_entries.php">
                    <input type="text" name="search" placeholder="Search by email..." value="<?= htmlspecialchars($search_email) ?>">
                    <button type="submit" class="btn">Search</button>
                    <?php if (!empty($search_email)): ?>
                        <a href="view_entries.php" class="btn" style="background: #6c757d;">Clear</a>
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
                                    <a href="view_entries.php?entry_id=<?= $entry['id'] ?>" style="font-weight: 600; text-decoration: none; color: #667eea;">
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

        <?php endif; // End main page router ?>
        
    </div>
</body>
</html>
