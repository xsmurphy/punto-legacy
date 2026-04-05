RewriteEngine On
ErrorDocument 404 ../panel/includes/404.inc.php
ErrorDocument 500 ../panel/includes/errorPage.inc.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^([^\.]+)$ $1.php [NC,L]
