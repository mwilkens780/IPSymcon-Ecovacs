<?php

declare(strict_types=1);

/**
 * Holds the login for one Ecovacs account and talks to the same
 * undocumented REST API the official Ecovacs app / Home Assistant's
 * deebot_client integration use (Ecovacs has no official public API).
 * EcovacsVacuum instances read the device list and issue status/control
 * commands through this instance instead of authenticating separately.
 *
 * Login and every device command go over plain HTTPS REST calls to
 * Ecovacs' portal (iot/devmanager.do) -- despite modern ("eco-ng") robots
 * also supporting a persistent MQTT connection for instant push updates,
 * the same REST call used for commands also works synchronously for
 * status queries (GetBattery, GetChargeState, GetCleanInfo, ...), so no
 * MQTT client is needed here at all.
 */
class EcovacsAccount extends IPSModule
{
    // Fixed public app credentials used by every Ecovacs Android app client
    // to sign requests -- these are not secrets tied to any account, they
    // identify the app talking to the API (same values the deebot_client /
    // Home Assistant integration use).
    private const CLIENT_KEY = '1520391301804';
    private const CLIENT_SECRET = '6c319b2a5cd3e66e39159c2e28f2fce9';
    private const AUTH_CLIENT_KEY = '1520391491841';
    private const AUTH_CLIENT_SECRET = '77ef58ce3afbe337da74aa8c5ab963a9';

    private const REALM = 'ecouser.net';

    // Germany-specific endpoints -- Ecovacs splits its API by continent
    // (DE -> "eu"); hardcoded since this module is only used from Germany.
    private const LOGIN_URL = 'https://gl-de-api.ecovacs.com';
    private const AUTH_CODE_URL = 'https://gl-de-openapi.ecovacs.com';
    private const PORTAL_URL = 'https://portal-eu.ecouser.net';
    private const COUNTRY = 'DE';

    private const REQUEST_HEADERS = [
        'User-Agent: Dalvik/2.1.0 (Linux; U; Android 5.1.1; A5010 Build/LMY48Z)',
        'Content-Type: application/json',
    ];

    private const PUBLIC_KEY_CONFIG = 'PUBLIC.KEY.CONFIG';
    private const ANDROID_MODEL = 'Pixel 7';
    private const ANDROID_SYSTEM = 'Android 14';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('account', '');
        $this->RegisterPropertyString('password', '');

        $this->RegisterAttributeString('DeviceId', '');
        $this->RegisterAttributeString('EcoUserId', '');
        $this->RegisterAttributeString('EcoToken', '');
        $this->RegisterAttributeInteger('EcoExpiresAt', 0);

        $this->SetVisualizationType(0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $account = $this->ReadPropertyString('account');
        $password = $this->ReadPropertyString('password');
        if ($account === '' || $password === '') {
            $this->SetStatus(201);
            return;
        }

        $this->SetStatus(102);
    }

    /**
     * Verifies the connection and lists devices, for the config-panel button.
     * Reuses a still-valid cached session instead of forcing a fresh login --
     * Ecovacs' login endpoint is fragile enough (see the device-verification
     * flow below) that discarding a working session just to "test" it can
     * itself break a login that was working a moment ago.
     */
    public function TestConnection(): string
    {
        if (!$this->ensureSession()) {
            $status = IPS_GetInstance($this->InstanceID)['InstanceStatus'];
            if ($status === 202) {
                return $this->Translate('Geräteverifizierung per E-Mail-Code erforderlich -- zuerst "E-Mail-Code anfordern" klicken, danach den Code aus der E-Mail eintragen und "Code bestätigen" klicken.');
            }
            return $this->Translate('Anmeldung fehlgeschlagen -- Details im IPS-Log.');
        }

        $devices = $this->fetchDeviceList();
        if ($devices === null) {
            return $this->Translate('Anmeldung erfolgreich, aber Geräteabfrage fehlgeschlagen -- Details im IPS-Log.');
        }
        if (count($devices) === 0) {
            return $this->Translate('Verbunden, aber keine Geräte in diesem Konto gefunden.');
        }

        $lines = [$this->Translate('Gefundene Geräte (ID für die Saugroboter-Instanz):')];
        foreach ($devices as $d) {
            $lines[] = '- ' . ($d['nick'] ?? $d['name'] ?? '?') . ': did=' . ($d['did'] ?? '?')
                . ', class=' . ($d['class'] ?? '?') . ', resource=' . ($d['resource'] ?? '?')
                . ', company=' . ($d['company'] ?? '?');
        }
        return implode("\n", $lines);
    }

