<?php
// Landingpage-Vorschau für das iframe im Editor, ausgegeben von
// handle_landingpage_preview(). $body ist rohes HTML wie im Frontend, das
// Schließen-Tag im CSS wird wie in index.php entschärft.
if (!defined('CMS_ENTRY')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<?php if ($page_style !== ''): ?>
<style><?= str_ireplace('</style', '<\/style', $page_style) ?></style>
<?php endif; ?>
</head>
<body>
<?= $body ?>
</body>
</html>
