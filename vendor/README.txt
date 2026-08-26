VENDOR FOLDER (server side)
===========================

Place these two files here to make the offline package fully self-contained
(customers will then download NOTHING at setup time):

  php.zip       PHP 8.2 NTS x64 Windows build
                https://windows.php.net/downloads/releases/
                (file: php-8.2.x-nts-Win32-vs16-x64.zip)

  mariadb.zip   MariaDB 10.11 winx64 ZIP package
                https://archive.mariadb.org/mariadb-10.11.8/winx64-packages/
                (file: mariadb-10.11.8-winx64.zip)

Keep the file names exactly as above. When present, they are copied into
every generated offline package, and the Windows setup uses them directly
instead of downloading.
