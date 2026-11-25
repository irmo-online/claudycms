<?php
declare(strict_types=1);

/**
 * POST-Handler für "Mein Profil" und den Tab "Benutzer".
 *
 * Jeder Handler gibt ein HandlerResult zurück, admin.php ruft ihn über die
 * Handler-Map auf (admin_post_handlers()).
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

// ─── "Mein Profil": eigene E-Mail + eigenes Passwort (Spec 5.3) ────────────
// Eigene Seite für jede Rolle (action=profile), deshalb guard(null) — die
// Route ist für jeden eingeloggten Nutzer legitim, jeder ändert nur sich
// selbst (current_user()). Website-/SMTP-/Sicherheits-Felder gehören
// inzwischen zu "Website & SEO" bzw. "Einstellungen" und tauchen hier gar
// nicht erst auf.
function handle_save_profile(): HandlerResult {
    $error = guard(null);
    if ($error !== null) {
        return HandlerResult::failure($error, 'profile');
    }

    $me = current_user();
    $cur_pw = post_str('current_password');

    // E-Mail ändern (optional) — verlangt wie das Passwort-Ändern unten
    // das aktuelle Passwort zur Bestätigung (Spec 5.3).
    $new_email = trim(post_str('profile_email'));
    $email_changed = $new_email !== '' && strtolower($new_email) !== strtolower($me['email']);
    if ($email_changed) {
        if (!password_verify($cur_pw, (string)($me['password_hash'] ?? ''))) {
            return HandlerResult::failure('Aktuelles Passwort ist falsch.', 'profile');
        }
        $email_result = cms_update_user_email($me['id'], $new_email);
        if (!$email_result['ok']) {
            return HandlerResult::failure($email_result['error'], 'profile');
        }
    }

    // Passwort ändern (optional) — identische Regeln wie im vollen
    // Einstellungen-Formular.
    $new_pw = post_str('new_password');
    $password_changed = false;
    if ($new_pw !== '') {
        $new_pw2 = post_str('new_password_confirm');
        if (!password_verify($cur_pw, (string)($me['password_hash'] ?? ''))) {
            return HandlerResult::failure('Aktuelles Passwort ist falsch.', 'profile');
        }
        if ($new_pw !== $new_pw2) {
            return HandlerResult::failure('Neues Passwort und Bestätigung stimmen nicht überein.', 'profile');
        }
        if (strlen($new_pw) < 12) {
            return HandlerResult::failure('Das neue Passwort muss mindestens 12 Zeichen lang sein.', 'profile');
        }
        if (!cms_set_user_password($me['id'], cms_hash_password($new_pw))) {
            return HandlerResult::failure('Passwort konnte nicht gespeichert werden.', 'profile');
        }
        $password_changed = true;
    }

    if (!$email_changed && !$password_changed) {
        return HandlerResult::failure('Keine Änderung eingegeben.', 'profile');
    }
    return HandlerResult::success('Profil aktualisiert.', 'profile');
}

// Eigenen MCP-Connector-Token neu generieren (Spec 5.3) — jeder eingeloggte
// Nutzer für sich selbst, unabhängig von der Rolle. Anders als
// regenerate_user_token unten (nur admin, für beliebige Nutzer) kein
// Rollen-Recht nötig: hier kann niemand einen fremden Token treffen,
// user_id kommt nicht aus dem Formular, sondern aus current_user().
function handle_regenerate_own_token(): HandlerResult {
    $error = guard(null);
    if ($error !== null) {
        return HandlerResult::failure($error, 'profile');
    }
    $me = current_user();
    $new_token = $me !== null ? cms_regenerate_user_mcp_token($me['id']) : null;
    if ($new_token === null) {
        return HandlerResult::failure('Token konnte nicht erzeugt werden.', 'profile');
    }
    return HandlerResult::success(
        'Neuer MCP-Token: ' . rtrim(SITE_URL, '/') . '/mcp.php/' . $new_token
            . ' — jetzt kopieren, er wird nicht erneut angezeigt.',
        'profile'
    );
}

// ─── Tab "Benutzer": Einladen, Sperren/Entsperren, Löschen, Token (Spec 5.2) ─
//
// Jeder Handler prüft die Rolle 'users' über guard() selbst (Spec 6.2) —
// nicht nur der zentrale $action==='users'-Check in admin.php, weil diese
// POST-Ziele wie die anderen Aktions-Handler ohne ?action=users in der URL
// auskommen (das Formular postet auf admin.php).

function handle_invite_user(): HandlerResult {
    $error = guard('users');
    if ($error !== null) {
        return HandlerResult::failure($error, 'users');
    }
    $result = invite_user(post_str('invite_email'), post_str('invite_role', Role::ContentCreator->value));
    if (!$result['ok']) {
        return HandlerResult::failure($result['error'], 'users');
    }
    return HandlerResult::success(
        $result['mail_sent']
            ? 'Nutzer angelegt. Einladungs-Mail verschickt.'
            : 'Nutzer angelegt, aber die Einladungs-Mail konnte nicht verschickt werden. Über "Einladung erneut senden" in der Liste erneut versuchen.',
        'users'
    );
}

// Einladung erneut senden ODER Passwort manuell zurücksetzen (Spec 5.2) —
// serverseitig identisch: beides erzeugt einen frischen Token und verschickt
// denselben Link, nur der Button-Text im Tab unterscheidet sich je nachdem,
// ob der Nutzer schon ein Passwort hat.
function handle_send_user_token(): HandlerResult {
    $error = guard('users');
    if ($error !== null) {
        return HandlerResult::failure($error, 'users');
    }
    $target = cms_find_user_by_id(post_str('user_id'));
    if ($target === null) {
        return HandlerResult::failure('Nutzer nicht gefunden.', 'users');
    }
    return send_invite_mail($target)
        ? HandlerResult::success('Link an ' . $target['email'] . ' verschickt.', 'users')
        : HandlerResult::failure('E-Mail-Versand ist aktuell nicht möglich. Bitte versuch es später erneut.', 'users');
}

// MCP-Token regenerieren, auch für andere Nutzer (Spec 5.2). Anders als beim
// eigenen Connector-Token unter "Mein Profil" gibt es keine Mail an sich
// selbst — der Admin sieht den neuen Link direkt hier und gibt ihn manuell
// weiter. Es wird nur der Hash gespeichert (lib/users.php), der Klartext ist
// nach dieser einen Anzeige nicht wiederherstellbar.
function handle_regenerate_user_token(): HandlerResult {
    $error = guard('users');
    if ($error !== null) {
        return HandlerResult::failure($error, 'users');
    }
    $target    = cms_find_user_by_id(post_str('user_id'));
    $new_token = $target !== null ? cms_regenerate_user_mcp_token($target['id']) : null;
    if ($new_token === null) {
        return HandlerResult::failure('Token konnte nicht erzeugt werden.', 'users');
    }
    return HandlerResult::success(
        'Neuer MCP-Token für ' . $target['email'] . ': '
            . rtrim(SITE_URL, '/') . '/mcp.php/' . $new_token
            . ' — jetzt kopieren, er wird nicht erneut angezeigt.',
        'users'
    );
}

// Sperren/Entsperren — cms_set_user_status() verweigert das Sperren des
// letzten Admin-Accounts selbst (Spec 6.5), hier keine doppelte Prüfung nötig.
function handle_toggle_user_status(): HandlerResult {
    $error = guard('users');
    if ($error !== null) {
        return HandlerResult::failure($error, 'users');
    }
    $result = cms_set_user_status(post_str('user_id'), post_str('new_status'));
    return $result['ok']
        ? HandlerResult::success('Status geändert.', 'users')
        : HandlerResult::failure($result['error'], 'users');
}

// Löschen — cms_delete_user() verweigert das Löschen des letzten
// Admin-Accounts selbst (Spec 6.5).
function handle_delete_user(): HandlerResult {
    $error = guard('users');
    if ($error !== null) {
        return HandlerResult::failure($error, 'users');
    }
    $result = cms_delete_user(post_str('user_id'));
    return $result['ok']
        ? HandlerResult::success('Nutzer gelöscht.', 'users')
        : HandlerResult::failure($result['error'], 'users');
}
