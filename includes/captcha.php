<?php
/**
 * Simple CAPTCHA generator (no external dependencies)
 * Generates a simple math question and stores the answer in session.
 * Also draws a simple image with the question text.
 */

function captcha_generate(): string
{
    $a = random_int(1, 20);
    $b = random_int(1, 20);
    $ops = ['+', '-'];
    $op = $ops[array_rand($ops)];
    
    if ($op === '-' && $b > $a) {
        // swap so result is always positive
        [$a, $b] = [$b, $a];
    }
    
    $question = toPersianDigits($a) . ' ' . $op . ' ' . toPersianDigits($b) . ' = ?';
    $answer = (string) ($op === '+' ? $a + $b : $a - $b);
    
    $_SESSION['captcha_answer'] = $answer;
    $_SESSION['captcha_time'] = time();
    
    return $question;
}

function captcha_verify(string $userAnswer): bool
{
    if (empty($_SESSION['captcha_answer']) || empty($_SESSION['captcha_time'])) {
        return false;
    }
    // Expire after 5 minutes
    if (time() - $_SESSION['captcha_time'] > 300) {
        unset($_SESSION['captcha_answer']);
        return false;
    }
    $correct = $_SESSION['captcha_answer'];
    unset($_SESSION['captcha_answer']);
    
    // Normalize Persian/Arabic digits
    $userAnswer = normalizePersianDigits(trim($userAnswer));
    
    return $userAnswer === $correct;
}

/**
 * Check if CAPTCHA is needed based on recent failed attempts
 */
function captcha_needed(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts 
         WHERE ip_address = ? AND success = 0 
         AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $stmt->execute([$ip]);
    $failedCount = (int) $stmt->fetchColumn();
    
    // Show CAPTCHA after 3 failed attempts
    return $failedCount >= 3;
}

/**
 * Record a login attempt (success or failure)
 */
function captcha_record_attempt(bool $success): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt = db()->prepare('INSERT INTO login_attempts (ip_address, success, attempted_at) VALUES (?, ?, NOW())');
    $stmt->execute([$ip, $success ? 1 : 0]);
    
    // Cleanup old records (older than 24 hours)
    db()->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}
