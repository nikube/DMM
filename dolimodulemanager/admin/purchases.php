<?php
// DoliStore purchases merged into "Add a module" (add.php) since v2.1.0.
// The order history lists only paid modules, so this tab had grown an
// add-by-URL form for free ones — the same job add.php now does in one place.
// Redirect for backward compatibility.
header('Location: '.dirname($_SERVER['PHP_SELF']).'/add.php#purchases');
exit;
