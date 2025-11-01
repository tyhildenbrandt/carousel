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
    <!-- Removed inline styles and linked the external stylesheet -->
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <!-- Added container-xl class for correct width -->
    <div class="container container-xl">
        
        <!-- Full-width header --><div class="header-section">
            <h1>🏆 Leaderboard</h1>
            <p class="subtitle">Coaching Carousel Game Standings (<?= $totalEntries ?> Players)</p>
        </div>

        <!-- New Two-Column Layout --><div class="main-layout">
            
            <!-- Left Column --><div class="leaderboard-column">
                
                <!-- Search Bar --><div class="rank-finder">
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
                
                <!-- Leaderboard Table --><div class="leaderboard-section">
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

            <!-- Right Column --><div class="stats-column">
                <!-- Stats Section --><div class="stats-section">
                    <h2>Game Statistics</h2>
                    <div class="stats-grid">
                        <div class="stats-col">
                            <h3>Most Picked Openings</h3>
                            <?php if (empty($popularWildcards)): ?>
                                <p>No picks made yet!</p>
                            <?php endif; ?>
                            <?php foreach ($popularWildcards as $pick): ?>
                                <?php $percent = ($totalWildcardPicks > 0) ? round(($pick['pick_count'] / ($totalEntries * 4)) * 100) : 0; // Each entry has 4 wildcard picks ?>
                                <div class="stat-item">
                                    <div class="stat-item-school">
                                        <?= displayLogo($pick['school'], 30) ?>
                                        <span><?= htmlspecialchars($pick['school']) ?></span>
                                    </div>
                                    <div class="stat-item-pick">
                                        (<?= $percent ?>% of picks)
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
                                <?php
                                $coachParts = explode('(', htmlspecialchars($pick['coach_name']), 2);
                                $coachName = trim($coachParts[0]);
                                $coachDetails = isset($coachParts[1]) ? '(' . $coachParts[1] : '';
                                $percent = ($totalCoachPicks > 0) ? round(($pick['pick_count'] / $totalCoachPicks) * 100) : 0;
                                ?>
                                <div class="stat-item">
                                    <div class="stat-item-just-name">
                                        <span><?= $coachName ?></span>
                                        <?php if (!empty($coachDetails)): ?>
                                            <br><small><?= $coachDetails ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="stat-item-pick-no-logo">
                                        (<?= $percent ?>% of picks)
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
                                    <?php
                                    $coachParts = explode('(', htmlspecialchars($pick['coach_name']), 2);
                                    $coachName = trim($coachParts[0]);
                                    $coachDetails = isset($coachParts[1]) ? '(' . $coachParts[1] : '';
                                    ?>
                                    <div class="stat-item-coach-name">
                                        <span><?= $coachName ?></span>
                                        <?php if (!empty($coachDetails)): ?>
                                            <br><small><?= $coachDetails ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="stat-item-pick">
                                        (<?= $percent ?>% of picks)
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div> <!-- end .main-layout -->
    </div>
</body>
</html>

