<?php
require_once 'config.php';

$db = getDB();

// Get total number of entries
$totalEntries = $db->query("SELECT COUNT(*) FROM entries")->fetchColumn();

// Get leaderboard
$stmt = $db->query("
    SELECT nickname, total_score, submission_date
    FROM entries
    ORDER BY total_score DESC, submission_date ASC
");
$leaderboard = $stmt->fetchAll();

// Get your rank if you have an entry
$yourRank = null;
$yourScore = null;
if (isset($_GET['email'])) {
    $email = filter_var($_GET['email'], FILTER_SANITIZE_EMAIL);
    $stmt = $db->prepare("
        SELECT nickname, total_score,
               (SELECT COUNT(*) + 1 FROM entries e2 WHERE e2.total_score > e1.total_score) as rank
        FROM entries e1
        WHERE email = ?
    ");
    $stmt->execute([$email]);
    $yourEntry = $stmt->fetch();
    if ($yourEntry) {
        $yourRank = $yourEntry['rank'];
        $yourScore = $yourEntry['total_score'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leaderboard - Coaching Carousel Game</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1000px;
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
        .stats {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .stat {
            text-align: center;
        }
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        .your-position {
            background: #e8f4f8;
            border: 2px solid #667eea;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: center;
        }
        .your-position h3 {
            color: #333;
            margin-bottom: 10px;
        }
        .your-position .rank {
            font-size: 48px;
            font-weight: 700;
            color: #667eea;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: #667eea;
            color: white;
        }
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        th:first-child {
            border-radius: 8px 0 0 0;
        }
        th:last-child {
            border-radius: 0 8px 0 0;
        }
        tbody tr {
            border-bottom: 1px solid #eee;
        }
        tbody tr:hover {
            background: #f8f9fa;
        }
        td {
            padding: 15px;
        }
        .rank-cell {
            font-weight: 700;
            color: #667eea;
            font-size: 18px;
        }
        .rank-1 { color: #FFD700; }
        .rank-2 { color: #C0C0C0; }
        .rank-3 { color: #CD7F32; }
        .score-cell {
            font-weight: 600;
            font-size: 18px;
        }
        .date-cell {
            color: #666;
            font-size: 14px;
        }
        .trophy {
            margin-left: 5px;
        }
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
        <h1>🏆 Leaderboard</h1>
        <p class="subtitle">Coaching Carousel Game Standings</p>
        
        <div class="stats">
            <div class="stat">
                <div class="stat-number"><?= $totalEntries ?></div>
                <div class="stat-label">Total Players</div>
            </div>
        </div>
        
        <?php if ($yourRank): ?>
        <div class="your-position">
            <h3>Your Position</h3>
            <div class="rank">#<?= $yourRank ?></div>
            <div>Score: <?= $yourScore ?> points</div>
        </div>
        <?php endif; ?>
        
        <table>
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Player</th>
                    <th>Score</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leaderboard)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 40px; color: #666;">
                            No entries yet. Be the first to play!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($leaderboard as $index => $entry): ?>
                        <?php 
                        $rank = $index + 1;
                        $rankClass = "rank-cell";
                        $trophy = "";
                        if ($rank === 1) {
                            $rankClass .= " rank-1";
                            $trophy = "🥇";
                        } elseif ($rank === 2) {
                            $rankClass .= " rank-2";
                            $trophy = "🥈";
                        } elseif ($rank === 3) {
                            $rankClass .= " rank-3";
                            $trophy = "🥉";
                        }
                        ?>
                        <tr>
                            <td class="<?= $rankClass ?>"><?= $rank ?><span class="trophy"><?= $trophy ?></span></td>
                            <td><?= htmlspecialchars($entry['nickname']) ?></td>
                            <td class="score-cell"><?= number_format($entry['total_score']) ?></td>
                            <td class="date-cell"><?= date('M j, Y', strtotime($entry['submission_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="button-group">
            <a href="index.php" class="btn">Make Your Predictions</a>
        </div>
    </div>
</body>
</html>
