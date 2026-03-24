<?php

session_start();

date_default_timezone_set('UTC');

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;


$config = array(
        'app_name' => 'Teams Meeting Responder',
        'session_key' => 'uploaded_meeting_event',
        'mail' => array(
                'mode' => 'smtp',
                'from_email' => envValue('SMTP_FROM_EMAIL', 'no-reply@example.com'),
                'from_name' => envValue('SMTP_FROM_NAME', 'Teams Meeting Responder'),
                'smtp_host' => envValue('SMTP_HOST', 'smtp'),
                'smtp_port' => (int) envValue('SMTP_PORT', '25'),
                'smtp_secure' => '',
                'smtp_auth' => false,
                'smtp_username' => '',
                'smtp_password' => ''
        )
);

$errorMessage = '';
$successMessage = '';
$event = isset($_SESSION[$config['session_key']]) ? $_SESSION[$config['session_key']] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['upload_ics'])) {
            if (!isset($_FILES['ics_file'])) {
                throw new Exception('Please choose an ICS file.');
            }

            if ($_FILES['ics_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('File upload failed.');
            }

            $fileName = isset($_FILES['ics_file']['name']) ? $_FILES['ics_file']['name'] : '';
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($extension !== 'ics') {
                throw new Exception('Only .ics files are allowed.');
            }

            $content = file_get_contents($_FILES['ics_file']['tmp_name']);
            if ($content === false) {
                throw new Exception('Unable to read uploaded ICS file.');
            }

            $event = parseIcsContent($content);

            $_SESSION[$config['session_key']] = $event;
            $successMessage = 'Meeting invitation loaded successfully.';
        } elseif (isset($_POST['meeting_action'])) {
            if (!$event) {
                throw new Exception('No meeting loaded. Please upload an ICS file first.');
            }

            $action = $_POST['meeting_action'];

            if ($action === 'approve') {
                $status = 'ACCEPTED';
            } elseif ($action === 'reject') {
                $status = 'DECLINED';
            } else {
                throw new Exception('Unknown action.');
            }

            sendMeetingResponse($config['mail'], $event, $status);

            if ($status === 'ACCEPTED') {
                $successMessage = 'Meeting approved and response email sent.';
            } else {
                $successMessage = 'Meeting rejected and response email sent.';
            }

            unset($_SESSION[$config['session_key']]);
            $event = null;
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}


function envValue($name, $default)
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function sendMeetingResponse($mailConfig, $event, $responseStatus)
{
    $responseStatus = strtoupper($responseStatus);

    if ($responseStatus !== 'ACCEPTED' && $responseStatus !== 'DECLINED') {
        throw new Exception('Invalid response status.');
    }

    $mail = new PHPMailer(true);

    try {
        if ($mailConfig['mode'] === 'smtp') {
            $mail->isSMTP();
            $mail->Host = $mailConfig['smtp_host'];
            $mail->Port = (int) $mailConfig['smtp_port'];
            $mail->SMTPAuth = !empty($mailConfig['smtp_auth']);

            if (!empty($mailConfig['smtp_secure'])) {
                $mail->SMTPSecure = $mailConfig['smtp_secure'];
            }

            if (!empty($mailConfig['smtp_username'])) {
                $mail->Username = $mailConfig['smtp_username'];
            }

            if (!empty($mailConfig['smtp_password'])) {
                $mail->Password = $mailConfig['smtp_password'];
            }
        }

        $fromEmail = getDetectedFromEmail($event, $mailConfig);
        $fromName = getDetectedFromName($event, $mailConfig);

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);
        $mail->addAddress($event['organizer_email'], $event['organizer_name']);
        $mail->Subject = ($responseStatus === 'ACCEPTED' ? 'Accepted: ' : 'Declined: ') . $event['summary'];

        $mail->Body = buildPlainTextResponse($event, $responseStatus);
        $mail->AltBody = $mail->Body;

        $calendarReply = buildCalendarReply($mailConfig, $event, $responseStatus);
        $mail->addStringAttachment(
                $calendarReply,
                'meeting-response.ics',
                'base64',
                'text/calendar; method=REPLY; charset=UTF-8; component=VEVENT'
        );

        $mail->send();

        return true;
    } catch (PHPMailerException $e) {
        throw new Exception('Failed to send response email: ' . $e->getMessage());
    }
}


