<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class QzKeys extends Command
{
    protected $signature = 'pos:qz-keys {--force : overwrite existing files}';

    protected $description = 'Generate the QZ Tray signing key and self-signed certificate (import the certificate into QZ Tray once).';

    public function handle(): int
    {
        $cert = config('pos.qz.certificate');
        $keyPath = config('pos.qz.private_key');
        if ((is_file($cert) || is_file($keyPath)) && ! $this->option('force')) {
            $this->error('Sudah ada. Pakai --force untuk menimpa.');

            return self::FAILURE;
        }

        @mkdir(dirname($cert), 0700, true);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = $key ? openssl_csr_new(['commonName' => config('app.name').' POS'], $key, ['digest_alg' => 'sha512']) : false;
        $x509 = $key && $csr instanceof \OpenSSLCertificateSigningRequest ? openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha512']) : false;
        if (! $key || ! $x509) {
            $this->error('Gagal membuat kunci. Pastikan ekstensi openssl aktif.');

            return self::FAILURE;
        }
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $keyPem);
        file_put_contents($cert, $certPem);
        file_put_contents($keyPath, $keyPem);
        chmod($keyPath, 0600);
        $this->info("Tersimpan: {$cert}");

        return self::SUCCESS;
    }
}
