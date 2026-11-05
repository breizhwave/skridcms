<?php
/**
 * Skrid settings. Most people never need to touch this file:
 * put the skrid/ folder at the root of the website and open /skrid/.
 *
 * The password can be changed in Admin › Settings (it is then stored, hashed, in data/settings.json).
 */
return [
    // Password "KL" (temporary, change it in Admin › Settings). Hash made with password_hash().
    'password_hash' => '$2y$10$P8XlfMj73BULfDFTZZk2q.HpoSruGAD8FQ7Wxo2jVno/3tlX3iIZu',

    // Folder of the website. null = the folder that contains skrid/.
    'site_root' => null,

    // Web address (path) of the website root, e.g. '/' or '/mysite/'. null = guessed from where skrid/ is.
    'site_url' => null,

    // Folders never listed or edited (names, at any depth).
    'exclude' => ['skrid', 'node_modules', 'vendor', '.git', 'archive', '_to_delete', 'backup', 'backups'],

    // Copies kept for each page (one is made before every save or restore).
    'backups_per_page' => 30,

    // Editor language: 'auto' (from the browser), 'fr' or 'en'.
    'lang' => 'auto',

    // Time zone for the dates shown in the admin.
    'timezone' => 'Europe/Paris',
];
