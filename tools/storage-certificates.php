<?php

declare(strict_types=1);

// Infrastructure fixture only. Private material stays in ignored runtime storage.
$directory = dirname(__DIR__).'/var/object-storage/tls';
if (is_file($directory.'/private.key') || is_file($directory.'/public.crt')) {
    if (!is_file($directory.'/private.key') || !is_file($directory.'/public.crt')) {
        throw new RuntimeException('Incomplete local TLS material; inspect the runtime directory.');
    }
    echo "Reusing local storage TLS material.\n";
    exit(0);
}
if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    throw new RuntimeException('Cannot prepare local TLS directory.');
}
$configuration = $directory.'/openssl.cnf';
file_put_contents($configuration, "[req]\ndistinguished_name=dn\nx509_extensions=ext\n[dn]\n[ext]\nsubjectAltName=DNS:object-storage-internal,DNS:object-storage-download,DNS:localhost\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    throw new RuntimeException('Local TLS generation failed.');
}
$csr = openssl_csr_new(['commonName' => 'object-storage-internal'], $key, ['config' => $configuration, 'digest_alg' => 'sha256']);
if (!$csr instanceof OpenSSLCertificateSigningRequest || !$key instanceof OpenSSLAsymmetricKey) {
    throw new RuntimeException('Local TLS generation failed.');
}
$certificate = openssl_csr_sign($csr, null, $key, 7, ['config' => $configuration, 'digest_alg' => 'sha256']);
if (false === $certificate || !openssl_x509_export_to_file($certificate, $directory.'/public.crt')
    || !openssl_pkey_export_to_file($key, $directory.'/private.key')) {
    throw new RuntimeException('Local TLS generation failed.');
}
chmod($directory.'/private.key', 0600);
echo "Local storage TLS material created (seven days).\n";
