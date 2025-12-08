<?php
echo "<h2>Daftar File & Folder di 'coba'</h2>";
echo "<ul>";
$files = scandir(__DIR__);
foreach($files as $file){
    echo "<li>$file</li>";
}
echo "</ul>";
?>

