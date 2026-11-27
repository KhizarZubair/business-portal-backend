<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\ScheduledPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class LinkedInPostService
{
    /**
     * Publish a ScheduledPost (text, image, or video) to LinkedIn via the Posts API (`w_member_social`).
     *
     * @return array{provider_post_id: string, response: array<string, mixed>}
     */
    public function publish(ScheduledPost $post): array
    {
        $content = trim($post->content);
        $mediaList = $this->extractMedia($post);
        $primaryMedia = $mediaList[0] ?? null;

        if ($content === '' && $primaryMedia === null) {
            throw new RuntimeException('Post content or media cannot be empty.');
        }

        $account = $this->resolveConnectedAccount($post);
        $accessToken = (string) ($account->access_token ?? '');

        if ($accessToken === '') {
            throw new RuntimeException('Connected LinkedIn account is missing an access token. Please reconnect LinkedIn.');
        }

        if ($account->token_expires_at !== null && $account->token_expires_at->isPast()) {
            throw new RuntimeException('LinkedIn access token has expired. Please reconnect your LinkedIn account.');
        }

        $authorUrn = $this->resolveAuthorUrn($account);
        $endpoint = (string) config('services.linkedin.posts_endpoint', 'https://api.linkedin.com/rest/posts');
        $apiVersion = (string) config('services.linkedin.linkedin_api_version', config('services.linkedin.api_version', '202605'));

        $payload = [
            'author' => $authorUrn,
            'commentary' => $content !== '' ? $content : ($primaryMedia['name'] ?? 'Shared media'),
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        $uploadedMediaUrn = null;
        if ($primaryMedia !== null) {
            $kind = ($primaryMedia['kind'] ?? 'image') === 'video' ? 'video' : 'image';
            $uploadedMediaUrn = $kind === 'video'
                ? $this->uploadVideo($primaryMedia, $authorUrn, $accessToken, $apiVersion)
                : $this->uploadImage($primaryMedia, $authorUrn, $accessToken, $apiVersion);

            $payload['content'] = [
                'media' => [
                    'title' => (string) ($primaryMedia['name'] ?? ucfirst($kind)),
                    'id' => $uploadedMediaUrn,
                ],
            ];
        }

        try {
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Linkedin-Version' => $apiVersion,
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(30)
                ->post($endpoint, $payload);
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to reach LinkedIn Posts API: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $body = $response->json();
            $apiMessage = is_array($body)
                ? (string) ($body['message'] ?? $body['error_description'] ?? $response->body())
                : (string) $response->body();

            throw new RuntimeException(
                sprintf('LinkedIn publish failed (HTTP %d): %s', $response->status(), trim($apiMessage))
            );
        }

        $jsonBody = $response->json();
        $data = is_array($jsonBody) ? $jsonBody : [];
        if ($uploadedMediaUrn !== null) {
            $data['media_urn'] = $uploadedMediaUrn;
        }

        $headerPostId = $response->header('x-restli-id') ?: $response->header('X-RestLi-Id');
        $bodyPostId = $data['id'] ?? null;
        $providerPostId = is_string($headerPostId) && $headerPostId !== ''
            ? $headerPostId
            : (is_string($bodyPostId) && $bodyPostId !== '' ? $bodyPostId : '');

        if ($providerPostId === '') {
            throw new RuntimeException('LinkedIn published the post but did not return a post ID.');
        }

        return [
            'provider_post_id' => $providerPostId,
            'response' => $data,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function extractMedia(ScheduledPost $post): array
    {
        $metadata = is_array($post->metadata) ? $post->metadata : [];
        $media = $metadata['media'] ?? [];

        return is_array($media) ? array_values(array_filter($media, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $media
     * @return array{binary: string, mime_type: string}
     */
    protected function decodeMediaBinary(array $media, string $fallbackMime): array
    {
        $mimeType = (string) ($media['mime_type'] ?? $fallbackMime);
        $storagePath = (string) ($media['storage_path'] ?? '');

        if ($storagePath !== '') {
            if (! Storage::disk('public')->exists($storagePath)) {
                throw new RuntimeException('Uploaded media file could not be found in storage.');
            }

            $binary = Storage::disk('public')->get($storagePath);
            if ($binary === null || $binary === '') {
                throw new RuntimeException('Uploaded media file is empty.');
            }

            return ['binary' => $binary, 'mime_type' => $mimeType];
        }

        $dataUrl = (string) ($media['data_url'] ?? '');
        if ($dataUrl !== '' && preg_match('/^data:([^;]+);base64,(.+)$/s', $dataUrl, $matches) === 1) {
            $mimeType = trim($matches[1]) !== '' ? trim($matches[1]) : $mimeType;
            $binary = base64_decode($matches[2], true);
            if ($binary === false || $binary === '') {
                throw new RuntimeException('Invalid base64 media payload.');
            }

            return ['binary' => $binary, 'mime_type' => $mimeType];
        }

        throw new RuntimeException('Attached media file is missing on the server.');
    }

    /**
     * @param  array<string, mixed>  $media
     */
    protected function uploadImage(array $media, string $authorUrn, string $accessToken, string $apiVersion): string
    {
        $decoded = $this->decodeMediaBinary($media, 'image/jpeg');

        $initResponse = Http::withToken($accessToken)
            ->withHeaders([
                'Linkedin-Version' => $apiVersion,
                'X-Restli-Protocol-Version' => '2.0.0',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(20)
            ->post('https://api.linkedin.com/rest/images?action=initializeUpload', [
                'initializeUploadRequest' => [
                    'owner' => $authorUrn,
                ],
            ]);

        if ($initResponse->failed()) {
            throw new RuntimeException(
                sprintf('LinkedIn image upload init failed (HTTP %d): %s', $initResponse->status(), $initResponse->body())
            );
        }

        $initData = $initResponse->json();
        $uploadUrl = is_array($initData) ? ($initData['value']['uploadUrl'] ?? null) : null;
        $imageUrn = is_array($initData) ? ($initData['value']['image'] ?? null) : null;

        if (! is_string($uploadUrl) || $uploadUrl === '' || ! is_string($imageUrn) || $imageUrn === '') {
            throw new RuntimeException('LinkedIn image upload initialization did not return uploadUrl and image URN.');
        }

        $uploadResponse = Http::withToken($accessToken)
            ->withBody($decoded['binary'], $decoded['mime_type'])
            ->timeout(40)
            ->put($uploadUrl);

        if ($uploadResponse->failed()) {
            throw new RuntimeException(
                sprintf('LinkedIn image binary upload failed (HTTP %d): %s', $uploadResponse->status(), $uploadResponse->body())
            );
        }

        return $imageUrn;
    }

    /**
     * @param  array<string, mixed>  $media
     */
    protected function uploadVideo(array $media, string $authorUrn, string $accessToken, string $apiVersion): string
    {
        $decoded = $this->decodeMediaBinary($media, 'video/mp4');
        $binary = $decoded['binary'];
        $mimeType = $decoded['mime_type'];
        $fileSize = strlen($binary);

        $initResponse = Http::withToken($accessToken)
            ->withHeaders([
                'Linkedin-Version' => $apiVersion,
                'X-Restli-Protocol-Version' => '2.0.0',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(20)
            ->post('https://api.linkedin.com/rest/videos?action=initializeUpload', [
                'initializeUploadRequest' => [
                    'owner' => $authorUrn,
                    'fileSizeBytes' => $fileSize,
                    'uploadCaptions' => false,
                    'uploadThumbnail' => false,
                ],
            ]);

        if ($initResponse->failed()) {
            throw new RuntimeException(
                sprintf('LinkedIn video upload init failed (HTTP %d): %s', $initResponse->status(), $initResponse->body())
            );
        }

        $initData = $initResponse->json();
        $videoUrn = is_array($initData) ? ($initData['value']['video'] ?? null) : null;
        $uploadToken = is_array($initData) ? ($initData['value']['uploadToken'] ?? '') : '';
        $instructions = is_array($initData) && is_array($initData['value']['uploadInstructions'] ?? null)
            ? $initData['value']['uploadInstructions']
            : [];

        if (! is_string($videoUrn) || $videoUrn === '' || $instructions === []) {
            throw new RuntimeException('LinkedIn video upload initialization did not return video URN and uploadInstructions.');
        }

        $uploadedPartIds = [];

        foreach ($instructions as $index => $instruction) {
            if (! is_array($instruction)) {
                continue;
            }

            $uploadUrl = (string) ($instruction['uploadUrl'] ?? '');
            if ($uploadUrl === '') {
                throw new RuntimeException(sprintf('LinkedIn video upload instruction #%d is missing uploadUrl.', $index + 1));
            }

            $startByte = isset($instruction['firstByte']) ? max(0, (int) $instruction['firstByte']) : 0;
            $endByte = isset($instruction['lastByte']) ? (int) $instruction['lastByte'] : ($fileSize - 1);
            $chunkLength = max(0, min($fileSize, $endByte + 1) - $startByte);
            $chunkBinary = $chunkLength > 0 ? substr($binary, $startByte, $chunkLength) : $binary;

            $uploadResponse = Http::withHeaders([
                'Content-Type' => $mimeType,
            ])
                ->timeout(120)
                ->withBody($chunkBinary, $mimeType)
                ->put($uploadUrl);

            if ($uploadResponse->failed()) {
                throw new RuntimeException(
                    sprintf('LinkedIn video binary upload failed (HTTP %d): %s', $uploadResponse->status(), $uploadResponse->body())
                );
            }

            $eTag = trim((string) ($uploadResponse->header('ETag') ?: $uploadResponse->header('etag') ?: ''), '"');
            if ($eTag !== '') {
                $uploadedPartIds[] = $eTag;
            }
        }

        $finalizeResponse = Http::withToken($accessToken)
            ->withHeaders([
                'Linkedin-Version' => $apiVersion,
                'X-Restli-Protocol-Version' => '2.0.0',
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(20)
            ->post('https://api.linkedin.com/rest/videos?action=finalizeUpload', [
                'finalizeUploadRequest' => [
                    'video' => $videoUrn,
                    'uploadToken' => (string) $uploadToken,
                    'uploadedPartIds' => $uploadedPartIds,
                ],
            ]);

        if ($finalizeResponse->failed()) {
            throw new RuntimeException(
                sprintf('LinkedIn video finalize failed (HTTP %d): %s', $finalizeResponse->status(), $finalizeResponse->body())
            );
        }

        return $videoUrn;
    }

    protected function resolveConnectedAccount(ScheduledPost $post): ConnectedAccount
    {
        $account = $post->connectedAccount;

        if (! $account instanceof ConnectedAccount) {
            $account = ConnectedAccount::query()
                ->where('user_id', $post->user_id)
                ->where('provider', 'linkedin')
                ->where('status', 'connected')
                ->latest('id')
                ->first();
        }

        if (! $account instanceof ConnectedAccount || $account->provider !== 'linkedin' || $account->status !== 'connected') {
            throw new RuntimeException('No active LinkedIn connected account found for this user.');
        }

        return $account;
    }

    protected function resolveAuthorUrn(ConnectedAccount $account): string
    {
        $providerAccountId = trim((string) $account->provider_account_id);

        if ($providerAccountId === '') {
            throw new RuntimeException('Connected LinkedIn account is missing the member identifier.');
        }

        if (str_starts_with($providerAccountId, 'urn:li:')) {
            return $providerAccountId;
        }

        return 'urn:li:person:'.$providerAccountId;
    }
}
