<?php
declare(strict_types=1);

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

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$idea = trim((string)($_POST['idea'] ?? ''));

if ($name === '' || $email === '' || $phone === '' || $idea === '') {
    respond(400, ['error' => 'Vul alle verplichte velden in.']);
}
if (strlen($name) > 480 || strlen($email) > 254 || strlen($phone) > 160 || strlen($idea) > 24000) {
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
        respond(400, ['error' => 'Upload een STL- of 3MF-bestand van maximaal 10 MB.']);
    }
    $originalName = (string)$upload['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['stl', '3mf'], true)) {
        respond(400, ['error' => 'Upload alleen een STL- of 3MF-bestand.']);
    }
    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));
    $attachments[] = [$upload['tmp_name'], substr($safeName ?: 'model.' . $extension, 0, 160)];
}

/*
 * Use PHP's built-in mail() so no Composer, vendor/ directory, or PHPMailer
 * installation is required. The web host must have outgoing PHP mail enabled.
 */
$contactTo = 'ben@justpetrov.com';
$fromAddress = 'automail@justpetrov.com';
$subject = '[!] Nieuwe 3D-printaanvraag';

$safeName = str_replace(["\r", "\n"], '', $name);
$boundary = 'ptrv_' . bin2hex(random_bytes(16));
$headers = [
    'From' => "Petrov's Workshop <{$fromAddress}>",
    'Reply-To' => str_replace(["\r", "\n"], '', $email),
    'MIME-Version' => '1.0',
    'Content-Type' => 'multipart/mixed; boundary="' . $boundary . '"',
    'X-Mailer' => 'PHP/' . PHP_VERSION,
];

$bodyText = "Nieuwe aanvraag via de website\n\nNaam: {$name}\nE-mail: {$email}\nTelefoonnummer: {$phone}\n\nIdee / opdracht:\n{$idea}";
$html = '<!doctype html><html><body style="margin:0;background:#050505;color:#eeeeee;font-family:Arial,sans-serif">'
    . '<div style="margin:0 auto;max-width:700px;background:#050505;color:#eeeeee;border:1px solid #292318">';
$headerImage = __DIR__ . '/mail-header.png';
$footerImage = __DIR__ . '/mail-footer.png';
$inlineImages = [];
if (is_file($headerImage)) {
    $inlineImages[] = [$headerImage, 'ptrv-mail-header', 'mail-header.png'];
    $html .= '<img src="cid:ptrv-mail-header" alt="Petrov" style="display:block;width:100%;height:auto;border:0">';
}
$html .= '<div style="padding:24px"><h2 style="color:#d1ae60;margin:0 0 18px">Nieuwe 3D-printaanvraag</h2>'
    . '<p><strong>Naam:</strong> ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
    . '<p><strong>E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
    . '<p><strong>Telefoonnummer:</strong> ' . htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
    . '<p><strong>Idee / opdracht:</strong><br>' . nl2br(htmlspecialchars($idea, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p></div>';
if (is_file($footerImage)) {
    $inlineImages[] = [$footerImage, 'ptrv-mail-footer', 'mail-footer.png'];
    $html .= '<img src="cid:ptrv-mail-footer" alt="Petrov — automail@justpetrov.com — PTRV.nl" style="display:block;width:100%;height:auto;border:0">';
}
$html .= '</div></body></html>';

$body = '';
if ($inlineImages) {
    $relatedBoundary = 'ptrv_related_' . bin2hex(random_bytes(12));
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: multipart/related; boundary=\"{$relatedBoundary}\"\r\n\r\n";
    $body .= "--{$relatedBoundary}\r\n";
    $body .= "Content-Type: multipart/alternative; boundary=\"{$relatedBoundary}_alt\"\r\n\r\n";
    $body .= "--{$relatedBoundary}_alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$bodyText}\r\n";
    $body .= "--{$relatedBoundary}_alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n";
    $body .= "--{$relatedBoundary}_alt--\r\n";
    foreach ($inlineImages as [$path, $cid, $filename]) {
        $mime = 'image/png';
        $data = @file_get_contents($path);
        if ($data === false) continue;
        $body .= "--{$relatedBoundary}\r\nContent-Type: {$mime}; name=\"{$filename}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\nContent-ID: <{$cid}>\r\nContent-Disposition: inline; filename=\"{$filename}\"\r\n\r\n";
        $body .= chunk_split(base64_encode($data)) . "\r\n";
    }
    $body .= "--{$relatedBoundary}--\r\n";
} else {
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: multipart/alternative; boundary=\"{$boundary}_alt\"\r\n\r\n";
    $body .= "--{$boundary}_alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$bodyText}\r\n";
    $body .= "--{$boundary}_alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n";
    $body .= "--{$boundary}_alt--\r\n";
}

foreach ($attachments as [$path, $filename]) {
    $data = @file_get_contents($path);
    if ($data === false) {
        respond(500, ['error' => 'Het bijgevoegde bestand kon niet worden gelezen.']);
    }
    $body .= "--{$boundary}\r\n";
    $body .= 'Content-Type: application/octet-stream; name="' . $filename . "\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= 'Content-Disposition: attachment; filename="' . $filename . "\"\r\n\r\n";
    $body .= chunk_split(base64_encode($data)) . "\r\n";
}
$body .= "--{$boundary}--\r\n";

$headerString = '';
foreach ($headers as $key => $value) {
    $headerString .= $key . ': ' . $value . "\r\n";
}

try {
    $sent = mail($contactTo, $subject, $body, $headerString);
} catch (Throwable $e) {
    error_log('PTRV contact mail error: ' . $e->getMessage());
    $sent = false;
}
if (!$sent) {
    error_log('PTRV contact: PHP mail() failed to hand off the message.');
    respond(500, ['error' => 'Versturen is niet gelukt. De mailserver van de hosting heeft het bericht niet aangenomen.']);
}
respond(200, ['ok' => true]);