    /** JSON array of the account's devices (did/class/resource/nick/company), fetched live. For the EcovacsVacuum instances. */
    public function GetDeviceList(): string
    {
        if (!$this->ensureSession()) {
            return '[]';
        }
        $devices = $this->fetchDeviceList();
        return json_encode($devices ?? []);
    }

    /**
     * Sends a command (e.g. "GetBattery", "GetChargeState", "Clean") to one
     * device and returns the raw decoded JSON response as a JSON string, so
     * EcovacsVacuum instances don't need their own session/auth handling.
     * $argsJson is a JSON object of extra command args (e.g. '{"act":"start"}'), '{}' for plain "Get" queries.
     */
    public function ExecuteCommand(string $did, string $resource, string $class, string $cmdName, string $argsJson = '{}'): string
    {
        if (!$this->ensureSession()) {
            return json_encode(['ret' => 'fail', 'errno' => 0, 'error' => 'not authenticated']);
        }

        $args = json_decode($argsJson, true);
        if (!is_array($args)) {
            $args = [];
        }

        $payload = [
            'header' => [
                'pri' => '1',
                'ts'  => (string) microtime(true),
                'tzm' => 480,
                'ver' => '0.0.50',
            ],
        ];
        if (count($args) > 0) {
            $payload['body'] = ['data' => $args];
        }

        $body = [
            'cmdName'     => $cmdName,
            'payload'     => $payload,
            'payloadType' => 'j',
            'td'          => 'q',
            'toId'        => $did,
            'toRes'       => $resource,
            'toType'      => $class,
        ];

        $userId = $this->ReadAttributeString('EcoUserId');
        $queryParams = [
            'mid' => $class,
            'did' => $did,
            'td'  => 'q',
            'u'   => $userId,
            'cv'  => '1.67.3',
            't'   => 'a',
            'av'  => '1.3.1',
        ];

        $resp = $this->postAuthenticated('iot/devmanager.do', $body, $queryParams);
        return json_encode($resp ?? ['ret' => 'fail', 'errno' => 0, 'error' => 'request failed']);
    }

    /**
     * Ecovacs requires a one-time e-mail verification for a device id it
     * hasn't seen before (login fails with code 1013). Call this first,
     * then VerifyDevice() with the code from the e-mail -- afterwards this
     * device id is trusted and normal login() works without it again.
     */
    public function RequestVerificationCode(): string
    {
        $account = trim($this->ReadPropertyString('account'));
        if ($account === '') {
            return $this->Translate('Zuerst E-Mail-Adresse und Passwort eintragen und speichern.');
        }

        $encryptedEmail = $this->encryptForRsa($account);
        if ($encryptedEmail === null) {
            return $this->Translate('Verschlüsselung fehlgeschlagen -- Details im IPS-Log.');
        }

        $result = $this->callPrivateApi('user/sendEmailVerifyCode', [
            'encryptEmail' => $encryptedEmail,
            'verifyType'   => 'EMAIL_VERIFY_DEVICE',
            'supportChar'  => 'N',
            'isForce'      => 'N',
        ]);
        if ($result['code'] !== '0000') {
            return $this->Translate('Anfordern fehlgeschlagen -- Details im IPS-Log.');
        }
        $this->LogMessage('EcovacsAccount: E-Mail-Verifizierungscode angefordert', KL_MESSAGE);
        return $this->Translate('Code angefordert -- bitte E-Mail-Postfach prüfen, Code unten eintragen und "Code bestätigen" klicken.');
    }

