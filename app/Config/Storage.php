<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

// Where user files live: listing media and tenant logos (uploads/), the
// encrypted KYC vault (kyc_vault/) and in-flight uploads (uploads_staging/).
//
// Set `storage.root` in .env to an absolute directory OUTSIDE the deployed
// code folder on any server whose code folder is replaced on deploy (e.g.
// Hostinger Git deployment) — otherwise every deploy can delete user files.
// Left blank, the in-tree locations public/uploads/ and writable/ are used,
// which is fine for local development.
class Storage extends BaseConfig
{
    public string $root = '';
}
