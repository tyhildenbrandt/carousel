<?php
require_once 'config.php';

if (!isset($_SESSION['confirmation'])) {
    header('Location: index.php');
    exit;
}

$nickname = $_SESSION['confirmation']['nickname'];
unset($_SESSION['confirmation']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submission Confirmed - Coaching Carousel Game</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            background: white;
            padding: 50px 40px;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        .success-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 15px;
            font-size: 32px;
        }
        .message {
            color: #666;
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .info-box {
            background: #e8f4f8;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: left;
        }
        .info-box h3 {
            color: #333;
            margin-bottom: 10px;
        }
        .info-box ul {
            margin-left: 20px;
            color: #555;
        }
        .info-box li {
            margin: 8px 0;
        }
        .btn {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 15px 40px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 18px;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">✅</div>
        <h1>Predictions Submitted!</h1>
        <p class="message">
            Thanks, <strong><?= htmlspecialchars($nickname) ?></strong>! Your predictions have been recorded.
        </p>
        
        <div class="info-box">
            <h3>What Happens Next?</h3>
            <ul>
                <li>Your predictions are locked in and cannot be changed</li>
                <li>As coaching moves happen, scores will be calculated automatically</li>
                <li>Check the leaderboard to see how you stack up against other players</li>
                <li>Points are awarded based on correct predictions</li>
            </ul>
        </div>
        
        <a href="leaderboard.php" class="btn">View Leaderboard →</a>
    </div>
</body>
</html>
