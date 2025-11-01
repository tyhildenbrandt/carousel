<?php
// leaderboard.php
require_once 'config.php';

$db = getDB();
$myRankInfo = null;
$search_email = '';

// --- New: Find My Rank Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['find_email'])) {
    $search_email = filter_var(trim($_POST['find_email'] ?? ''), FILTER_SANITIZE_EMAIL);
    if (!empty($search_email)) {
        // Find the user's rank, score, and nickname
        $stmt = $db->prepare("
            SELECT nickname, total_score, 
                   (SELECT COUNT(*) + 1 FROM entries e2 WHERE e2.total_score > e1.total_score OR (e2.total_score = e1.total_score AND e2.submission_date < e1.submission_date)) as rank
            FROM entries e1
            WHERE email = ?
        ");
        $stmt->execute([$search_email]);
        $myRankInfo = $stmt->fetch();
    }
}

// Get total number of entries
$totalEntries = $db->query("SELECT COUNT(*) FROM entries")->fetchColumn();

// Get leaderboard
$stmt = $db->query("
    SELECT nickname, total_score, submission_date
    FROM entries
    ORDER BY total_score DESC, submission_date ASC
");
$leaderboard = $stmt->fetchAll();

// --- New: Get Game Statistics ---

// 1. Most Picked Openings (Wildcards) - Top 3
$popularWildcards = $db->query("
    SELECT school, COUNT(*) as pick_count
    FROM wildcard_picks
    GROUP BY school
    ORDER BY pick_count DESC
    LIMIT 3
")->fetchAll();
$totalWildcardPicks = $db->query("SELECT COUNT(*) FROM wildcard_picks")->fetchColumn();

// 2. Most Picked Coaches (All) - Top 3
$popularCoaches = $db->query("
    SELECT coach_name, COUNT(*) as pick_count
    FROM coach_predictions
    GROUP BY coach_name
    ORDER BY pick_count DESC
    LIMIT 3
")->fetchAll();

// 3. Most Picked Hirings (Pairings) - Top 3
$popularHirings = $db->query("
    SELECT school, coach_name, COUNT(*) as pick_count
    FROM coach_predictions
    GROUP BY school, coach_name
    ORDER BY pick_count DESC
    LIMIT 3
")->fetchAll();

// Total coach predictions for percentages
$totalCoachPicks = $db->query("SELECT COUNT(*) FROM coach_predictions")->fetchColumn();

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
            padding: 2rem 1rem;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .header-section {
            background: white;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 36px;
        }
        .subtitle {
            color: #666;
            text-align: center;
            margin-bottom: 30px;
            font-size: 18px;
        }
        
        /* --- New Find My Rank Styles --- */
        .rank-finder {
            background: #f8f9fa;
            padding: 2rem;
            border-radius: 8px;
            border: 1px solid #ddd;
            margin-bottom: 2rem;
        }
        .rank-finder h2 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            text-align: center;
        }
        .rank-finder-form {
            display: flex;
            gap: 10px;
            max-width: 500px;
            margin: 0 auto;
        }
        .rank-finder-form input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ccc;
            border-radius: 6px;
            font-size: 1rem;
            flex-grow: 1;
        }
        .rank-finder-form button {
            background: #667eea;
            color: white;
            padding: 0 25px;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .rank-finder-form button:hover { background: #5568d3; }

        .your-position {
            background: #e8f4f8;
            border: 2px solid #667eea;
            padding: 20px;
            border-radius: 8px;
            margin-top: 1.5rem;
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
        .your-position .rank-desc {
            font-size: 1.1rem;
            color: #555;
        }
        .not-found {
            color: #c33;
            font-weight: 600;
            margin-top: 1rem;
        }
        /* --- End New Styles --- */

        .leaderboard-section {
            background: white;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-top: 2rem;
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
        th:first-child { border-radius: 8px 0 0 0; }
        th:last-child { border-radius: 0 8px 0 0; }
        tbody tr {
            border-bottom: 1px solid #eee;
        }
        tbody tr:last-child {
            border-bottom: none;
        }
        tbody tr:hover {
            background: #f8f9fa;
        }
        tbody tr.highlight {
            background: #e8f4f8;
            border-left: 4px solid #667eea;
            border-right: 4px solid #667eea;
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
        .btn:hover { background: #5568d3; }
        .button-group {
            text-align: center;
            margin-top: 30px;
        }
        
        /* --- New Stats Section --- */
        .stats-section {
            background: white;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-top: 2rem;
        }
        .stats-section h2 {
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
            border-bottom: 3px solid #667eea;
            padding-bottom: 0.5rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        .stats-col h3 {
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
        .stat-item {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 0.75rem;
        }
        .stat-item-school {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }
        .stat-item-pick {
            font-size: 0.95rem;
            color: #555;
            padding-left: 40px;
        }
        .stat-item-pick strong {
            color: #333;
        }
        .stat-item-pick .percent {
            color: #667eea;
            font-weight: 600;
        }
        .stat-item-just-name {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
            padding-left: 10px;
        }
        .stat-item-pick-no-logo {
            font-size: 0.95rem;
            color: #555;
            padding-left: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-section">
            <h1>🏆 Leaderboard</h1>
            <p class="subtitle">Coaching Carousel Game Standings (<?= $totalEntries ?> Players)</p>

            <!-- --- New Find My Rank Form --- -->
            <div class="rank-finder">
                <h2>Find Your Rank</h2>
                <form method="POST" class="rank-finder-form">
                    <input type="email" name="find_email" placeholder="Enter your email..." value="<?= htmlspecialchars($search_email) ?>" required>
                    <button type="submit">Find Me</button>
                </form>

                <?php if ($myRankInfo): ?>
                    <div class="your-position">
                        <h3><?= htmlspecialchars($myRankInfo['nickname']) ?>, here's your rank:</h3>
                        <div class="rank">#<?= $myRankInfo['rank'] ?></div>
                        <div class="rank-desc">Score: <?= number_format($myRankInfo['total_score']) ?> points</div>
                    </div>
                <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                     <div class="your-position" style="background: #fee; border-color: #fcc;">
                        <p class="not-found">Email not found. Please try again.</p>
                    </div>
                <?php endif; ?>
            </div>
            <!-- --- End Find My Rank --- -->
        </div>

        <!-- --- Updated Stats Section --- -->
        <div class="stats-section">
            <h2>Game Statistics</h2>
            <div class="stats-grid">
                <div class="stats-col">
                    <h3>Most Picked Openings</h3>
                    <?php if (empty($popularWildcards)): ?>
                        <p>No picks made yet!</p>
                    <?php endif; ?>
                    <?php foreach ($popularWildcards as $pick): ?>
                        <?php $percent = ($totalWildcardPicks > 0) ? round(($pick['pick_count'] / $totalWildcardPicks) * 100) : 0; ?>
                        <div class="stat-item">
                            <div class="stat-item-school">
                                <?= displayLogo($pick['school'], 30) ?>
                                <span><?= htmlspecialchars($pick['school']) ?></span>
                            </div>
                            <div class="stat-item-pick">
                                Picked <span class="percent"><?= $percent ?>%</span> of the time
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="stats-col">
                    <h3>Most Picked Coaches</h3>
                     <?php if (empty($popularCoaches)): ?>
                        <p>No picks made yet!</p>
                    <?php endif; ?>
                    <?php foreach ($popularCoaches as $pick): ?>
                        <?php $percent = ($totalCoachPicks > 0) ? round(($pick['pick_count'] / $totalCoachPicks) * 100) : 0; ?>
                        <div class="stat-item">
                            <div class="stat-item-just-name">
                                <span><?= htmlspecialchars($pick['coach_name']) ?></span>
                            </div>
                            <div class="stat-item-pick-no-logo">
                                Picked <span class="percent"><?= $percent ?>%</span> of the time
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="stats-col">
                    <h3>Most Picked Hirings</h3>
                    <?php if (empty($popularHirings)): ?>
                        <p>No picks made yet!</p>
                    <?php endif; ?>
                     <?php foreach ($popularHirings as $pick): ?>
                        <?php $percent = ($totalCoachPicks > 0) ? round(($pick['pick_count'] / $totalCoachPicks) * 100) : 0; ?>
                        <div class="stat-item">
                            <div class="stat-item-school">
                                <?= displayLogo($pick['school'], 30) ?>
                                <span><?= htmlspecialchars($pick['school']) ?></span>
                            </div>
                            <div class="stat-item-pick">
                                <strong><?= htmlspecialchars($pick['coach_name']) ?></strong>
                                (<span class="percent"><?= $percent ?>%</span> of picks)
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <!-- --- End Stats Section --- -->
        
        <div class="leaderboard-section">
            <h2>All Players</h2>
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
                            
                            // Highlight this row if it matches the search
                            $highlightClass = ($myRankInfo && $myRankInfo['nickname'] === $entry['nickname']) ? 'highlight' : '';
                            ?>
                            <tr class="<?= $highlightClass ?>">
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
    </div>
</body>
</html>
