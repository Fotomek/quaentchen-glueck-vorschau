<?php
declare(strict_types=1);

/* Serverseitig als qg-kontakt.config.php speichern, nicht ins Git.
   mail_to erst setzen, wenn eine Mailbox als Formularempfänger bestätigt ist.
   Die Visitenkartenadresse ist das nicht. mail_from muss zum versendenden Host passen.
   allowed_origins sind die späteren HTTPS-Ursprünge, nicht die GitHub-Vorschau. */
return [
    'mail_to' => '',
    'mail_from' => '',
    'rate_salt' => '',
    'allowed_origins' => [],
    'dry_run' => false,
];
