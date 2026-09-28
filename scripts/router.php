<?php
// Router for the local PHP development server; production rules are in README.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('~(?:^|/)(?:\.|backup_|config(?:/|$)|lib(?:/|$)|scripts(?:/|$)|tests(?:/|$))|\.(?:sql|zip|log|md|ini)$~i',$path) || preg_match('~^/uploads/.*\.(?:php\d?|phtml|phar)$~i',$path)) {
    http_response_code(404); exit;
}
return false;
