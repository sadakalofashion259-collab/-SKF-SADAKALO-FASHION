<?php
declare(strict_types=1);

class NotificationService {

    private string $appId;
    private string $restApiKey;
    private string $siteUrl;

    public function __construct(
        string $appId      = 'cbd8af0c-e1f4-4229-a1f6-8452055837e6',
        string $restApiKey = 'os_v2_app_zpmk6dhb6rbctipwqrjakwbx4ysvx7ejj3qe2oml2kmbui5nw76o3gkelirukdzdukzfs27igxk5evprhas3qmhqbtpbwobgt6w2yti',
        string $siteUrl    = 'https://sadakalohisabsystem.com'
    ) {
        $this->appId      = $appId;
        $this->restApiKey = $restApiKey;
        $this->siteUrl    = $siteUrl;
    }

    /* ── Send Push Notification ───────────────────────────────── */
    public function sendPush(string $message, string $heading = '📢 Sada Kalo Notice'): string {
        $payload = [
            'app_id'             => $this->appId,
            'included_segments'  => ['Subscribed Users'],
            'contents'           => ['en' => $message],
            'headings'           => ['en' => $heading],
            'url'                => $this->siteUrl,
        ];

        $curlHandle = curl_init();
        curl_setopt_array($curlHandle, [
            CURLOPT_URL            => 'https://onesignal.com/api/v1/notifications',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json; charset=utf-8',
                'Authorization: Basic ' . $this->restApiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $apiResponse = curl_exec($curlHandle);
        curl_close($curlHandle);
        return (string) $apiResponse;
    }
}
