<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Methode niet toegestaan.']);
}

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    error_log('PTRV contact: Composer dependencies are missing.');
    respond(503, ['error' => 'Het contactformulier is nog niet volledig ingesteld.']);
}
require __DIR__ . '/vendor/autoload.php';

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$idea = trim((string)($_POST['idea'] ?? ''));

if ($name === '' || $email === '' || $phone === '' || $idea === '') {
    respond(400, ['error' => 'Vul alle verplichte velden in.']);
}
if (mb_strlen($name) > 120 || strlen($email) > 254 || mb_strlen($phone) > 40 || mb_strlen($idea) > 6000) {
    respond(400, ['error' => 'Een of meer velden zijn te lang.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(400, ['error' => 'Vul een geldig e-mailadres in.']);
}

$maxFileSize = 10 * 1024 * 1024;
$attachments = [];
if (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload = $_FILES['file'];
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        respond(400, ['error' => 'Het bestand kon niet worden geüpload. Controleer of het kleiner is dan 10 MB.']);
    }
    if (!is_uploaded_file($upload['tmp_name']) || $upload['size'] > $maxFileSize) {
        respond(400, ['error' => 'Upload alleen een STL- of 3MF-bestand van maximaal 10 MB.']);
    }
    $originalName = (string)$upload['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['stl', '3mf'], true)) {
        respond(400, ['error' => 'Upload alleen een STL- of 3MF-bestand.']);
    }
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));
    $attachments[] = [$upload['tmp_name'], substr($safeName ?: 'model.' . $extension, 0, 160)];
}

$smtpHost = getenv('SMTP_HOST') ?: '';
$smtpPort = (int)(getenv('SMTP_PORT') ?: '465');
$smtpUser = getenv('SMTP_USER') ?: '';
$smtpPass = getenv('SMTP_PASS') ?: '';
$contactTo = getenv('CONTACT_TO') ?: 'ben@justpetrov.com';

if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '' || !filter_var($contactTo, FILTER_VALIDATE_EMAIL)) {
    error_log('PTRV contact: SMTP_HOST, SMTP_USER, SMTP_PASS or CONTACT_TO is not configured.');
    respond(503, ['error' => 'Het contactformulier is nog niet volledig ingesteld. Probeer het later opnieuw.']);
}

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->Port = $smtpPort;
    $mail->SMTPSecure = $smtpPort === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($smtpUser, "Petrov's Workshop website");
    $mail->addAddress($contactTo);
    $mail->addReplyTo($email, $name);
    $mail->Subject = 'Nieuwe 3D-printaanvraag via PTRV.nl';
    $mail->Body = "Nieuwe aanvraag via de website\n\nNaam: {$name}\nE-mail: {$email}\nTelefoonnummer: {$phone}\n\nIdee / opdracht:\n{$idea}";
    $mail->AltBody = $mail->Body;
    foreach ($attachments as [$path, $filename]) {
        $mail->addAttachment($path, $filename);
    }
    $mail->send();
    respond(200, ['ok' => true]);
} catch (Exception $e) {
    error_log('PTRV contact mail error: ' . $mail->ErrorInfo);
    respond(500, ['error' => 'Versturen is niet gelukt. Probeer het later opnieuw.']);
}
