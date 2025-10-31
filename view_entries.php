<?php
require_once 'config.php';

// Simple auth check
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

$db = getDB();

// Get all entries with details
$entries = $db->query("
    SELECT id, nickname, email, total_score, submission_date
    FROM entries
    ORDER BY total_score DESC, submission_date ASC
")->fetchAll();

// If viewing specific entry
$selectedEntry = null;
if (isset($_GET['entry_id'])) {
    $entryId = (int)$_GET['entry_id'];
    
    // Get entry details
    $stmt = $db->prepare("SELECT * FROM entries WHERE id = ?");
    $stmt->execute([$entryId]);
    $selectedEntry = $stmt->fetch();
    
    if ($selectedEntry) {
        // Get wild card picks
        $stmt = $db->prepare("SELECT * FROM wildcard_picks WHERE entry_id = ?");
        $stmt->execute([$entryId]);
        $wildcards = $stmt->fetchAll();
        
        // Get coach predictions
        $stmt = $db->prepare("
            SELECT cp.*, r.hired_coach as actual_coach, r.is_filled
            FROM coach_predictions cp
            LEFT JOIN results r ON cp.school = r.school
            WHERE cp.entry_id = ?
            ORDER BY cp.is_wildcard, cp.school
        ");
        $stmt->execute([$entryId]);
        $predictions = $stmt->fetchAll();
    }
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
            max-width: 1400px;
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
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .main-content {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
        }
        .entries-list {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            max-height: 80vh;
            overflow-y: auto;
        }
        .entry-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.2s;
        }
        .entry-item:hover {
            background: #f8f9fa;
        }
        .entry-item.active {
            background: #e8f4f8;
            border-left: 3px solid #667eea;
        }
        .entry-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        .entry-score {
            color: #667eea;
            font-weight: 600;
        }
        .entry-email {
            font-size: 12px;
            color: #666;
        }
        .detail-panel {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .detail-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #667eea;
        }
        .detail-header h2 {
            color: #333;
            margin-bottom: 10px;
        }
        .detail-info {
            color: #666;
            margin-bottom: 5px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h3 {
            color: #333;
            margin-bottom: 15px;
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
        .correct {
            background: #d4edda;
        }
        .incorrect {
            background: #f8d7da;
        }
        .partial {
            background: #fff3cd;
        }
        .pending {
            background: #f8f9fa;
        }
        .points-positive {
            color: #28a745;
            font-weight: 600;
        }
        .points-negative {
            color: #dc3545;
            font-weight: 600;
        }
        .points-zero {
            color: #6c757d;
        }
        .wildcard-badge {
            background: #ffc107;
            color: #000;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        .placeholder {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        .placeholder svg {
            width: 100px;
            height: 100px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="admin.php" class="back-link">← Back to Admin Panel</a>
            <h1>View All Entries</h1>
        </div>
        
        <div class="main-content">
            <div class="entries-list">
                <h3 style="margin-bottom: 15px;">All Players (<?= count($entries) ?>)</h3>
                <?php foreach ($entries as $entry): ?>
                    <a href="?entry_id=<?= $entry['id'] ?>" style="text-decoration: none; color: inherit;">
                        <div class="entry-item <?= $selectedEntry && $selectedEntry['id'] == $entry['id'] ? 'active' : '' ?>">
                            <div class="entry-name"><?= htmlspecialchars($entry['nickname']) ?></div>
                            <div class="entry-score"><?= number_format($entry['total_score']) ?> pts</div>
                            <div class="entry-email"><?= htmlspecialchars($entry['email']) ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <div class="detail-panel">
                <?php if ($selectedEntry): ?>
                    <div class="detail-header">
                        <h2><?= htmlspecialchars($selectedEntry['nickname']) ?></h2>
                        <div class="detail-info"><strong>Email:</strong> <?= htmlspecialchars($selectedEntry['email']) ?></div>
                        <div class="detail-info"><strong>Submitted:</strong> <?= date('F j, Y g:i A', strtotime($selectedEntry['submission_date'])) ?></div>
                        <div class="detail-info"><strong>Total Score:</strong> <span style="font-size: 24px; color: #667eea; font-weight: 700;"><?= number_format($selectedEntry['total_score']) ?></span> points</div>
                    </div>
                    
                    <div class="section">
                        <h3>Wild Card Picks</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>School</th>
                                    <th>Status</th>
                                    <th>Points</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($wildcards as $wc): ?>
                                    <?php
                                    $status = 'Pending';
                                    $rowClass = 'pending';
                                    if ($wc['opened'] === 1) {
                                        $status = '✅ Opened';
                                        $rowClass = 'correct';
                                    } elseif ($wc['opened'] === 0) {
                                        $status = '❌ Did Not Open';
                                        $rowClass = 'incorrect';
                                    }
                                    
                                    $pointsClass = $wc['points'] > 0 ? 'points-positive' : ($wc['points'] < 0 ? 'points-negative' : 'points-zero');
                                    ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td>
                                            <strong>
                                                <?= displayLogo($wc['school'], 35) ?>
                                                <span><?= htmlspecialchars($wc['school']) ?></span>
                                            </strong>
                                        </td>
                                        <td><?= $status ?></td>
                                        <td class="<?= $pointsClass ?>"><?= $wc['points'] > 0 ? '+' : '' ?><?= $wc['points'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="section">
                        <h3>Coach Predictions</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>School</th>
                                    <th>Predicted Coach</th>
                                    <th>Actual Coach</th>
                                    <th>Points</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($predictions as $pred): ?>
                                    <?php
                                    $rowClass = 'pending';
                                    $actualDisplay = 'TBD';
                                    
                                    if ($pred['is_filled']) {
                                        $actualDisplay = htmlspecialchars($pred['actual_coach']);
                                        if ($pred['points'] >= 200) {
                                            $rowClass = 'correct';
                                        } elseif ($pred['points'] > 0) {
                                            $rowClass = 'partial';
                                        } else {
                                            $rowClass = 'incorrect';
                                        }
                                    }
                                    
                                    $pointsClass = $pred['points'] > 0 ? 'points-positive' : 'points-zero';
                                    ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td>
                                            <strong>
                                                <?= displayLogo($pred['school'], 35) ?>
                                                <span><?= htmlspecialchars($pred['school']) ?></span>
                                            </strong>
                                            <?php if ($pred['is_wildcard']): ?>
                                                <span class="wildcard-badge">WILD CARD</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($pred['coach_name']) ?></td>
                                        <td><?= $actualDisplay ?></td>
                                        <td class="<?= $pointsClass ?>"><?= $pred['points'] > 0 ? '+' : '' ?><?= $pred['points'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="placeholder">
                        <p style="font-size: 18px;">Select a player from the list to view their predictions</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
