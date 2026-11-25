<?php
declare(strict_types=1);

/**
 * Mailversand aus admin.php: Einladungen, Passwort-Reset und
 * Update-Benachrichtigungen, alle über den konfigurierten SMTP-Zugang.
 *
 * Braucht SITE_URL, SITE_DOMAIN und load_config() aus admin.php sowie
 * create_user_token() aus lib/admin/auth.php, alles erst zur Laufzeit.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Sendet eine E-Mail über den konfigurierten SMTP-Zugang an eine beliebige
 * Adresse — gleicher PHPMailer-Aufbau wie send_contact_email() in index.php,
 * aber ohne addReplyTo: der Absender ist immer die eigene smtp_from_email.
 *
 * Schlägt der Versand fehl (SMTP nicht konfiguriert/nicht erreichbar), gibt
 * die Funktion false zurück, statt einen Fatal Error zu werfen — der Aufrufer
 * entscheidet dann fail-closed weiter (Spec 5.4).
 */
function send_user_email(array $cfg, string $to_email, string $subject, string $body): bool {
    if (empty($cfg['smtp_host']) || empty($cfg['smtp_user']) || empty($cfg['smtp_pass']) || empty($cfg['smtp_from_email'])) {
        return false;
    }
    require_once dirname(__DIR__) . '/phpmailer/Exception.php';
    require_once dirname(__DIR__) . '/phpmailer/PHPMailer.php';
    require_once dirname(__DIR__) . '/phpmailer/SMTP.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['smtp_user'];
        $mail->Password   = $cfg['smtp_pass'];
        $mail->SMTPSecure = $cfg['smtp_secure'];
        $mail->Port       = (int)$cfg['smtp_port'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($cfg['smtp_from_email'], $cfg['smtp_from_name']);
        $mail->addAddress($to_email);

        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Prüft, ob SITE_URL für einen sicherheitsrelevanten Link (Passwort-Reset,
 * Einladung) vertrauenswürdig genug ist — nur wenn site_domain konfiguriert
 * ist oder der Request von localhost/127.0.0.1 kommt (lokale Entwicklung
 * ohne gesetzte Domain, analog is_local_host in index.php). Ohne diese
 * Prüfung würde admin.php sonst einen Link aus dem — zwar bereinigten, aber
 * vom Request steuerbaren — Host-Header verschicken (L9/L10-Nachtrag).
 *
 * Bewusst NUR am Mailversand geprüft, nicht am SITE_URL-Fallback selbst
 * (anders als in index.php): admin.php muss auch ohne gesetzte site_domain
 * erreichbar bleiben, sonst wäre der Betreiber genau von der Einstellungs-
 * Seite ausgesperrt, auf der er die Domain nachtragen müsste.
 */
function site_url_trustworthy_for_mail(): bool {
    if (SITE_DOMAIN !== '') {
        return true;
    }
    return preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $_SERVER['HTTP_HOST'] ?? '') === 1;
}

/**
 * Verschickt (oder verschickt erneut) die Einladungs-Mail für einen
 * bestehenden Nutzer (Spec 5.4). Text unterscheidet sich leicht vom
 * "Passwort vergessen"-Flow in handle_forgot_password_request() ("Zugang
 * eingerichtet, lege dein Passwort fest" statt "Zurücksetzen angefordert"),
 * nutzt aber denselben Token- und Link-Mechanismus (create_user_token()).
 *
 * Schreibt den Token *vor* dem Versandversuch und räumt ihn bei einem
 * Fehlschlag bewusst nicht wieder weg — anders als beim "Passwort
 * vergessen"-Flow, der fail-closed bleibt (Token wird dort bei
 * Mailversand-Fehlschlag sofort verworfen). Hier unkritisch, weil den Token
 * mangels verschickter Mail niemand kennt, und ein erneuter Aufruf (Button
 * "Einladung erneut senden") ihn ohnehin überschreibt.
 */
function send_invite_mail(array $user): bool {
    if (!site_url_trustworthy_for_mail()) {
        return false;
    }
    $token = create_user_token($user['id']);
    if ($token === null) {
        return false;
    }
    $cfg  = load_config();
    $site = $cfg['site_title'] !== '' ? $cfg['site_title'] : 'diese Website';
    $link = SITE_URL . '/admin.php?action=reset_password&token=' . $token;
    $subject = 'Zugang zu ' . $site;
    $body = "Für dich wurde ein Zugang zu {$site} angelegt.\n\n"
        . "Lege dein Passwort fest (Link 1 Stunde gültig): {$link}\n\n"
        . 'Hast du das nicht erwartet, ignoriere diese Mail — es passiert nichts von selbst.';
    return send_user_email($cfg, $user['email'], $subject, $body);
}

/**
 * Legt einen neuen Nutzer an und verschickt die Einladungs-Mail (Spec 5.2/5.4).
 *
 * Scheitert der Mailversand, bleibt der Account trotzdem bestehen (Spec 8
 * Punkt 2, v0.3) — anders als beim ursprünglichen
 * Passwort-Reset ist das hier kein Sicherheitsrisiko: ohne Passwort ist der
 * Account ohnehin nicht nutzbar. Der Tab "Benutzer" bietet für genau diesen
 * Fall "Einladung erneut senden".
 *
 * @return array{ok: true, user: array, mail_sent: bool}|array{ok: false, code: string, error: string}
 */
function invite_user(string $email, string $role): array {
    $created = cms_create_user($email, $role);
    if (!$created['ok']) {
        return $created;
    }
    $created['mail_sent'] = send_invite_mail($created['user']);
    return $created;
}
