<?php
declare(strict_types=1);

/**
 * Ergebnis eines POST-Handlers in admin.php.
 *
 * Jeder Handler gibt genau ein HandlerResult zurück, statt geteilte
 * Variablen ($message, $error, $action) zu setzen. Damit kann kein Handler
 * mehr die Meldung eines anderen überschreiben — früher sah so ein
 * Fehlschlag wie ein Erfolg aus, weil eine spätere Zuweisung die
 * Fehlermeldung verdrängt hat.
 *
 * Bewusst keine `readonly class`: die gibt es erst ab PHP 8.2, die
 * Zielversion ist 8.1. readonly-Properties per Constructor Property
 * Promotion leisten dasselbe.
 */
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}

final class HandlerResult {
    /**
     * @param string|null $action   Tab, der danach gerendert wird. null lässt
     *                              den Tab aus der URL stehen.
     * @param string|null $redirect Ziel, auf das der Browser statt des Renderns
     *                              umgeleitet wird (relativ zu admin.php).
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly string $error = '',
        public readonly ?string $action = null,
        public readonly ?string $redirect = null,
    ) {}

    public static function success(string $message, ?string $action = null): self {
        return new self(true, $message, '', $action);
    }

    /**
     * Erfolg, nach dem die Antwort nicht gerendert, sondern umgeleitet wird.
     * Die Meldung muss das Ziel selbst erzeugen, sie überlebt den Redirect
     * nicht.
     */
    public static function redirect(string $url): self {
        return new self(true, '', '', null, $url);
    }

    public static function failure(string $error, ?string $action = null): self {
        return new self(false, '', $error, $action);
    }

    /**
     * Für Handler, die nur einen Fehlertext oder null kennen (guard(),
     * cfg_update()): null oder leer heißt Erfolg mit $message.
     */
    public static function fromError(?string $error, string $message, ?string $action = null): self {
        return $error === null || $error === ''
            ? self::success($message, $action)
            : self::failure($error, $action);
    }
}