    /** Completes the login using the one-time e-mail code from RequestVerificationCode(). */
    public function VerifyDevice(string $code): string
    {
        $account = trim($this->ReadPropertyString('account'));
        $code = trim($code);
        if ($account === '' || $code === '') {
            return $this->Translate('E-Mail-Adresse und Code werden benötigt.');
        }

        $encryptedAccount = $this->encryptForRsa($account);
        if ($encryptedAccount === null) {
            return $this->Translate('Verschlüsselung fehlgeschlagen -- Details im IPS-Log.');
        }

        $result = $this->callPrivateApi('user/verifyDevice', [
            'encryptAccount' => $encryptedAccount,
            'backUpEmail'    => '',
            'verifyCode'     => $code,
            'model'          => self::ANDROID_MODEL,
            'system'         => self::ANDROID_SYSTEM,
        ]);
        if ($result['code'] !== '0000') {
            return $this->Translate('Bestätigung fehlgeschlagen -- falscher oder abgelaufener Code? Details im IPS-Log.');
        }

        if (!$this->completeLogin($result['data'])) {
            return $this->Translate('Gerät bestätigt, aber Anmeldung danach fehlgeschlagen -- Details im IPS-Log.');
        }
        return $this->Translate('Gerät bestätigt und angemeldet.');
    }

    /** Reuses the cached Ecovacs session (token/userid) while valid, otherwise redoes the full login. */
    private function ensureSession(): bool
    {
        $expiresAt = $this->ReadAttributeInteger('EcoExpiresAt');
        if ($expiresAt > time() + 60 && $this->ReadAttributeString('EcoToken') !== '') {
            return true;
        }
        return $this->login();
    }