function parseIcsContent($content)
{
    $content = preg_replace("/(\r\n|\n|\r)[ \t]/", '', $content);
    $lines = preg_split("/\r\n|\n|\r/", $content);

    $event = array(
            'uid' => '',
            'summary' => '',
            'description' => '',
            'location' => '',
            'dtstart' => '',
            'dtend' => '',
            'organizer_email' => '',
            'organizer_name' => '',
            'attendee_email' => '',
            'attendee_name' => '',
            'sequence' => '0',
            'raw' => $content
    );

    $insideEvent = false;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === 'BEGIN:VEVENT') {
            $insideEvent = true;
            continue;
        }

        if ($line === 'END:VEVENT') {
            $insideEvent = false;
            break;
        }

        if (!$insideEvent) {
            continue;
        }

        if (strpos($line, 'UID') === 0) {
            $event['uid'] = getIcsValue($line);
        } elseif (strpos($line, 'SUMMARY') === 0) {
            $event['summary'] = decodeIcsText(getIcsValue($line));
        } elseif (strpos($line, 'DESCRIPTION') === 0) {
            $event['description'] = decodeIcsText(getIcsValue($line));
        } elseif (strpos($line, 'LOCATION') === 0) {
            $event['location'] = decodeIcsText(getIcsValue($line));
        } elseif (strpos($line, 'DTSTART') === 0) {
            $event['dtstart'] = getIcsValue($line);
        } elseif (strpos($line, 'DTEND') === 0) {
            $event['dtend'] = getIcsValue($line);
        } elseif (strpos($line, 'SEQUENCE') === 0) {
            $event['sequence'] = getIcsValue($line);
        } elseif (strpos($line, 'ORGANIZER') === 0) {
            $event['organizer_email'] = extractIcsEmail($line);
            $event['organizer_name'] = extractIcsCn($line);
        } elseif (strpos($line, 'ATTENDEE') === 0 && $event['attendee_email'] === '') {
            $event['attendee_email'] = extractIcsEmail($line);
            $event['attendee_name'] = extractIcsCn($line);
        }
    }

    if ($event['uid'] === '') {
        throw new Exception('ICS file is missing UID.');
    }

    if ($event['organizer_email'] === '') {
        throw new Exception('ICS file is missing organizer email.');
    }

    if ($event['summary'] === '') {
        $event['summary'] = '(No subject)';
    }

    return $event;
}

function getIcsValue($line)
{
    $parts = explode(':', $line, 2);
    return isset($parts[1]) ? trim($parts[1]) : '';
}

