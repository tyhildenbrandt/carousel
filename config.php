<?php
// --- THIS MUST BE THE VERY FIRST THING ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// --- END SESSION ---


// Database Configuration
// Fill in your SiteGround database credentials

define('DB_HOST', 'localhost'); // Usually 'localhost' on SiteGround
define('DB_NAME', 'mydbname');
define('DB_USER', 'myusername');
define('DB_PASS', 'mypassword');

// Application Settings
define('GAME_ACTIVE', true); // Set to false to close submissions

// Existing openings (these 8 schools)
$EXISTING_OPENINGS = [
    'LSU',
    'Florida', 
    'Penn State',
    'Virginia Tech',
    'Arkansas',
    'Oklahoma State',
    'UCLA',
    'Stanford'
];

// All P4 schools (excluding the 8 current openings: LSU, Florida, Penn State, Virginia Tech, Arkansas, Oklahoma State, UCLA, Stanford)
$ALL_P4_SCHOOLS = [
    // ACC (minus Virginia Tech, Stanford)
    'Boston College', 'California', 'Clemson', 'Duke', 'Florida State',
    'Georgia Tech', 'Louisville', 'Miami', 'NC State', 'North Carolina',
    'Pittsburgh', 'SMU', 'Syracuse', 'Virginia', 'Wake Forest',
    // SEC (minus LSU, Florida, Arkansas)
    'Alabama', 'Auburn', 'Georgia', 'Kentucky', 'Mississippi State',
    'Missouri', 'Oklahoma', 'Ole Miss', 'South Carolina', 'Tennessee',
    'Texas', 'Texas A&M', 'Vanderbilt',
    // Big Ten (minus Penn State, UCLA)
    'Illinois', 'Indiana', 'Iowa', 'Maryland', 'Michigan',
    'Michigan State', 'Minnesota', 'Nebraska', 'Northwestern', 'Ohio State',
    'Oregon', 'Purdue', 'Rutgers', 'USC', 'Washington', 'Wisconsin',
    // Big 12 (minus Oklahoma State)
    'Arizona', 'Arizona State', 'Baylor', 'BYU', 'Cincinnati',
    'Colorado', 'Houston', 'Iowa State', 'Kansas', 'Kansas State',
    'TCU', 'Texas Tech', 'UCF', 'Utah', 'West Virginia',
    // Independent
    'Notre Dame'
];

// Sort alphabetically
sort($ALL_P4_SCHOOLS);

// Logo settings
define('LOGO_DIR', 'logos/'); // Directory where logo PNG files are stored

// Function to get logo path for a school
function getSchoolLogo($schoolName) {
    // Convert school name to filename format
    // Examples: "Penn State" -> "Penn_State-light.png"
    //           "Texas A&M" -> "Texas_AM-light.png"
    
    $filename = str_replace(' ', '_', $schoolName); // Spaces to underscores
    $filename = str_replace('&', '', $filename);    // Remove ampersands
    $filename = $filename . '-light.png';           // Add suffix
    
    $logoPath = LOGO_DIR . $filename;
    
    // Check if file exists, return path or empty string
    if (file_exists($logoPath)) {
        return $logoPath;
    }
    
    return ''; // Return empty if logo doesn't exist
}

// Function to display logo img tag
function displayLogo($schoolName, $size = 40, $classes = '') {
    $logoPath = getSchoolLogo($schoolName);
    
    if (!empty($logoPath)) {
        $alt = htmlspecialchars($schoolName) . ' Logo';
        return '<img src="' . htmlspecialchars($logoPath) . '" 
                     alt="' . $alt . '" 
                     class="school-logo ' . htmlspecialchars($classes) . '" 
                     style="width: ' . (int)$size . 'px; height: ' . (int)$size . 'px; object-fit: contain;">';
    }
    
    return ''; // Return empty if no logo
}

// Database connection function
function getDB() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch(PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}


// --- NEW GLOBAL SCORING FUNCTION ---
/**
 * Recalculates scores for ALL entries.
 */