    /** Multi-step, MD5-signed login flow: user/login -> getAuthCode -> loginByItToken. */
    private function login(): bool
    {
        $account = trim($this->ReadPropertyString('account'));
        $password = trim($this->ReadPropertyString('password'));
        if ($account === '' || $password === '') {
            $this->SetStatus(201);
            return false;
        }

        try {
            $passwordHash = md5($password);

            $result = $this->callPrivateApi('user/login', [
                'account'  => $account,
                'password' => $passwordHash,
            ]);

            if ($result['code'] === '1013') {
                $this->SetStatus(202);
                $this->LogMessage('EcovacsAccount: Geräteverifizierung per E-Mail-Code erforderlich -- Buttons "E-Mail-Code anfordern" / "Code bestätigen" in der Instanzkonfiguration verwenden.', KL_ERROR);
                return false;
            }
            if ($result['code'] === '1005' || $result['code'] === '1010') {
                $this->LogMessage('EcovacsAccount: Benutzername oder Passwort falsch (Code ' . $result['code'] . ')', KL_ERROR);
                $this->SetStatus(201);
                return false;
            }
            if ($result['code'] !== '0000') {
                $this->SetStatus(201);
                return false;
            }

            return $this->completeLogin($result['data']);
        } catch (\Throwable $e) {
            $this->LogMessage('EcovacsAccount Login: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(201);
            return false;
        }
    }

    /** Shared tail of both normal login() and VerifyDevice(): uid/accessToken -> authCode -> portal session token. */
    private function completeLogin(array $loginResp): bool
    {
        if (!isset($loginResp['uid'], $loginResp['accessToken'])) {
            $this->LogMessage('EcovacsAccount: Login-Antwort unvollständig: ' . $this->truncate(json_encode($loginResp)), KL_ERROR);
            $this->SetStatus(201);
            return false;
        }
        $uid = (string) $loginResp['uid'];
        $accessToken = (string) $loginResp['accessToken'];

        $authCode = $this->callAuthApi($accessToken, $uid);
        if ($authCode === null) {
            $this->SetStatus(201);
            return false;
        }

        $tokenResp = $this->loginByItToken($uid, $authCode);
        if ($tokenResp === null || ($tokenResp['result'] ?? '') !== 'ok') {
            $this->LogMessage('EcovacsAccount: loginByItToken fehlgeschlagen: ' . $this->truncate(json_encode($tokenResp)), KL_ERROR);
            $this->SetStatus(201);
            return false;
        }

        $finalUserId = (string) ($tokenResp['userId'] ?? $uid);
        $finalToken = (string) $tokenResp['token'];
        $validForMs = (int) ($tokenResp['last'] ?? 604800000);
        $expiresAt = (int) (time() + ($validForMs / 1000 * 0.99));

        $this->WriteAttributeString('EcoUserId', $finalUserId);
        $this->WriteAttributeString('EcoToken', $finalToken);
        $this->WriteAttributeInteger('EcoExpiresAt', $expiresAt);
        $this->SetStatus(102);
        $this->LogMessage('EcovacsAccount: Anmeldung erfolgreich, Sitzung gültig bis ' . date('Y-m-d H:i:s', $expiresAt), KL_MESSAGE);
        return true;
    }

    /** users/user.do?todo=GetDeviceList -- returns this account's robots. */
    private function fetchDeviceList(): ?array
    {
        $userId = $this->ReadAttributeString('EcoUserId');
        $resp = $this->postAuthenticated('users/user.do', [
            'userid' => $userId,
            'todo'   => 'GetDeviceList',
        ]);
        if ($resp === null || !isset($resp['devices']) || !is_array($resp['devices'])) {
            return null;
        }
        return $resp['devices'];
    }

    /** POST to portal_url/api/{path}, with the standard "auth" block (userid/token/resource) merged into the JSON body. */
    private function postAuthenticated(string $path, array $json, array $queryParams = []): ?array
    {
        $userId = $this->ReadAttributeString('EcoUserId');
        $token = $this->ReadAttributeString('EcoToken');
        $deviceId = $this->deviceId();

        $json['auth'] = [
            'with'     => 'users',
            'userid'   => $userId,
            'realm'    => self::REALM,
            'token'    => $token,
            'resource' => $deviceId,
        ];

        $url = self::PORTAL_URL . '/api/' . $path;
        if (count($queryParams) > 0) {
            $url .= '?' . http_build_query($queryParams);
        }

        $resp = $this->httpRequest($url, 'POST', self::REQUEST_HEADERS, json_encode($json));
        if ($resp['status'] !== 200) {
            $this->LogMessage('EcovacsAccount: POST ' . $path . ' fehlgeschlagen (HTTP ' . $resp['status'] . '): ' . $this->truncate($resp['body']), KL_ERROR);
            return null;
        }
        $data = json_decode($resp['body'], true);
        return is_array($data) ? $data : null;
    }

    /** Calls a signed "private" auth-API GET endpoint (user/login, common/getConfig, ...). Returns ['code' => string, 'data' => array]. */
    private function callPrivateApi(string $endpoint, array $params): array
    {
        $deviceId = $this->deviceId();
        $meta = [
            'country'     => strtolower(self::COUNTRY),
            'lang'        => 'EN',
            'appCode'     => 'global_e',
            'appVersion'  => '3.14.0',
            'channel'     => 'google_play',
            'deviceType'  => '1',
            'deviceId'    => $deviceId,
        ];

        $now = microtime(true);
        $params += [
            'requestId'     => md5((string) $now),
            'authTimespan'  => (int) ($now * 1000),
            'authTimeZone'  => 'GMT-8',
        ];

        $signedParams = $this->sign($params, $meta, self::CLIENT_KEY, self::CLIENT_SECRET);

        $url = self::LOGIN_URL . '/v1/private/' . $meta['country'] . '/' . $meta['lang'] . '/' . $meta['deviceId']
            . '/' . $meta['appCode'] . '/' . $meta['appVersion'] . '/' . $meta['channel'] . '/' . $meta['deviceType']
            . '/' . $endpoint;

        return $this->doAuthRequest($url, $signedParams);
    }

    /** Exchanges the Ecovacs access token for a short-lived auth code, needed for loginByItToken. */
    private function callAuthApi(string $accessToken, string $userId): ?string
    {
        $params = [
            'uid'          => $userId,
            'accessToken'  => $accessToken,
            'bizType'      => 'ECOVACS_IOT',
            'deviceId'     => $this->deviceId(),
            'authTimespan' => (int) (microtime(true) * 1000),
        ];
        $signedParams = $this->sign($params, ['openId' => 'global'], self::AUTH_CLIENT_KEY, self::AUTH_CLIENT_SECRET);

        $url = self::AUTH_CODE_URL . '/v1/global/auth/getAuthCode';
        $result = $this->doAuthRequest($url, $signedParams);
        if ($result['code'] !== '0000' || !isset($result['data']['authCode'])) {
            $this->LogMessage('EcovacsAccount: getAuthCode fehlgeschlagen (Code ' . $result['code'] . ')', KL_ERROR);
            return null;
        }
        return (string) $result['data']['authCode'];
    }

    /** users/user.do?todo=loginByItToken -- exchanges the auth code for the actual portal session token. */
    private function loginByItToken(string $userId, string $authCode): ?array
    {
        $deviceId = $this->deviceId();
        $body = [
            'edition' => 'ECOGLOBLE',
            'userId'  => $userId,
            'token'   => $authCode,
            'realm'   => self::REALM,
            'resource'=> $deviceId,
            'org'     => 'ECOWW',
            'last'    => '',
            'country' => self::COUNTRY,
            'todo'    => 'loginByItToken',
        ];

        $resp = $this->httpRequest(self::PORTAL_URL . '/api/users/user.do', 'POST', self::REQUEST_HEADERS, json_encode($body));
        if ($resp['status'] !== 200) {
            $this->LogMessage('EcovacsAccount: loginByItToken HTTP ' . $resp['status'] . ': ' . $this->truncate($resp['body']), KL_ERROR);
            return null;
        }
        $data = json_decode($resp['body'], true);
        return is_array($data) ? $data : null;
    }

    /** GET request + Ecovacs' {code,msg,data} envelope handling, shared by callPrivateApi and callAuthApi. Returns ['code' => string, 'data' => array]. */
    private function doAuthRequest(string $url, array $params): array
    {
        $fullUrl = $url . '?' . http_build_query($params);
        $resp = $this->httpRequest($fullUrl, 'GET', self::REQUEST_HEADERS);
        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            $this->LogMessage('EcovacsAccount: ungültige Antwort von ' . $url . ' (HTTP ' . $resp['status'] . '): ' . $this->truncate($resp['body']), KL_ERROR);
            return ['code' => '', 'data' => []];
        }

        $code = (string) ($json['code'] ?? '');
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        if ($code !== '0000') {
            $msg = (string) ($json['msg'] ?? '');
            $this->LogMessage('EcovacsAccount: Anfrage an ' . $url . ' fehlgeschlagen, Code ' . $code . ($msg !== '' ? ' (' . $msg . ')' : ''), KL_ERROR);
        }
        return ['code' => $code, 'data' => $data];
    }