function extractIcsEmail($line)
{
    if (preg_match('/mailto:([^\s]+)/i', $line, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function extractIcsCn($line)
{
    if (preg_match('/CN=([^;:]+)/i', $line, $matches)) {
        return trim($matches[1], '"');
    }

    return '';
}

function decodeIcsText($value)
{
    $value = str_replace('\n', "\n", $value);
    $value = str_replace('\N', "\n", $value);
    $value = str_replace('\,', ',', $value);
    $value = str_replace('\;', ';', $value);
    $value = str_replace('\\\\', '\\', $value);

    return $value;
}

function getDetectedFromEmail($event, $mailConfig)
{
    if (!empty($event['attendee_email'])) {
        return $event['attendee_email'];
    }

    return $mailConfig['from_email'];
}

function getDetectedFromName($event, $mailConfig)
{
    if (!empty($event['attendee_name'])) {
        return $event['attendee_name'];
    }

    return $mailConfig['from_name'];
}

function buildPlainTextResponse($event, $responseStatus)
{
    $decision = $responseStatus === 'ACCEPTED' ? 'approved' : 'rejected';

    return implode("\n", array(
            'Meeting response',
            '',
            'Subject: ' . $event['summary'],
            'Organizer: ' . $event['organizer_email'],
            'Start: ' . $event['dtstart'],
            'End: ' . $event['dtend'],
            '',
            'The meeting was ' . $decision . '.'
    ));
}

function buildCalendarReply($mailConfig, $event, $responseStatus)
{
    $attendeeEmail = $event['attendee_email'] !== '' ? $event['attendee_email'] : $mailConfig['from_email'];
    $attendeeName = $event['attendee_name'] !== '' ? $event['attendee_name'] : $mailConfig['from_name'];
    $organizerName = $event['organizer_name'] !== '' ? $event['organizer_name'] : $event['organizer_email'];

    $lines = array(
            'BEGIN:VCALENDAR',
            'PRODID:-//ICS Acknowledgement Agent//ICS ACK 1.0//EN',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:REPLY',
            'BEGIN:VEVENT',
            'UID:' . escapeIcsText($event['uid']),
            'SUMMARY:' . escapeIcsText($event['summary']),
            'DTSTART:' . $event['dtstart'],
            'DTEND:' . $event['dtend'],
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'SEQUENCE:' . escapeIcsText($event['sequence']),
            'ORGANIZER;CN=' . escapeIcsParam($organizerName) . ':mailto:' . $event['organizer_email'],
            'ATTENDEE;CN=' . escapeIcsParam($attendeeName) . ';PARTSTAT=' . $responseStatus . ';RSVP=FALSE;ROLE=REQ-PARTICIPANT:mailto:' . $attendeeEmail,
            'END:VEVENT',
            'END:VCALENDAR'
    );

    return implode("\r\n", $lines) . "\r\n";
}

function escapeIcsText($value)
{
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(';', '\;', $value);
    $value = str_replace(',', '\,', $value);
    $value = str_replace("\r\n", '\n', $value);
    $value = str_replace("\n", '\n', $value);
    $value = str_replace("\r", '\n', $value);

    return $value;
}

function escapeIcsParam($value)
{
    return '"' . str_replace('"', '\"', $value) . '"';
}

function escapeHtml($value)
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatDateValue($value)
{
    if ($value === '') {
        return '';
    }

    if (preg_match('/^(\d{8})T(\d{6})Z$/', $value, $matches)) {
        $date = DateTime::createFromFormat('Ymd His', $matches[1] . ' ' . $matches[2], new DateTimeZone('UTC'));
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d H:i:s') . ' UTC';
        }
    }

    if (preg_match('/^(\d{8})T(\d{6})$/', $value, $matches)) {
        $date = DateTime::createFromFormat('Ymd His', $matches[1] . ' ' . $matches[2]);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    if (preg_match('/^(\d{8})$/', $value, $matches)) {
        $date = DateTime::createFromFormat('Ymd', $matches[1]);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    return $value;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo escapeHtml($config['app_name']); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
            background: #f5f7fb;
            color: #222;
        }

        .container {
            max-width: 780px;
            margin: 0 auto;
            background: #fff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
        }

        h1, h2 {
            margin-top: 0;
        }

        .message {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 16px;
        }

        .message.error {
            background: #fde7e7;
            color: #8a1f1f;
        }

        .message.success {
            background: #e8f6ea;
            color: #1f6b31;
        }

        .meeting-details {
            margin-top: 24px;
            padding: 16px;
            background: #f8fafc;
            border: 1px solid #d9e2ec;
            border-radius: 6px;
        }

        .meeting-details dt {
            font-weight: bold;
            margin-top: 12px;
        }

        .meeting-details dd {
            margin: 4px 0 0 0;
        }

        .actions {
            margin-top: 20px;
        }

        .actions button,
        .upload-box button {
            padding: 10px 18px;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
            margin-right: 12px;
            font-size: 14px;
        }

        .approve {
            background: #1f883d;
            color: #fff;
        }

        .reject {
            background: #cf222e;
            color: #fff;
        }

        .upload-box {
            margin-top: 18px;
        }

        .upload-box input[type="file"] {
            margin-right: 10px;
        }

        .hint {
            color: #5f6b7a;
            font-size: 13px;
            margin-top: 10px;
        }

        code {
            background: #f1f3f5;
            padding: 2px 5px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1><?php echo escapeHtml($config['app_name']); ?></h1>

    <p>Upload a Microsoft Teams meeting invitation in <strong>.ics</strong> format, inspect the details, and then approve or reject the meeting.</p>

    <?php if ($errorMessage !== ''): ?>
        <div class="message error"><?php echo escapeHtml($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
        <div class="message success"><?php echo escapeHtml($successMessage); ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <div class="upload-box">
            <input type="file" name="ics_file" accept=".ics">
            <button type="submit" name="upload_ics" value="1">Upload ICS</button>
        </div>
        <div class="hint">
            Remember to update SMTP settings before expecting email magic.
        </div>
    </form>

    <?php if ($event): ?>
        <div class="meeting-details">
            <h2>Meeting Details</h2>
            <dl>
                <dt>Subject</dt>
                <dd><?php echo escapeHtml($event['summary']); ?></dd>

                <dt>Organizer</dt>
                <dd>
                    <?php echo escapeHtml($event['organizer_name'] !== '' ? $event['organizer_name'] : $event['organizer_email']); ?>
                    (<?php echo escapeHtml($event['organizer_email']); ?>)
                </dd>

                <dt>Attendee</dt>
                <dd>
                    <?php echo escapeHtml($event['attendee_name'] !== '' ? $event['attendee_name'] : $event['attendee_email']); ?>
                    <?php if ($event['attendee_email'] !== ''): ?>
                        (<?php echo escapeHtml($event['attendee_email']); ?>)
                    <?php endif; ?>
                </dd>

                <dt>Start</dt>
                <dd><?php echo escapeHtml(formatDateValue($event['dtstart'])); ?></dd>

                <dt>End</dt>
                <dd><?php echo escapeHtml(formatDateValue($event['dtend'])); ?></dd>

                <dt>Location</dt>
                <dd><?php echo nl2br(escapeHtml($event['location'])); ?></dd>

                <dt>Description</dt>
                <dd><?php echo nl2br(escapeHtml($event['description'])); ?></dd>

                <dt>UID</dt>
                <dd><code><?php echo escapeHtml($event['uid']); ?></code></dd>
            </dl>

            <form method="post" class="actions">
                <button type="submit" name="meeting_action" value="approve" class="approve">Approve</button>
                <button type="submit" name="meeting_action" value="reject" class="reject">Reject</button>
            </form>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
