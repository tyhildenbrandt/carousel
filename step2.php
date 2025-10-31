<?php
require_once 'config.php';

// Check if Step 1 was completed
if (!isset($_SESSION['email']) || !isset($_SESSION['wildcards'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $coaches = $_POST['coaches'] ?? [];
    
    // Validate all 12 positions have coaches
    $allSchools = array_merge($EXISTING_OPENINGS, $_SESSION['wildcards']);
    $allFilled = true;
    
    foreach ($allSchools as $school) {
        if (empty(trim($coaches[$school] ?? ''))) {
            $allFilled = false;
            break;
        }
    }
    
    if (!$allFilled) {
        $error = 'Please enter a coach name for all 12 schools.';
    } else {
        // --- NEW: Calculate Bonus Points ---
        $bonus_podcast = (int)($_POST['bonus_podcast'] ?? 0);
        $bonus_youtube = (int)($_POST['bonus_youtube'] ?? 0);
        $bonus_newsletter = isset($_POST['bonus_newsletter']) ? 1 : 0;
        
        // Calculate total bonus
        $total_bonus = 0;
        if ($bonus_podcast === 1) $total_bonus += 20;
        if ($bonus_youtube === 1) $total_bonus += 20;
        if ($bonus_newsletter === 1) $total_bonus += 10;
        // --- END NEW ---

        try {
            $db = getDB();
            $db->beginTransaction();
            
            // --- MODIFIED: Add bonus_points to the insert query ---
            $stmt = $db->prepare("INSERT INTO entries (email, nickname, bonus_points) VALUES (?, ?, ?)");
            $stmt->execute([$_SESSION['email'], $_SESSION['nickname'], $total_bonus]);
            $entryId = $db->lastInsertId();
            
            // Insert wildcard picks
            $stmt = $db->prepare("INSERT INTO wildcard_picks (entry_id, school) VALUES (?, ?)");
            foreach ($_SESSION['wildcards'] as $school) {
                $stmt->execute([$entryId, $school]);
            }
            
            // Insert coach predictions
            $stmt = $db->prepare("INSERT INTO coach_predictions (entry_id, school, coach_name, is_wildcard) VALUES (?, ?, ?, ?)");
            
            // Existing openings
            foreach ($EXISTING_OPENINGS as $school) {
                $stmt->execute([$entryId, $school, trim($coaches[$school]), 0]);
            }
            
            // Wildcard picks
            foreach ($_SESSION['wildcards'] as $school) {
                $stmt->execute([$entryId, $school, trim($coaches[$school]), 1]);
            }
            
            $db->commit();
            
            // Clear session and redirect to confirmation
            $_SESSION['confirmation'] = [
                'nickname' => $_SESSION['nickname'],
                'email' => $_SESSION['email'],
                'entry_id' => $entryId
            ];
            unset($_SESSION['email'], $_SESSION['nickname'], $_SESSION['wildcards']);
            
            header('Location: confirmation.php');
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'An error occurred saving your entry. Please try again.';
        }
    }
}

$allSchools = array_merge($EXISTING_OPENINGS, $_SESSION['wildcards']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coaching Carousel Game - Step 2</title>
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
            font-size: 32px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 20px;
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
        .user-info {
            background: #e8f4f8;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .user-info strong {
            color: #333;
        }
        .section {
            margin-bottom: 40px;
        }
        .section h2 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 3px solid #667eea;
        }
        .coach-input-group {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 15px;
            align-items: center;
            margin-bottom: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        .coach-input-group.wildcard {
            background: #fff8e1;
            border: 2px dashed #ffa726;
        }
        .wildcard-badge {
            display: none;
        }
        .school-name {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            color: #333;
            font-size: 16px;
            min-height: 44px;
        }
        .school-name img {
            display: block;
            flex-shrink: 0;
            vertical-align: middle;
        }
        .school-name > span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            line-height: 1;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
            position: relative;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
        }
        .autocomplete-container {
            position: relative;
        }
        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #667eea;
            border-top: none;
            border-radius: 0 0 6px 6px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .autocomplete-suggestions.show {
            display: block;
        }
        .autocomplete-item {
            padding: 10px 12px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .autocomplete-item:hover,
        .autocomplete-item.selected {
            background: #f0f0f0;
        }
        .autocomplete-item strong {
            color: #667eea;
        }
        .error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        .btn {
            padding: 15px 40px;
            border: none;
            border-radius: 6px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-primary {
            background: #667eea;
            color: white;
            flex: 1;
        }
        .btn-primary:hover {
            background: #5568d3;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover {
            background: #5a6268;
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

        /* --- BONUS SECTION CSS --- */
        .bonus-section {
            background: #f8f9fa;
            border: 2px solid #ddd;
            padding: 25px;
            border-radius: 8px;
        }
        .bonus-item {
            display: grid;
            grid-template-columns: 1fr 100px;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        .bonus-item-desc {
            line-height: 1.5;
        }
        .bonus-item-desc strong {
            color: #333;
            font-size: 16px;
        }
        .bonus-item-desc p {
            font-size: 14px;
            color: #555;
        }
        .bonus-links {
            display: flex;
            gap: 10px;
            margin-top: 5px;
        }
        .bonus-links a {
            font-weight: 600;
            color: #667eea;
            text-decoration: none;
        }
        .bonus-links a:hover {
            text-decoration: underline;
        }
        .bonus-status {
            font-weight: 600;
            text-align: right;
            color: #dc3545; /* Red */
        }
        .bonus-status.completed {
            color: #28a745; /* Green */
        }
        .newsletter-check {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #fff;
            padding: 10px;
            border-radius: 6px;
        }
        .newsletter-check input {
            width: 18px;
            height: 18px;
        }
        .newsletter-check label {
            margin: 0;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏈 Coaching Carousel Game</h1>
        <p class="subtitle">Now predict which coaches will take each job!</p>
        
        <div class="step-indicator">STEP 2 of 2: Predict Coaching Hires</div>
        
        <div class="user-info">
            <strong>Email:</strong> <?= htmlspecialchars($_SESSION['email']) ?><br>
            <strong>Nickname:</strong> <?= htmlspecialchars($_SESSION['nickname']) ?>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="section">
                <h2>Existing Openings (8 Schools)</h2>
                <div class="scoring-box">
                    <h3>💰 Scoring:</h3>
                    <p><strong>+200 points</strong> for correct coach at correct school</p>
                    <p><strong>+100 points</strong> if your coach takes a different P4 job (partial credit)</p>
                </div>
                <?php foreach ($EXISTING_OPENINGS as $school): ?>
                    <div class="coach-input-group">
                        <div class="school-name">
                            <?= displayLogo($school, 40) ?>
                            <span><?= htmlspecialchars($school) ?></span>
                        </div>
                        <div class="autocomplete-container">
                            <input type="text" 
                                   name="coaches[<?= htmlspecialchars($school) ?>]" 
                                   class="coach-input"
                                   placeholder="Enter coach name"
                                   value="<?= htmlspecialchars($_POST['coaches'][$school] ?? '') ?>"
                                   autocomplete="off"
                                   required>
                            <div class="autocomplete-suggestions"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="section">
                <h2>Your Wild Card Picks (4 Schools)</h2>
                <div class="scoring-box">
                    <h3>💰 Scoring:</h3>
                    <p><strong>+300 points</strong> for correct coach at correct school (jackpot!)</p>
                    <p><strong>+150 points</strong> if school opens but coach takes different P4 job</p>
                    <p><strong>+100 points</strong> if school doesn't open but coach takes a P4 job</p>
                </div>
                <?php foreach ($_SESSION['wildcards'] as $school): ?>
                    <div class="coach-input-group wildcard">
                        <div class="school-name">
                            <?= displayLogo($school, 40) ?>
                            <span><?= htmlspecialchars($school) ?></span>
                        </div>
                        <div class="autocomplete-container">
                            <input type="text" 
                                   name="coaches[<?= htmlspecialchars($school) ?>]" 
                                   class="coach-input"
                                   placeholder="Enter coach name"
                                   value="<?= htmlspecialchars($_POST['coaches'][$school] ?? '') ?>"
                                   autocomplete="off"
                                   required>
                            <div class="autocomplete-suggestions"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- === BONUS SECTION === -->
            <div class="section bonus-section">
                <h2>🎁 Get 50 Bonus Points!</h2>
                
                <!-- Podcast Item -->
                <div class="bonus-item">
                    <div class="bonus-item-desc">
                        <strong>Follow the Podcast (+20 pts)</strong>
                        <p>Click to follow on your favorite app.</p>
                        <div class="bonus-links">
                            <a href="https://apple.co/solidverbal" target="_blank" class="bonus-link" data-type="podcast">Apple</a>
                            <span>|</span>
                            <a href="https://open.spotify.com/show/0MABqnjJ8GlteE1Ql9xOEs?si=vKos4dmzSNyIrSfrwDgpVw" target="_blank" class="bonus-link" data-type="podcast">Spotify</a>
                        </div>
                    </div>
                    <div class="bonus-status" id="podcast-status">Pending</div>
                </div>

                <!-- YouTube Item -->
                <div class="bonus-item">
                    <div class="bonus-item-desc">
                        <strong>Subscribe on YouTube (+20 pts)</strong>
                        <p>Click to subscribe to the channel.</p>
                        <div class="bonus-links">
                             <a href="https://www.youtube.com/@solidverbal?sub_confirmation=1" target="_blank" class="bonus-link" data-type="youtube">YouTube</a>
                        </div>
                    </div>
                    <div class="bonus-status" id="youtube-status">Pending</div>
                </div>
                
                <!-- Newsletter Item -->
                <div class="bonus-item">
                    <div class="bonus-item-desc">
                        <strong>Join the Newsletter (+10 pts)</strong>
                        <p>Check the box to get the best CFB news.</p>
                    </div>
                    <div class="newsletter-check">
                        <input type="checkbox" name="bonus_newsletter" id="bonus_newsletter" value="1">
                        <label for="bonus_newsletter">Sign Up!</label>
                    </div>
                </div>

                <!-- Hidden inputs to track clicks -->
                <input type="hidden" name="bonus_podcast" id="bonus_podcast" value="0">
                <input type="hidden" name="bonus_youtube" id="bonus_youtube" value="0">
            </div>
            <!-- === END BONUS SECTION === -->
            
            <div class="button-group">
                <a href="index.php" class="btn btn-secondary">← Back</a>
                <button type="submit" class="btn btn-primary">Submit My Predictions →</button>
            </div>
        </form>
    </div>
    
    <script>
        // Load coach list for autocomplete
        let coaches = [];
        
        fetch('coaches.json')
            .then(response => response.json())
            .then(data => {
                coaches = data.coaches;
                initAutocomplete();
            })
            .catch(error => {
                console.log('Coach autocomplete not available:', error);
            });
        
        function initAutocomplete() {
            const inputs = document.querySelectorAll('.coach-input');
            
            inputs.forEach(input => {
                const container = input.parentElement;
                const suggestions = container.querySelector('.autocomplete-suggestions');
                let selectedIndex = -1;
                
                input.addEventListener('input', function() {
                    const value = this.value.toLowerCase().trim();
                    suggestions.innerHTML = '';
                    selectedIndex = -1;
                    
                    if (value.length < 2) {
                        suggestions.classList.remove('show');
                        return;
                    }
                    
                    const matches = coaches.filter(coach => 
                        coach.toLowerCase().includes(value)
                    ).slice(0, 10);
                    
                    if (matches.length > 0) {
                        matches.forEach((coach, index) => {
                            const div = document.createElement('div');
                            div.className = 'autocomplete-item';
                            const regex = new RegExp(`(${value})`, 'gi');
                            div.innerHTML = coach.replace(regex, '<strong>$1</strong>');
                            
                            div.addEventListener('click', function() {
                                input.value = coach;
                                suggestions.classList.remove('show');
                            });
                            suggestions.appendChild(div);
                        });
                        
                        suggestions.classList.add('show');
                    } else {
                        suggestions.classList.remove('show');
                    }
                });
                
                // Keyboard navigation
                input.addEventListener('keydown', function(e) {
                    const items = suggestions.querySelectorAll('.autocomplete-item');
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                        updateSelection(items);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        selectedIndex = Math.max(selectedIndex - 1, 0);
                        updateSelection(items);
                    } else if (e.key === 'Enter' && selectedIndex >= 0) {
                        e.preventDefault();
                        items[selectedIndex].click();
                    } else if (e.key === 'Escape') {
                        suggestions.classList.remove('show');
                    }
                });
                
                function updateSelection(items) {
                    items.forEach((item, index) => {
                        if (index === selectedIndex) {
                            item.classList.add('selected');
                            item.scrollIntoView({ block: 'nearest' });
                        } else {
                            item.classList.remove('selected');
                        }
                    });
                }
                
                document.addEventListener('click', function(e) {
                    if (!container.contains(e.target)) {
                        suggestions.classList.remove('show');
                    }
                });
            });
        }

        // --- BONUS SCRIPT ---
        document.addEventListener('DOMContentLoaded', function() {
            const links = document.querySelectorAll('.bonus-link');
            
            links.forEach(link => {
                link.addEventListener('click', function(e) {
                    const type = e.target.dataset.type;
                    
                    if (type === 'podcast') {
                        document.getElementById('bonus_podcast').value = '1';
                        const statusEl = document.getElementById('podcast-status');
                        statusEl.textContent = '✅ Done!';
                        statusEl.classList.add('completed');
                    } 
                    else if (type === 'youtube') {
                        document.getElementById('bonus_youtube').value = '1';
                        const statusEl = document.getElementById('youtube-status');
                        statusEl.textContent = '✅ Done!';
                        statusEl.classList.add('completed');
                    }
                });
            });
        });
    </script>
</body>
</html>

