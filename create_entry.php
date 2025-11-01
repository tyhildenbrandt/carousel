<?php
require_once 'config.php';

$error = '';
$success = '';

// Check if we are loading this page from a magic link (set in auth.php)
if (isset($_SESSION['load_success'])) {
    $success = $_SESSION['load_success'];
    unset($_SESSION['load_success']);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!GAME_ACTIVE) {
        $error = 'Submissions are currently closed.';
    } else {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $nickname = trim($_POST['nickname'] ?? '');
        $wildcards = $_POST['wildcards'] ?? [];
        
        // Validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (empty($nickname)) {
            $error = 'Please enter a nickname.';
        } elseif (count($wildcards) !== 4) {
            $error = 'Please select exactly 4 Wild Card schools.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, bonus_points FROM entries WHERE email = ?");
            $stmt->execute([$email]);
            $existing_entry = $stmt->fetch();
            
            // Check if user is a RETURNING PLAYER
            if ($existing_entry) {
                
                // Check if they are authorized (i.e., they just clicked a magic link)
                if (isset($_SESSION['auth_entry_id']) && $_SESSION['auth_entry_id'] === $existing_entry['id']) {
                    // --- SECURE OVERWRITE LOGIC ---
                    $db->beginTransaction();
                    try {
                        
                        // --- THIS IS THE FIX ---
                        // 1. Get old data *before* deleting
                        $entry_id_to_edit = $existing_entry['id'];
                        
                        // 1a. Get old coach picks
                        $stmt = $db->prepare("SELECT school, coach_name FROM coach_predictions WHERE entry_id = ?");
                        $stmt->execute([$entry_id_to_edit]);
                        $old_coach_picks = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                        
                        // 1b. Get old bonus points
                        $old_bonus_points = (int)$existing_entry['bonus_points'];
                        // --- END FIX ---

                        // 2. Delete the old entry (ON DELETE CASCADE handles the rest)
                        $delete_stmt = $db->prepare("DELETE FROM entries WHERE id = ?");
                        $delete_stmt->execute([$entry_id_to_edit]);
                        $db->commit();
                        
                        // 3. Unset the auth flag
                        unset($_SESSION['auth_entry_id']);
                        
                        // 4. Proceed as a new user (with the data they just submitted)
                        $_SESSION['email'] = $email;
                        $_SESSION['nickname'] = $nickname;
                        $_SESSION['wildcards'] = $wildcards;
                        
                        // 5. Store the old data in the session for step2.php
                        $_SESSION['old_coach_picks'] = $old_coach_picks;
                        $_SESSION['old_bonus_points'] = $old_bonus_points;
                        
                        header('Location: step2.php');
                        exit;
                    } catch (Exception $e) {
                        $db->rollBack();
                        $error = 'An error occurred while overwriting. Please try again.';
                    }
                    
                } else {
                    // User is not authorized. Send them a link.
                    // This logic is in index.php now, so we just clear the session
                    // in case they got here by pressing "Back".
                    unset($_SESSION['new_user_email']);
                    header('Location: index.php?error=existing_email');
                    exit;
                }
            } else {
                // --- NEW PLAYER LOGIC ---
                // Email is new. Proceed as normal.
                $_SESSION['email'] = $email;
                $_SESSION['nickname'] = $nickname;
                $_SESSION['wildcards'] = $wildcards;
                // Make sure old bonus points aren't set for new users
                unset($_SESSION['old_bonus_points']); 
                header('Location: step2.php');
                exit;
            }
        }
    }
}

// --- Check for session data to pre-fill the form ---
// This happens after a user clicks the magic link from auth.php
// OR if they are a new user from index.php
$entry_email = $_SESSION['email'] ?? $_SESSION['new_user_email'] ?? $_POST['email'] ?? '';
$entry_nickname = $_SESSION['nickname'] ?? $_POST['nickname'] ?? '';
$entry_wildcards = $_SESSION['wildcards'] ?? []; // This is now loaded by auth.php

