<?php

return [
    /*
     | THE APEX, NOT THE PORTAL HOST.
     |
     | The portal is app.kiddietrac.com and the API is api.kiddietrac.com. Registering
     | against the apex means one passkey works from either and survives a subdomain
     | nobody has thought of yet. It must be a registrable suffix of the page's origin,
     | which the apex is — confirmed on a real device 2026-09-22, where a wrong value
     | would have thrown SecurityError rather than returning a credential.
     |
     | Overridable so a staging host can differ; never leave it empty, because a null RP
     | ID fails every ceremony with an error nobody can read.
     */
    'rp_id' => env('PASSKEY_RP_ID', 'kiddietrac.com'),
];
