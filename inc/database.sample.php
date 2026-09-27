<?php
/**
 * LAZE QR Menü - Canlı Sunucu Veritabanı Yapılandırma Örneği
 *
 * Canlı sunucuda (cPanel / Plesk vb.) bu dosyayı 'inc/database.php' adıyla kaydedip
 * sunucunuzdaki MySQL veritabanı bağlantı bilgilerini girin.
 *
 * GÜVENLİK NOTU:
 * 'inc/database.php' dosyası .gitignore ile korunur ve GitHub'a ASLA gönderilmez.
 * FTP dağıtımında da sunucudaki mevcut inc/database.php dosyasının üzerine yazılmaz.
 */
return array(
    'host'     => 'localhost',
    'port'     => 3306,
    'name'     => 'lazekofte_db',
    'user'     => 'lazekofte_user',
    'password' => 'guclu_veritabani_sifreniz'
);