// If the email field is empty, they don't belong here. Send to start.
if (empty($entry_email)) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coaching Carousel Game - Step 1</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 32px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 18px;
        }
        .step-indicator {
            background: #f0f0f0;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 30px;
            font-weight: 600;
            color: #667eea;
        }
        .info-box {
            background: #e8f4f8;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .info-box h3 {
            margin-bottom: 10px;
            color: #333;
        }
        .info-box ul {
            margin-left: 20px;
        }
        .info-box li {
            margin: 5px 0;
            color: #555;
        }
        .scoring-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 4px;
        }
        .scoring-box h3 {
            margin-bottom: 10px;
            color: #333;
            font-size: 16px;
        }
        .scoring-box p {
            color: #555;
            margin: 5px 0;
            font-size: 14px;
        }
        .scoring-box strong {
            color: #28a745;
        }
        .scoring-box .negative {
            color: #dc3545;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        input[type="email"], input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            margin-bottom: 20px;
            transition: border-color 0.3s;
        }
        /* Make email read-only for editing users */
        input[type="email"][readonly] {
            background: #f0f0f0;
            cursor: not-allowed;
        }

        input[type="email"]:focus, input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }
        .wildcard-section {
            margin: 30px 0;
        }
        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .filter-btn {
            padding: 10px 20px;
            border: 2px solid #667eea;
            background: white;
            color: #667eea;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        .filter-btn:hover {
            background: #f0f0f0;
        }
        .filter-btn.active {
            background: #667eea;
            color: white;
        }
        .school-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }
        .school-option {
            position: relative;
        }
        .school-option input[type="checkbox"] {
            position: absolute;
            opacity: 0;
        }
        .school-option label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px;
            background: #f8f9fa;
            border: 2px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
            font-weight: 500;
            min-height: 60px;
        }
        .school-logo {
            flex-shrink: 0;
        }
        .school-option input[type="checkbox"]:checked + label {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .school-option label:hover {
            border-color: #667eea;
        }
        .selection-count {
            text-align: center;
            margin: 15px 0;
            font-weight: 600;
            color: #667eea;
            font-size: 18px;
        }
        .error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .submit-btn {
            background: #667eea;
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 6px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: background 0.3s;
        }
        .submit-btn:hover {
            background: #5568d3;
        }
        .submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏈 Coaching Carousel Game</h1>
        <p class="subtitle">Predict the college football coaching moves!</p>
        
        <div class="step-indicator">
            <?php if (isset($_SESSION['auth_entry_id'])): ?>
                STEP 1 of 2: Review and Edit Your Picks
            <?php else: ?>
                STEP 1 of 2: Select Your Wild Card Schools
            <?php endif; ?>
        </div>
        
        <div class="info-box">
            <h3>Current Openings (Everyone Gets These 8):</h3>
            <ul>
                <?php foreach ($EXISTING_OPENINGS as $school): ?>
                    <li><?= htmlspecialchars($school) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        
        <div class="scoring-box">
            <h3>💰 Wild Card Scoring:</h3>
            <p><strong>+100 points</strong> if your Wild Card school has an opening</p>
            <p><span class="negative">-50 points</span> if your Wild Card school does NOT have an opening</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (GAME_ACTIVE): ?>
        
            <form method="POST" id="gameForm">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" required 
                       value="<?= htmlspecialchars($entry_email) ?>"
                       placeholder="your.email@example.com"
                       <?php if (isset($_SESSION['auth_entry_id'])) echo 'readonly'; ?>>
                
                <label for="nickname">Nickname / Display Name *</label>
                <input type="text" id="nickname" name="nickname" required 
                       value="<?= htmlspecialchars($entry_nickname) ?>"
                       placeholder="Coach Smith" maxlength="100">
                
                <div class="wildcard-section">
                    <label>Select 4 Wild Card Schools (Jobs You Think Will Open) *</label>
                    
                    <div class="filter-buttons">
                        <button type="button" class="filter-btn active" data-conference="all">All Schools</button>
                        <button type="button" class="filter-btn" data-conference="acc">ACC</button>
                        <button type="button" class="filter-btn" data-conference="sec">SEC</button>
                        <button type="button" class="filter-btn" data-conference="bigten">Big Ten</button>
                        <button type="button" class="filter-btn" data-conference="big12">Big 12</button>
                        <button type="button" class="filter-btn" data-conference="independent">Independent</button>
                    </div>
                    
                    <div class="selection-count">
                        <span id="count">0</span> / 4 selected
                    </div>
                    
                    <div class="school-grid">
                        <?php 
                        $conferences = [
                            'acc' => ['Boston College', 'California', 'Clemson', 'Duke', 'Florida State', 'Georgia Tech', 'Louisville', 'Miami', 'NC State', 'North Carolina', 'Pittsburgh', 'SMU', 'Syracuse', 'Virginia', 'Wake Forest'],
                            'sec' => ['Alabama', 'Auburn', 'Georgia', 'Kentucky', 'Mississippi State', 'Missouri', 'Oklahoma', 'Ole Miss', 'South Carolina', 'Tennessee', 'Texas', 'Texas A&M', 'Vanderbilt'],
                            'bigten' => ['Illinois', 'Indiana', 'Iowa', 'Maryland', 'Michigan', 'Michigan State', 'Minnesota', 'Nebraska', 'Northwestern', 'Ohio State', 'Oregon', 'Purdue', 'Rutgers', 'USC', 'Washington', 'Wisconsin'],
                            'big12' => ['Arizona', 'Arizona State', 'Baylor', 'BYU', 'Cincinnati', 'Colorado', 'Houston', 'Iowa State', 'Kansas', 'Kansas State', 'TCU', 'Texas Tech', 'UCF', 'Utah', 'West Virginia'],
                            'independent' => ['Notre Dame']
                        ];
                        
                        foreach ($conferences as $conf => $schools):
                            foreach ($schools as $school):
                        ?>
                                <div class="school-option" data-conference="<?= $conf ?>">
                                    <input type="checkbox" 
                                           name="wildcards[]" 
                                           value="<?= htmlspecialchars($school) ?>"
                                           id="school_<?= htmlspecialchars(str_replace(' ', '_', $school)) ?>"
                                           <?= in_array($school, $entry_wildcards) ? 'checked' : '' ?>>
                                    <label for="school_<?= htmlspecialchars(str_replace(' ', '_', $school)) ?>">
                                        <?= displayLogo($school, 30) ?>
                                        <span><?= htmlspecialchars($school) ?></span>
                                    </label>
                                </div>
                        <?php 
                            endforeach;
                        endforeach; 
                        ?>
                    </div>
                </div>
                
                <button type="submit" class="submit-btn" id="submitBtn">Continue to Step 2 →</button>
            </form>

        <?php else: ?>
            <div class="info-box" style="background: #fff3cd; border-color: #ffc107; text-align: center;">
                <h3 style="font-size: 24px;">Submissions Are Closed</h3>
                <p style="font-size: 16px; margin-top: 10px; line-height: 1.6;">
                    The game is now locked. No new entries are allowed. 
                    Good luck! <br>You can check the <a href="leaderboard.php" style="color: #667eea; font-weight: 600;">Leaderboard</a> 
                    to see the results as they come in.
                </p>
            </div>
        <?php endif; ?>

    </div>
    
    <script>
        const checkboxes = document.querySelectorAll('input[type="checkbox"]');
        const countDisplay = document.getElementById('count');
        const submitBtn = document.getElementById('submitBtn');
        const filterBtns = document.querySelectorAll('.filter-btn');
        const schoolOptions = document.querySelectorAll('.school-option');
        
        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const conference = btn.dataset.conference;
                
                filterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                schoolOptions.forEach(option => {
                    if (conference === 'all' || option.dataset.conference === conference) {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                });
            });
        });
        
        function updateCount() {
            const checked = document.querySelectorAll('input[type="checkbox"]:checked').length;
            countDisplay.textContent = checked;
            
            if (checked === 4) {
                if (submitBtn) submitBtn.disabled = false;
                countDisplay.style.color = '#28a745';
            } else {
                if (submitBtn) submitBtn.disabled = true;
                countDisplay.style.color = checked > 4 ? '#c33' : '#667eea';
            }
            
            checkboxes.forEach(cb => {
                if (!cb.checked && checked >= 4) {
                    cb.disabled = true;
                    cb.parentElement.style.opacity = '0.5';
                } else {
                    cb.disabled = false;
                    cb.parentElement.style.opacity = '1';
                }
            });
        }
        
        checkboxes.forEach(cb => cb.addEventListener('change', updateCount));
        // Run on page load to pre-check the count
        updateCount();
    </script>
</body>
</html>