function calculateAllScores($db) {
    // MODIFIED: Fetch bonus_points along with id
    $entries = $db->query("SELECT id, bonus_points FROM entries")->fetchAll(PDO::FETCH_ASSOC);
    
    // --- Cache all results in an associative array for fast lookup ---
    // Key: School Name => Value: Hired Coach Name
    $allHires = $db->query("SELECT school, hired_coach FROM results WHERE is_filled = 1")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
                   
    // --- Cache all coach destinations ---
    // Key: Coach Name => Value: School Name
    $coachDestinations = $db->query("SELECT hired_coach, school FROM results WHERE is_filled = 1 AND hired_coach IS NOT NULL")
                          ->fetchAll(PDO::FETCH_KEY_PAIR);

    foreach ($entries as $entry) {
        // MODIFIED: Start the total score with the user's bonus points
        $entryId = $entry['id'];
        $totalScore = (int)$entry['bonus_points'];
        
        // === 1. Score Wild Card picks ===
        $wildcards = $db->prepare("SELECT school, opened FROM wildcard_picks WHERE entry_id = ?");
        $wildcards->execute([$entryId]);
        
        $userWildcardPicks = $wildcards->fetchAll();

        foreach ($userWildcardPicks as $wc) {
            $points = 0;
            
            if ($wc['opened'] === null) {
                $points = 0; // Pending
            } elseif ($wc['opened'] == 1) {
                $points = 100; // Correct
            } else { // opened == 0
                $points = -50; // Incorrect
            }
            
            // Update wildcard points in DB
            $db->prepare("UPDATE wildcard_picks SET points = ? WHERE entry_id = ? AND school = ?")
               ->execute([$points, $entryId, $wc['school']]);
            
            $totalScore += $points;
        }
        
        // === 2. Score Coach predictions ===
        $predictions = $db->prepare("SELECT id, school, coach_name, is_wildcard FROM coach_predictions WHERE entry_id = ?");
        $predictions->execute([$entryId]);
        
        foreach ($predictions->fetchAll() as $pred) {
            $points = 0;
            $predictedSchool = $pred['school'];
            $predictedCoach = $pred['coach_name'];
            
            // SCENARIO 1: Exact Match (Correct school, correct coach)
            if (isset($allHires[$predictedSchool]) && strtolower(trim($allHires[$predictedSchool])) === strtolower(trim($predictedCoach))) {
                if ($pred['is_wildcard']) {
                    $points = 300; // Jackpot
                } else {
                    $points = 200; // Existing opening correct
                }
            } 
            // SCENARIO 2: No exact match. Check for Partial Credit.
            // (Did the predicted coach take *any other* P4 job?)
            elseif (isset($coachDestinations[$predictedCoach])) {
                // Yes, the coach *did* move, just to the wrong school.
                
                // Was this an existing opening prediction?
                if (!$pred['is_wildcard']) {
                    $points = 100; // Half credit for existing opening
                } 
                // This was a wildcard prediction.
                else {
                    // We need to know if the user's wildcard school opened.
                    // Find the matching wildcard pick from our earlier fetch.
                    $openedStatus = null;
                    foreach ($userWildcardPicks as $wcPick) {
                        if ($wcPick['school'] === $predictedSchool) {
                            $openedStatus = $wcPick['opened'];
                            break;
                        }
                    }

                    if ($openedStatus == 1) {
                        $points = 150; // Wildcard opened, but wrong coach
                    } else {
                        $points = 100; // Wildcard didn't open, but coach moved
                    }
                }
            }
            // SCENARIO 3: No match, no partial credit. Points remain 0.
            
            // Update the points for this specific prediction
            $db->prepare("UPDATE coach_predictions SET points = ? WHERE id = ?")
               ->execute([$points, $pred['id']]);
               
            $totalScore += $points;
        }
        
        // === 3. Update entry total score ===
        $db->prepare("UPDATE entries SET total_score = ? WHERE id = ?")
           ->execute([$totalScore, $entryId]);
    }
}
?>
