<?php
declare(strict_types=1);

/**
 * POST-Handler für den Tab "Redirects": Anlegen, Löschen.
 *
 * Jeder Handler prüft die Rolle 'redirects' über guard() selbst — die Formulare posten ohne
 * ?action=redirects in der URL auf admin.php.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

function handle_create_redirect(): HandlerResult {
    $error = guard('redirects');
    if ($error !== null) {
        return HandlerResult::failure($error, 'redirects');
    }
    // Existierende Slugs (inkl. Entwürfe) für die Kollisionsprüfung in
    // cms_create_redirect() — ein Redirect darf keine Seite verdecken,
    // auch keine noch unveröffentlichte, die demnächst live geht.
    $existing_slugs = array_map(static fn(array $p): string => $p['slug'], cms_list_pages(true));
    $me = current_user();
    $result = cms_create_redirect(
        post_str('redirect_from'),
        post_str('redirect_to'),
        $existing_slugs,
        $me['email'] ?? 'unbekannt'
    );
    if (!$result['ok']) {
        return HandlerResult::failure($result['error'], 'redirects');
    }
    return HandlerResult::success(
        'Redirect angelegt.' . ($result['warning'] !== null ? ' Hinweis: ' . $result['warning'] : ''),
        'redirects'
    );
}

function handle_delete_redirect(): HandlerResult {
    $error = guard('redirects');
    if ($error !== null) {
        return HandlerResult::failure($error, 'redirects');
    }
    $result = cms_delete_redirect(post_str('redirect_id'));
    return $result['ok']
        ? HandlerResult::success('Redirect gelöscht.', 'redirects')
        : HandlerResult::failure($result['error'], 'redirects');
}
