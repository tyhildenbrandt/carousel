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
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 1rem;
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .hero-section {
            background: white;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        h1 {
            color: #333;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .subtitle {
            font-size: 1.25rem;
            color: #555;
            margin-bottom: 1.5rem;
        }
        .intro-text {
            font-size: 1.1rem;
            color: #444; /* Darker text for readability */
            line-height: 1.6;
            margin-bottom: 2rem;
            text-align: left; /* Better for paragraphs */
        }
        .intro-text p {
            margin-bottom: 1em; /* Space between paragraphs */
        }

        /* Login Box */
        .login-box {
            background: #f8f9fa;
            padding: 2rem;
            border-radius: 8px;
            border: 1px solid #ddd;
        }
        .login-box h2 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            text-align: center;
        }
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .form-group label {
            font-weight: 600;
            font-size: 1rem;
            text-align: left;
        }
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ccc;
            border-radius: 6px;
            font-size: 1rem;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .submit-btn {
            background: #667eea;
            color: white;
            padding: 15px;
            border: none;
            border-radius: 6px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .submit-btn:hover {
            background: #5568d3;
        }
        .error {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 12px;
            border-radius: 6px;
        }
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
        }

        /* Explainer Sections */
        .explainer-section {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            margin-top: 2rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .explainer-section h2 {
            font-size: 1.75rem;
            margin-bottom: 1.5rem;
            border-bottom: 3px solid #667eea;
            padding-bottom: 0.5rem;
        }
        
        /* How to Play */
        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }
        .step {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
        }
        .step h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: #667eea;
        }
        .step p {
            font-size: 0.95rem;
            line-height: 1.5;
            color: #555;
        }

        /* Scoring */
        .rules-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        .rule {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #ffc107;
        }
        .rule h3 {
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        .rule ul {
            list-style: none;
            padding-left: 0;
            color: #555;
            font-size: 0.95rem;
        }
        .rule li {
            position: relative;
            padding-left: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .rule li::before {
            content: '»';
            position: absolute;
            left: 0;
            top: 0;
            color: #667eea;
            font-weight: 700;
        }
        .rule .points {
            font-weight: 700;
        }
        .rule .points-pos { color: #28a745; }
        .rule .points-neg { color: #dc3545; }

    </style>
</head>
<body>

    <div class="container">
        
        <!-- Hero and Login Box -->
        <div class="hero-section">
            <h1>🏈 The Coaching Carousel Game</h1>
            <p class="subtitle">The ultimate college football coaching prediction game.</p>
            
            <!-- This section has been updated -->
            <div class="intro-text">
                <p>The 2025 college football coaching carousel figures to be one of the craziest ever. This game, the first of its kind (that we know of), is built to have some fun with the madness.</p>
                <p>First, you'll be asked to pick which <em>other</em> jobs ("Wild Cards") you think will come open by season's end. Next, you'll need to identify which coaches you think will take the open gigs. You'll be awarded points for correctly guessing jobs that come open, coaches that take new jobs, and especially if you guess the eventual match.</p>
            </div>
            <!-- End updated section -->

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
