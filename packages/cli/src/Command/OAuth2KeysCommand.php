<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\Jose\SigningKey;
use Illuminate\Console\Command;

/**
 * Generates the private key the OAuth2 authorization server signs with: RSA 2048 for RS256 (the default) or
 * P-256 for ES256, written to storage/oauth2/private.pem with owner-only permissions (or printed with --print),
 * and the two lines a developer needs next — the env variable to set and the kid the JWKS will publish. A file
 * that already exists is never overwritten without --force: a key replaced by accident invalidates every token
 * in flight. Rotation is documented in docs/modules/security-oauth2-server.md (move the old key to
 * jwt.previous_keys, generate a new one here).
 */
final class OAuth2KeysCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:oauth2:keys
        {--algorithm=RS256 : RS256 (an RSA key) or ES256 (an EC P-256 key)}
        {--bits=2048 : the RSA modulus size (ignored for ES256)}
        {--out= : where to write the PEM (default: storage/oauth2/private.pem)}
        {--force : overwrite an existing file}
        {--print : print the PEM to the console instead of writing a file}';

    /** @var string */
    protected $description = 'Generate a private signing key for the OAuth2 authorization server (firefly.security.oauth2.server.jwt.signing_key).';

    public function handle(): int
    {
        $algorithm = (string) $this->option('algorithm');
        $bits = (int) $this->option('bits');

        try {
            $pem = KeyPairGenerator::generate($algorithm, $bits);
            $kid = SigningKey::fromPem($pem, $algorithm)->kid;
        } catch (ConfigurationException $e) {
            $this->error('firefly:oauth2:keys — '.$e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('print')) {
            $this->line($pem);
            $this->info("kid: {$kid} ({$algorithm})");

            return self::SUCCESS;
        }

        $out = (string) $this->option('out');
        if ($out === '') {
            $out = $this->laravel->storagePath('oauth2/private.pem');
        }

        if (is_file($out) && ! (bool) $this->option('force')) {
            $this->error("firefly:oauth2:keys — {$out} already exists; pass --force to overwrite it (every token signed with the old key stops verifying unless you keep it in jwt.previous_keys).");

            return self::FAILURE;
        }

        $dir = dirname($out);
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        file_put_contents($out, $pem);
        chmod($out, 0o600);

        $this->info("firefly:oauth2:keys — wrote {$out} ({$algorithm}, kid {$kid})");
        $this->line("Set FIREFLY_OAUTH2_SERVER_SIGNING_KEY={$out} (firefly.security.oauth2.server.jwt.signing_key) and keep the file out of version control.");

        return self::SUCCESS;
    }
}
