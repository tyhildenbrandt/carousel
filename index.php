<?php
require_once 'config.php';

$error = '';
$success = '';

// Check if we are loading this page from a magic link
// We set this in auth.php
if (isset($_SESSION['load_success'])) {
    $success = $_SESSION['load_success'];
    unset($_SESSION['load_success']);
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
            
            // Check if user is a RETURNING PLAYER
            if ($existing_entry) {
                // --- SEND MAGIC LINK LOGIC ---
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
                        <html>
                        <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
                            <h2>Edit Your Picks</h2>
                            <p>We received a request to edit the picks associated with this email address.</p>
                            <p>To proceed, please click the link below. This link is valid for 15 minutes.</p>
                            <p>
                                <a href='{$link}' style='background: #667eea; color: white; padding: 12px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                                    Click Here to Edit Your Picks
                                </a>
                            </p>
                            <p>If you did not request this, you can safely ignore this email.</p>
                        </body>
                        </html>
                    ";
                    $headers = "MIME-Version: 1.0" . "\r\n";
                    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                    $headers .= 'From: noreply@solidverbal.com' . "\r\n"; // <-- **** REPLACE THIS ****

                    if (mail($email, $subject, $message, $headers)) {
                        $success = "We found your entry! An edit link has been sent to {$email}. Please check your inbox.";
                    } else {
                        $error = "We found your entry, but could not send an email. Please contact support.";
                    }
                    
                } catch (Exception $e) {
                    $error = "A database error occurred. " . $e->getMessage();
                }

            } else {
                // --- NEW PLAYER LOGIC ---
                // Email is new. Save email to session and send to create page.
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
    <title>Coaching Carousel Game - Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .container {
            max-width: 500px;
            width: 100%;
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
            text-align: center;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 18px;
            text-align: center;
        }
        .info-box {
            background: #e8f4f8;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 4px;
            font-size: 15px;
            line-height: 1.6;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        input[type="email"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            margin-bottom: 20px;
            transition: border-color 0.3s;
        }
        input[type="email"]:focus {
            outline: none;
            border-color: #667eea;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🏈 Coaching Carousel</h1>
        <p class="subtitle">Login or Create Your Entry</p>
        
        <div class="info-box">
            <strong>New Players:</strong> Enter your email to begin.<br>
            <strong>Returning Players:</strong> Enter your email to receive a magic link to edit your picks.
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if (GAME_ACTIVE): ?>
            <form method="POST">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" required 
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="your.email@example.com">
                
                <button type="submit" class="submit-btn">Continue →</button>
            </form>
        <?php else: ?>
            <div class="info-box" style="background: #fff3cd; border-color: #ffc107; text-align: center;">
                <h3 style="font-size: 24px;">Submissions Are Closed</h3>
                <p style="font-size: 16px; margin-top: 10px; line-height: 1.6;">
                    The game is now locked. No new entries are allowed. 
                    Good luck! <br>You can check the <a href="leaderboard.php" style="color: #667eea; font-weight: 600;">Leaderboard</a> 
                    to see the results.
                </p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
