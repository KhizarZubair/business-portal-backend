<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\PublishScheduledLinkedInPostJob;
use App\Models\ConnectedAccount;
use App\Models\ScheduledPost;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LinkedInPostController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scope = (string) $request->query('scope', 'all');
        $limit = max(1, min(50, (int) $request->query('limit', 50)));
        $beforeId = $request->filled('before_id') ? (int) $request->query('before_id') : null;

        $query = ScheduledPost::query()
            ->where('user_id', $user->id)
            ->where('provider', 'linkedin');

        if ($scope === 'queue') {
            $query->whereIn('status', [
                ScheduledPost::STATUS_DRAFT,
                ScheduledPost::STATUS_SCHEDULED,
                ScheduledPost::STATUS_PUBLISHING,
            ]);
        } elseif ($scope === 'history') {
            $query->whereIn('status', [
                ScheduledPost::STATUS_PUBLISHED,
                ScheduledPost::STATUS_FAILED,
                ScheduledPost::STATUS_CANCELLED,
            ]);
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', (string) $request->query('status'));
        }

        if ($beforeId !== null && $beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }

        $totalMatching = (clone $query)->count();
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $posts = $hasMore ? $rows->slice(0, $limit)->values() : $rows->values();
        $nextBeforeId = $hasMore && $posts->isNotEmpty() ? (int) $posts->last()->id : null;

        return response()->json([
            'data' => $posts->map(fn (ScheduledPost $post): array => $this->serializePost($post))->values(),
            'meta' => [
                'scope' => $scope,
                'limit' => $limit,
                'total' => $totalMatching,
                'has_more' => $hasMore,
                'next_before_id' => $nextBeforeId,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:4000'],
            'action' => ['nullable', 'string', Rule::in(['draft', 'schedule', 'publish_now'])],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
            'media_file' => ['nullable', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm,video/x-msvideo'],
            'media_kind' => ['nullable', 'string', Rule::in(['image', 'video'])],
            'skip_db_media' => ['nullable'],
            'media' => ['nullable', 'array', 'max:4'],
            'media.*.kind' => ['required_with:media', 'string', Rule::in(['image', 'video'])],
            'media.*.name' => ['required_with:media', 'string', 'max:255'],
            'media.*.mime_type' => ['nullable', 'string', 'max:120'],
            'media.*.size_bytes' => ['nullable', 'integer', 'min:0'],
            'media.*.storage_path' => ['nullable', 'string', 'max:500'],
        ]);

        $content = trim((string) ($validated['content'] ?? ''));
        $uploadedFile = $request->file('media_file');
        $skipDbMedia = filter_var($request->input('skip_db_media', false), FILTER_VALIDATE_BOOLEAN);
        $media = $this->normalizeMedia($validated['media'] ?? null);

        if ($uploadedFile instanceof UploadedFile) {
            if ($skipDbMedia) {
                $media = [$this->transientMediaDescriptor($uploadedFile, $validated['media_kind'] ?? null)];
            } else {
                $media = [$this->storeUploadedMedia($uploadedFile, $user->id, $validated['media_kind'] ?? null)];
            }
        }

        if ($content === '' && $media === []) {
            return response()->json([
                'message' => 'Post content or media is required.',
                'errors' => ['content' => ['Post content or media is required.']],
            ], 422);
        }

        $action = $validated['action'] ?? (! empty($validated['scheduled_for']) ? 'schedule' : 'draft');

        if ($action === 'schedule' && empty($validated['scheduled_for'])) {
            return response()->json([
                'message' => 'scheduled_for is required when scheduling a post.',
                'errors' => ['scheduled_for' => ['scheduled_for is required when scheduling a post.']],
            ], 422);
        }

        $account = $this->resolveUserLinkedInAccount($user);

        if (in_array($action, ['schedule', 'publish_now'], true) && ! $account instanceof ConnectedAccount) {
            return response()->json([
                'message' => 'Connect a LinkedIn account before publishing or scheduling posts.',
            ], 422);
        }

        $scheduledFor = ! empty($validated['scheduled_for'])
            ? Carbon::parse((string) $validated['scheduled_for'])->utc()
            : null;

        $status = match ($action) {
            'publish_now' => ScheduledPost::STATUS_SCHEDULED,
            'schedule' => ScheduledPost::STATUS_SCHEDULED,
            default => ScheduledPost::STATUS_DRAFT,
        };

        $post = ScheduledPost::query()->create([
            'user_id' => $user->id,
            'connected_account_id' => $account?->id,
            'provider' => 'linkedin',
            'content' => $content,
            'status' => $status,
            'scheduled_for' => $action === 'publish_now' ? now()->utc() : $scheduledFor,
            'metadata' => [
                'created_via' => 'api.v1.linkedin.posts',
                'media' => $media,
                'skip_db_media' => $skipDbMedia,
            ],
        ]);

        if ($action === 'publish_now') {
            PublishScheduledLinkedInPostJob::dispatchSync($post->id);
        } elseif ($action === 'schedule' && $post->scheduled_for !== null) {
            PublishScheduledLinkedInPostJob::dispatch($post->id)->delay($post->scheduled_for);
        }

        $post->refresh();

        return response()->json([
            'message' => match ($action) {
                'publish_now' => $post->status === ScheduledPost::STATUS_PUBLISHED
                    ? 'Post published to LinkedIn.'
                    : ($post->error_message ?? 'LinkedIn publishing failed.'),
                'schedule' => 'Post scheduled for LinkedIn publishing.',
                default => 'Post draft saved.',
            },
            'data' => $this->serializePost($post),
        ], 201);
    }

    public function update(Request $request, ScheduledPost $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ((int) $post->user_id !== (int) $user->id || $post->provider !== 'linkedin') {
            abort(404);
        }

        if (in_array($post->status, [ScheduledPost::STATUS_PUBLISHING, ScheduledPost::STATUS_PUBLISHED], true)) {
            return response()->json([
                'message' => 'Published or currently publishing posts cannot be modified.',
            ], 422);
        }

        $validated = $request->validate([
            'content' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
            'status' => ['sometimes', 'string', Rule::in([ScheduledPost::STATUS_DRAFT, ScheduledPost::STATUS_SCHEDULED, ScheduledPost::STATUS_CANCELLED])],
            'media_file' => ['nullable', 'file', 'max:10240', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm,video/x-msvideo'],
            'media_kind' => ['nullable', 'string', Rule::in(['image', 'video'])],
            'clear_media' => ['nullable'],
            'media' => ['nullable', 'array', 'max:4'],
            'media.*.kind' => ['required_with:media', 'string', Rule::in(['image', 'video'])],
            'media.*.name' => ['required_with:media', 'string', 'max:255'],
            'media.*.mime_type' => ['nullable', 'string', 'max:120'],
            'media.*.size_bytes' => ['nullable', 'integer', 'min:0'],
            'media.*.storage_path' => ['nullable', 'string', 'max:500'],
        ]);

        $existingMetadata = is_array($post->metadata) ? $post->metadata : [];
        $existingMedia = is_array($existingMetadata['media'] ?? null) ? $existingMetadata['media'] : [];

        $content = array_key_exists('content', $validated) ? trim((string) ($validated['content'] ?? '')) : $post->content;
        $uploadedFile = $request->file('media_file');
        $clearMedia = filter_var($request->input('clear_media', false), FILTER_VALIDATE_BOOLEAN);

        if ($uploadedFile instanceof UploadedFile) {
            $nextMedia = [$this->storeUploadedMedia($uploadedFile, $user->id, $validated['media_kind'] ?? null)];
        } elseif ($clearMedia) {
            $nextMedia = [];
        } elseif (array_key_exists('media', $validated)) {
            $nextMedia = $this->normalizeMedia($validated['media']);
        } else {
            $nextMedia = $existingMedia;
        }

        if ($content === '' && $nextMedia === []) {
            return response()->json([
                'message' => 'Post content or media is required.',
                'errors' => ['content' => ['Post content or media is required.']],
            ], 422);
        }

        $nextStatus = $validated['status'] ?? $post->status;
        $nextScheduledFor = array_key_exists('scheduled_for', $validated)
            ? ($validated['scheduled_for'] !== null ? Carbon::parse((string) $validated['scheduled_for'])->utc() : null)
            : $post->scheduled_for;

        if ($nextStatus === ScheduledPost::STATUS_SCHEDULED) {
            $account = $this->resolveUserLinkedInAccount($user);
            if (! $account instanceof ConnectedAccount) {
                return response()->json([
                    'message' => 'Connect a LinkedIn account before scheduling a post.',
                ], 422);
            }

            if ($nextScheduledFor === null) {
                return response()->json([
                    'message' => 'scheduled_for is required when status is scheduled.',
                ], 422);
            }
        }

        $existingMetadata['media'] = $nextMedia;

        $post->update([
            'content' => $content,
            'scheduled_for' => $nextScheduledFor,
            'status' => $nextStatus,
            'error_message' => null,
            'metadata' => $existingMetadata,
        ]);

        if ($nextStatus === ScheduledPost::STATUS_SCHEDULED && $nextScheduledFor !== null) {
            PublishScheduledLinkedInPostJob::dispatch($post->id)->delay($nextScheduledFor);
        }

        return response()->json([
            'message' => 'Post updated.',
            'data' => $this->serializePost($post->fresh() ?? $post),
        ]);
    }

    public function publishNow(Request $request, ScheduledPost $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ((int) $post->user_id !== (int) $user->id || $post->provider !== 'linkedin') {
            abort(404);
        }

        if ($post->status === ScheduledPost::STATUS_PUBLISHED) {
            return response()->json([
                'message' => 'This post is already published on LinkedIn.',
                'data' => $this->serializePost($post),
            ], 422);
        }

        $account = $this->resolveUserLinkedInAccount($user);
        if (! $account instanceof ConnectedAccount) {
            return response()->json([
                'message' => 'Connect a LinkedIn account before publishing.',
            ], 422);
        }

        $post->update([
            'connected_account_id' => $account->id,
            'status' => ScheduledPost::STATUS_SCHEDULED,
            'scheduled_for' => now()->utc(),
            'error_message' => null,
        ]);

        PublishScheduledLinkedInPostJob::dispatchSync($post->id);
        $updatedPost = $post->fresh() ?? $post;

        return response()->json([
            'message' => $updatedPost->status === ScheduledPost::STATUS_PUBLISHED
                ? 'Post published to LinkedIn.'
                : ($updatedPost->error_message ?? 'LinkedIn publishing failed.'),
            'data' => $this->serializePost($updatedPost),
        ]);
    }

    public function destroy(Request $request, ScheduledPost $post): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ((int) $post->user_id !== (int) $user->id || $post->provider !== 'linkedin') {
            abort(404);
        }

        $post->delete();

        return response()->json([
            'message' => 'Post removed.',
        ]);
    }

    protected function resolveUserLinkedInAccount(User $user): ?ConnectedAccount
    {
        return $user->connectedAccounts()
            ->where('provider', 'linkedin')
            ->where('status', 'connected')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function transientMediaDescriptor(UploadedFile $file, ?string $explicitKind): array
    {
        $mimeType = (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'video/mp4');
        $kind = $explicitKind === 'image' || str_starts_with($mimeType, 'image/') ? 'image' : 'video';

        return [
            'kind' => $kind,
            'name' => $file->getClientOriginalName() ?: ($kind === 'video' ? 'video.mp4' : 'image.jpg'),
            'mime_type' => $mimeType,
            'size_bytes' => (int) ($file->getSize() ?: 0),
            'transient_only' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function storeUploadedMedia(UploadedFile $file, int $userId, ?string $explicitKind): array
    {
        $mimeType = (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream');
        $kind = $explicitKind === 'video' || str_starts_with($mimeType, 'video/') ? 'video' : 'image';
        $storagePath = $file->store("posts-media/user-{$userId}", 'public');

        return [
            'kind' => $kind,
            'name' => $file->getClientOriginalName() ?: ($kind === 'video' ? 'video.mp4' : 'image.jpg'),
            'mime_type' => $mimeType,
            'size_bytes' => (int) ($file->getSize() ?: 0),
            'storage_path' => $storagePath,
            'url' => Storage::disk('public')->url($storagePath),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $rawMedia
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeMedia(?array $rawMedia): array
    {
        if (! is_array($rawMedia) || $rawMedia === []) {
            return [];
        }

        $normalized = [];
        foreach ($rawMedia as $item) {
            if (! is_array($item)) {
                continue;
            }
            $kind = ($item['kind'] ?? '') === 'video' ? 'video' : 'image';
            $name = trim((string) ($item['name'] ?? ($kind === 'video' ? 'video.mp4' : 'image.jpg')));
            $storagePath = isset($item['storage_path']) && is_string($item['storage_path']) && $item['storage_path'] !== ''
                ? $item['storage_path']
                : null;

            $entry = [
                'kind' => $kind,
                'name' => $name !== '' ? $name : ($kind === 'video' ? 'video.mp4' : 'image.jpg'),
                'mime_type' => isset($item['mime_type']) && is_string($item['mime_type'])
                    ? $item['mime_type']
                    : ($kind === 'video' ? 'video/mp4' : 'image/jpeg'),
                'size_bytes' => isset($item['size_bytes']) ? max(0, (int) $item['size_bytes']) : 0,
                'storage_path' => $storagePath,
                'transient_only' => ! empty($item['transient_only']),
            ];

            if ($storagePath !== null) {
                $entry['url'] = Storage::disk('public')->url($storagePath);
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializePost(ScheduledPost $post): array
    {
        $metadata = is_array($post->metadata) ? $post->metadata : [];
        $rawMedia = is_array($metadata['media'] ?? null) ? $metadata['media'] : [];
        $media = array_map(function (array $item): array {
            if (! isset($item['url']) && isset($item['storage_path']) && is_string($item['storage_path']) && $item['storage_path'] !== '') {
                $item['url'] = Storage::disk('public')->url($item['storage_path']);
            }
            unset($item['data_url']);

            return $item;
        }, array_values(array_filter($rawMedia, 'is_array')));

        return [
            'id' => $post->id,
            'provider' => $post->provider,
            'connected_account_id' => $post->connected_account_id,
            'content' => $post->content,
            'media' => $media,
            'status' => $post->status,
            'scheduled_for' => $post->scheduled_for?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'provider_post_id' => $post->provider_post_id,
            'error_message' => $post->error_message,
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }
}
