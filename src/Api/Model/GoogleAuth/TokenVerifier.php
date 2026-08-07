<?php

namespace Api\Model\GoogleAuth;

/**
 * Verifies a Google Sign-In ID token server-side, via Google's own
 * tokeninfo endpoint (simple and dependency-free - the alternative,
 * verifying the JWT signature locally against Google's public keys, needs
 * a JWT library this app doesn't otherwise have a use for).
 */
class TokenVerifier
{

    private const string TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    /**
     * @param string[] $allowedAudiences our own app's client IDs
     * @return array{sub: string, email: string}|null
     */
    public function verify(string $idToken, array $allowedAudiences): ?array
    {
        $payload = $this->httpGet(self::TOKENINFO_URL . '?id_token=' . urlencode($idToken));

        if (
            !isset($payload['sub'], $payload['email'], $payload['aud'])
            || !in_array($payload['aud'], $allowedAudiences, true)
            || ($payload['email_verified'] ?? 'false') !== 'true'
        ) {
            return null;
        }

        return array('sub' => (string) $payload['sub'], 'email' => (string) $payload['email']);
    }

    /**
     * Deliberately not Core\Model\Utils\Curl - its make() unconditionally
     * proxies through this app's own dev server when IS_DEV is true, which
     * breaks reaching a real third-party host like Google.
     *
     * @return array<string, mixed>
     */
    private function httpGet(string $url): array
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 15);

        $output  = curl_exec($curl);
        $decoded = $output !== false ? json_decode($output, true) : null;
        return is_array($decoded) ? $decoded : array();
    }

}
