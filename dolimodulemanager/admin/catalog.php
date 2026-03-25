<?php
// Catalog merged into dashboard (index.php) since v1.0.5
// Redirect for backward compatibility
header('Location: '.dirname($_SERVER['PHP_SELF']).'/index.php?'.($_SERVER['QUERY_STRING'] ?? ''));
exit;
