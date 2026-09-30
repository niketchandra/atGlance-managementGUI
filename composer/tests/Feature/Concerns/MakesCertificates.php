<?php

namespace Tests\Feature\Concerns;

/** Self-signed certificates for tests (EC P-256, fast to generate). */
trait MakesCertificates
{
    protected function makeCertificate(array $names, int $days = 365, ?string $keyPassphrase = null): array
    {
        $config = tempnam(sys_get_temp_dir(), 'cnf');
        $san = implode(',', array_map(fn ($name) => 'DNS:' . $name, $names));
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName={$san}\nbasicConstraints=CA:FALSE\n");
        $options = ['config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3', 'req_extensions' => 'v3'];

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $names[0]], $key, $options);
        $cert = openssl_csr_sign($csr, null, $key, $days, $options);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, $keyPassphrase, ['config' => $config]);
        @unlink($config);

        return ['cert' => $certPem, 'key' => $keyPem];
    }

    protected function makePkcs12(array $names, string $password): string
    {
        $pair = $this->makeCertificate($names);
        openssl_pkcs12_export($pair['cert'], $out, $pair['key'], $password);

        return $out;
    }
}
