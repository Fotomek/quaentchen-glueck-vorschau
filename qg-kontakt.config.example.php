<?php
declare(strict_types=1);

/* Serverseitig als qg-kontakt.config.php speichern, nicht ins Git.
   mail_to erst setzen, wenn eine Mailbox als Formularempfänger bestätigt ist.
   Kartentelefon nicht eintragen: die Nummer ist bestätigt, aber nicht veröffentlicht
   und nicht der Formularempfänger. Die Kartenmail bleibt unbestätigt.
   mail_from muss zum versendenden Host passen und eine reine Adresse sein.
   allowed_origins sind spätere HTTPS-Ursprünge, nicht die GitHub-Vorschau.
   Das Skript liest höchstens 16 KiB und weist längere oder falsch bezeichnete
   Bodies ab, auch ohne Content-Length. PHP post_max_size muss mindestens
   16K sein, 0 bedeutet für PHP unbegrenzt. LimitRequestBody mindestens 16384
   bleibt zusätzlich sinnvoll und ist auf dem Zielhost noch nicht geprüft. */
return [
    'mail_to' => '',
    'mail_from' => '',
    'rate_salt' => '',
    'allowed_origins' => [],
    'dry_run' => false,
];
