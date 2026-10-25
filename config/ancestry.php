<?php

// Who may borrow a loader session through the Ancestry Switch browser
// extension (extension/ancestry-switch). Handing these out is handing
// over the whole Ancestry account, so it is an explicit allow-list of
// login emails, and empty — the default — means nobody.
//
// Read at config-load time, like config/admin.php, so it survives
// `php artisan config:cache`.
return [
    'switch_emails' => array_values(array_filter(array_map(
        fn ($e) => strtolower(trim($e)),
        explode(',', (string) env('ANCESTRY_SWITCH_EMAILS', ''))
    ))),
];
