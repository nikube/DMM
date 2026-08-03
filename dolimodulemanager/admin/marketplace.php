<?php
// DoliStore browsing merged into "Add a module" (add.php) since v2.1.0: the old
// split was browse-vs-own, which is not a boundary users act on. The catalog
// cache controls moved to the Advanced tab.
// Redirect for backward compatibility.
header('Location: '.dirname($_SERVER['PHP_SELF']).'/add.php#dolistore');
exit;
