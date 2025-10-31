<?php
require_once 'config.php';

$selectedEntry = null;
$wildcards = [];
$predictions = [];
$error = '';

// Check if an ID is provided
if (!isset($_GET['id'])) {
    $error = 'No entry ID provided.';
} else {
    $entryId = (int)$_GET['id'];
    if ($entryId <= 0) {
        $error = 'Invalid entry ID.';
    } else {
        $db = getDB();
        
        // Get entry details
        $stmt = $db->prepare("SELECT * FROM entries WHERE id = ?");
        $stmt->execute([$entryId]);
        $selectedEntry = $stmt->fetch();
        
        if (!$selectedEntry) {
            $error = 'Entry not found. This link may be incorrect.';
        } else {
            // Get wild card picks
            $stmt = $db->prepare("SELECT * FROM wildcard_picks WHERE entry_id = ? ORDER BY school");
            $stmt->execute([$entryId]);
            $wildcards = $stmt->fetchAll();
            
            // Get coach predictions with results joined
            $stmt = $db->prepare("
                SELECT cp.*, r.hired_coach as actual_coach, r.is_filled
                FROM coach_predictions cp
                LEFT JOIN results r ON cp.school = r.school
                WHERE cp.entry_id = ?
                ORDER BY cp.is_wildcard, cp.school
            ");
            $stmt->execute([$entryId]);
            
            // Separate predictions into the two groups
            $predictions = ['existing' => [], 'wildcard' => []];
            foreach ($stmt->fetchAll() as $pred) {
                if ($pred['is_wildcard']) {
                    $predictions['wildcard'][] = $pred;
                } else {
                    $predictions['existing'][] = $pred;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Dynamic Title -->
    <title>
        <?php echo $selectedEntry ? htmlspecialchars($selectedEntry['nickname']) . "'s Picks" : "View Picks"; ?>
         - Coaching Carousel Game
    </title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 36px;
            text-align: center;
        }
        .subtitle {
            color: #666;
            text-align: center;
            margin-bottom: 30px;
            font-size: 18px;
        }
        .error-box {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            font-size: 18px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h2 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
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
        .points-positive { color: #28a745; font-weight: 600; }
        .points-negative { color: #dc3545; font-weight: 600; }
        .points-zero { color: #6c757d; }
        .wildcard-badge {
            background: #ffc107;
            color: #000;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        .status-correct { background: #d4edda; }
        .status-incorrect { background: #f8d7da; }
        .status-partial { background: #fff3cd; }
        .status-pending { background: #f8f9fa; }
        .actual-coach { color: #004085; font-weight: 600; }
        
        .btn {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 30px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 20px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #5568d3;
        }
        .button-group {
            text-align: center;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($error): ?>
            <h1>Error</h1>
            <div class="error-box"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($selectedEntry): ?>
            <h1><?= htmlspecialchars($selectedEntry['nickname']) ?>'s Picks</h1>
            <p class="subtitle">
                Total Score: <strong><?= number_format($selectedEntry['total_score']) ?> points</strong>
            </p>

            <!-- Wild Card Picks -->
            <div class="section">
                <h2>Wild Card Schools</h2>
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
                                $rowClass = 'status-pending';
                                if ($wc['opened'] === 1) {
                                    $status = '✅ Opened';
                                    $rowClass = 'status-correct';
                                } elseif ($wc['opened'] === 0) {
                                    $status = '❌ Did Not Open';
                                    $rowClass = 'status-incorrect';
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

            <!-- Coach Predictions -->
            <div class="section">
                <h2>Coach Predictions</h2>
                <table>
                    <thead>
                        <tr>
                            <th>School</th>
                            <th>Predicted Coach</th>
                            <th>Actual Hire</th>
                            <th>Points</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Existing Openings -->
                        <?php foreach ($predictions['existing'] as $pred): ?>
                            <?php
                                $actualDisplay = '⏳ TBD';
                                $rowClass = 'status-pending';
                                if ($pred['is_filled']) {
                                    $actualDisplay = $pred['actual_coach'];
                                    if ($pred['points'] >= 200) $rowClass = 'status-correct';
                                    elseif ($pred['points'] > 0) $rowClass = 'status-partial';
                                    else $rowClass = 'status-incorrect';
                                }
                                $pointsClass = $pred['points'] > 0 ? 'points-positive' : 'points-zero';
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td>
                                    <strong>
                                        <?= displayLogo($pred['school'], 35) ?>
                                        <span><?= htmlspecialchars($pred['school']) ?></span>
                                    </strong>
                                </td>
                                <td><?= htmlspecialchars($pred['coach_name']) ?></td>
                                <td class="actual-coach"><?= htmlspecialchars($actualDisplay) ?></td>
                                <td class="<?= $pointsClass ?>"><?= $pred['points'] > 0 ? '+' : '' ?><?= $pred['points'] ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Wildcard Predictions -->
                        <?php foreach ($predictions['wildcard'] as $pred): ?>
                             <?php
                                $actualDisplay = '⏳ TBD';
                                $rowClass = 'status-pending';
                                if ($pred['is_filled']) {
                                    $actualDisplay = $pred['actual_coach'];
                                    if ($pred['points'] >= 300) $rowClass = 'status-correct';
                                    elseif ($pred['points'] > 0) $rowClass = 'status-partial';
                                    else $rowClass = 'status-incorrect';
                                }
                                $pointsClass = $pred['points'] > 0 ? 'points-positive' : 'points-zero';
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td>
                                    <strong>
                                        <?= displayLogo($pred['school'], 35) ?>
                                        <span><?= htmlspecialchars($pred['school']) ?></span>
                                        <span class="wildcard-badge">WILD CARD</span>
                                    </strong>
                                </td>
                                <td><?= htmlspecialchars($pred['coach_name']) ?></td>
                                <td class="actual-coach"><?= htmlspecialchars($actualDisplay) ?></td>
                                <td class="<?= $pointsClass ?>"><?= $pred['points'] > 0 ? '+' : '' ?><?= $pred['points'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <div class="button-group">
            <a href="leaderboard.php" class="btn">View Full Leaderboard</a>
        </div>
    </div>
</body>
</html>
```

---

### 2. Update to `step2.php`

In your `step2.php` file, find the `try` block where you save the submission. You need to make **one change**: add the new `$entryId` to the session so `confirmation.php` can use it.

Find this code (around line 50):
```php
// Clear session and redirect to confirmation
$_SESSION['confirmation'] = [
    'nickname' => $_SESSION['nickname'],
    'email' => $_SESSION['email']
];
```

**Change it to this:**
```php
// Clear session and redirect to confirmation
$_SESSION['confirmation'] = [
    'nickname' => $_SESSION['nickname'],
    'email' => $_SESSION['email'],
    'entry_id' => $entryId  // <-- ADD THIS LINE
];