    /** Fetches and PEM-wraps Ecovacs' RSA public key (common/getConfig), used to encrypt the account e-mail for device verification. */
    private function getPublicKeyPem(): ?string
    {
        $result = $this->callPrivateApi('common/getConfig', ['keys' => self::PUBLIC_KEY_CONFIG]);
        if ($result['code'] !== '0000') {
            return null;
        }

        foreach ($result['data'] as $entry) {
            if (!is_array($entry) || ($entry['key'] ?? '') !== self::PUBLIC_KEY_CONFIG || !isset($entry['value'])) {
                continue;
            }
            $decoded = json_decode((string) $entry['value'], true);
            $base64Der = $decoded['publicKey'] ?? null;
            if (is_string($base64Der) && $base64Der !== '') {
                return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($base64Der, 64, "\n") . "-----END PUBLIC KEY-----\n";
            }
        }
        $this->LogMessage('EcovacsAccount: Antwort auf common/getConfig enthält keinen öffentlichen Schlüssel', KL_ERROR);
        return null;
    }

    /** RSA/PKCS1v15-encrypts (as Ecovacs' Android app does) and base64-encodes a plaintext, for the device-verification endpoints. */
    private function encryptForRsa(string $plaintext): ?string
    {
        $pem = $this->getPublicKeyPem();
        if ($pem === null) {
            return null;
        }
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            $this->LogMessage('EcovacsAccount: öffentlicher Schlüssel ungültig: ' . openssl_error_string(), KL_ERROR);
            return null;
        }
        if (!openssl_public_encrypt($plaintext, $encrypted, $publicKey, OPENSSL_PKCS1_PADDING)) {
            $this->LogMessage('EcovacsAccount: RSA-Verschlüsselung fehlgeschlagen: ' . openssl_error_string(), KL_ERROR);
            return null;
        }
        return base64_encode($encrypted);
    }

    /** MD5 request signing shared by every signed auth-API call: md5(key + sorted "k=v" pairs + secret). */
    private function sign(array $params, array $additionalSignParams, string $key, string $secret): array
    {
        $signData = array_merge($additionalSignParams, $params);
        ksort($signData);

        $signOnText = $key;
        foreach ($signData as $k => $v) {
            $signOnText .= $k . '=' . $v;
        }
        $signOnText .= $secret;

        $params['authSign'] = md5($signOnText);
        $params['authAppkey'] = $key;
        return $params;
    }

    /** A random, persistent per-installation device id -- reused for every signed request and as the portal "resource". */
    private function deviceId(): string
    {
        $id = $this->ReadAttributeString('DeviceId');
        if ($id === '') {
            $id = bin2hex(random_bytes(4));
            $this->WriteAttributeString('DeviceId', $id);
        }
        return $id;
    }

    /** Keeps error log lines short even when Ecovacs returns a large HTML error page instead of JSON. */
    private function truncate(string $body, int $length = 300): string
    {
        $body = trim($body);
        return strlen($body) > $length ? substr($body, 0, $length) . '...' : $body;
    }

    /** @return array{status:int,body:string} */
    private function httpRequest(string $url, string $method, array $headers, ?string $body = null, int $redirectsLeft = 5): array
    {
        $options = [
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headers),
                'ignore_errors'   => true,
                'timeout'         => 15,
                'follow_location' => 0,
            ],
        ];
        if ($body !== null) {
            $options['http']['content'] = $body;
        }

        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);

        $status = 0;
        $location = null;
        if (isset($http_response_header) && is_array($http_response_header)) {
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
            foreach ($http_response_header as $headerLine) {
                if (stripos($headerLine, 'Location:') === 0) {
                    $location = trim(substr($headerLine, strlen('Location:')));
                }
            }
        }

        if ($status >= 300 && $status < 400 && $location !== null && $redirectsLeft > 0) {
            $nextUrl = (parse_url($location, PHP_URL_SCHEME) !== null) ? $location : $this->resolveUrl($url, $location);
            return $this->httpRequest($nextUrl, $method, $headers, $body, $redirectsLeft - 1);
        }

        return ['status' => $status, 'body' => $result === false ? '' : $result];
    }

    /** Resolves a relative Location header against the URL it was returned for. */
    private function resolveUrl(string $baseUrl, string $location): string
    {
        $base = parse_url($baseUrl);
        if ($location !== '' && $location[0] === '/') {
            return $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '') . $location;
        }
        return rtrim(dirname($baseUrl), '/') . '/' . $location;
    }
}
