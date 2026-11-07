<?php
declare(strict_types=1);

/* Serverseitig als qg-kontakt.config.php speichern, nicht ins Git.
   mail_to erst setzen, wenn eine Mailbox als Formularempfänger bestätigt ist.
   Visitenkartenmail und -telefon hier nicht eintragen: die Websitefreigabe fehlt.
   mail_from muss zum versendenden Host passen und eine reine Adresse sein.
   allowed_origins sind spätere HTTPS-Ursprünge, nicht die GitHub-Vorschau.
   Der Endpunkt weist Bodies über 16 KiB ab. PHP post_max_size muss mindestens
   16K sein, 0 bedeutet unbegrenzt. Der Webserver braucht LimitRequestBody
   mindestens 16384. Beides ist auf dem Zielhost noch nicht geprüft. */
return [
    'mail_to' => '',
    'mail_from' => '',
    'rate_salt' => '',
    'allowed_origins' => [],
    'dry_run' => false,
];
