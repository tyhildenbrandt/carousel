<?php
// index.php
require_once 'config.php';

$error = '';
$success = '';

// Check for error/success messages from redirects
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'existing_email') {
        $error = 'That email is already in use. Please enter your email again to receive a magic link to edit your picks.';
    }
}
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'sent') {
        $success = 'Magic link sent! Please check your email inbox (and spam folder) to continue.';
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!GAME_ACTIVE) {
        $error = 'Submissions are currently closed.';
    } else {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id FROM entries WHERE email = ?");
            $stmt->execute([$email]);
            $existing_entry = $stmt->fetch();
            
            if ($existing_entry) {
                // --- RETURNING PLAYER: SEND MAGIC LINK ---
                try {
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    $entry_id = $existing_entry['id'];

                    $stmt = $db->prepare("INSERT INTO auth_tokens (entry_id, token, expires_at) VALUES (?, ?, ?)");
                    $stmt->execute([$entry_id, $token, $expires]);

                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                    $host = $_SERVER['HTTP_HOST'];
                    $path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                    $link = "{$protocol}://{$host}{$path}/auth.php?token={$token}";

                    $subject = "Edit Your Coaching Carousel Picks";
                    $message = "
                        <html><body style='font-family: Arial, sans-serif; line-height: 1.6;'>
                            <h2>Edit Your Picks</h2>
                            <p>We received a request to edit the picks associated with this email address.</p>
                            <p>To proceed, please click the link below. This link is valid for 15 minutes.</p>
                            <p>
                                <a href='{$link}' style='background: #667eea; color: white; padding: 12px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                                    Click Here to Edit Your Picks
                                </a>
                            </p>
                            <p>If you did not request this, you can safely ignore this email.</p>
                        </body></html>
                    ";
                    $headers = "MIME-Version: 1.0" . "\r\n";
                    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                    $headers .= 'From: noreply@solidverbal.com' . "\r\n"; // Make sure this is a valid sending email

                    if (mail($email, $subject, $message, $headers)) {
                        header('Location: index.php?success=sent');
                        exit;
                    } else {
                        $error = "We found your entry, but could not send an email. Please contact support.";
                    }
                    
                } catch (Exception $e) {
                    $error = "A database error occurred.";
                }

            } else {
                // --- NEW PLAYER: GO TO STEP 1 ---
                $_SESSION['new_user_email'] = $email;
                header('Location: create_entry.php');
                exit;
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
    <title>Coaching Carousel Game</title>
    <!-- Linked the external stylesheet -->
    <link rel="stylesheet" href="styles.css">
    
    <!-- This override block fixes the login box width -->
    <style>
        .hero-section .login-box {
            width: auto; /* Resets the 300px width from admin style */
            margin: 0;   /* Resets the margin: 0 auto */
        }
    </style>
</head>
<body>

    <!-- This page uses the default container width (800px) -->
    <div class="container">
        
        <!-- Hero and Login Box -->
        <div class="hero-section">
            <h1>🏈 The Coaching Carousel Game</h1>
            <p class="subtitle">The ultimate college football coaching prediction game.</p>
            
            <div class="placeholder-text" style="text-align: left;">
                <p>The 2025 college football coaching carousel figures to be one of the craziest ever. This game, the first of its kind (that we know of), is built to have some fun with the madness.</p>
                <p style="margin-top: 1rem;">First, you'll be asked to pick which <em>other</em> jobs ("Wild Cards") you think will come open by season's end. Next, you'll need to identify which coaches you think will take the open gigs. You'll be awarded points for correctly guessing jobs that come open, coaches that take new jobs, and especially if you guess the eventual match.</p>
            </div>

            <div class="login-box">
                <h2>Play Now</h2>
                
                <?php if ($error): ?>
                    <div class="error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <form method="POST" class="login-form">
                    <div class="form-group">
                        <label for="email">Enter Your Email to Play or Edit:</label>
                        <input type="email" id="email" name="email" required placeholder="your.email@example.com">
                    </div>
                    <button type="submit" class="submit-btn">
                        <?php echo (GAME_ACTIVE) ? 'Continue' : 'Submissions Closed'; ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- How It Works Section -->
        <div class="explainer-section">
            <h2>How to Play</h2>
            <div class="steps-grid">
                <div class="step">
                    <h3>1. Enter Your Email</h3>
                    <p>Enter your email above. If you're new, you'll create a new entry. If you're a returning player, we'll send a "magic link" to your inbox to let you edit your picks.</p>
                </div>
                <div class="step">
                    <h3>2. Pick Your Wildcards</h3>
                    <p>You get the 8 current openings for free. Your first challenge is to predict which 4 <strong>other</strong> P4 schools will also have a head coaching vacancy this season.</p>
                </div>
                <div class="step">
                    <h3>3. Predict the Hires</h3>
                    <p>Fill out the board. You'll predict the new head coach for all 12 of your openings (the 8 existing jobs + your 4 wildcards). Score points for every correct prediction!</p>
                </div>
            </div>
        </div>

        <!-- Scoring Rules Section -->
        <div class="explainer-section">
            <h2>How to Score Points</h2>
            <div class="rules-grid">
                <div class="rule">
                    <h3>Wild Card School Picks (Step 1)</h3>
                    <ul>
                        <li><span class="points points-pos">+100 pts</span> for correctly picking a school that opens.</li>
                        <li><span class="points points-neg">-50 pts</span> for picking a school that does NOT open.</li>
                    </ul>
                </div>
                <div class="rule">
                    <h3>Existing Opening Hires (Step 2)</h3>
                    <ul>
                        <li><span class="points points-pos">+200 pts</span> for the <strong>correct coach</strong> at the <strong>correct school</strong>.</li>
                        <li><span class="points points-pos">+100 pts</span> if your coach takes a different P4 job (partial credit).</li>
                    </ul>
                </div>
                <div class="rule">
                    <h3>Wildcard Hires (Step 2)</h3>
                    <ul>
                        <li><span class="points points-pos">+300 pts</span> for the <strong>correct coach</strong> at the <strong>correct school</strong> (Jackpot!).</li>
                        <li><span class="points points-pos">+150 pts</span> if your wildcard school opens, but you get the coach wrong.</li>
                        <li><span class="points points-pos">+100 pts</span> if your school *doesn't* open, but your predicted coach takes another P4 job.</li>
                    </ul>
                </div>
                 <div class="rule">
                    <h3>Bonus Points (New Entries Only)</h3>
                    <ul>
                        <li><span class="points points-pos">+50 pts</span> total for following the podcast, subscribing on YouTube, and joining the newsletter.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</body>
</html>

