<?php

namespace App\Jobs;

use App\Models\ScheduledPost;
use App\Services\LinkedInPostService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishScheduledLinkedInPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $scheduledPostId,
    ) {}

    public function handle(LinkedInPostService $linkedInPostService): void
    {
        $post = ScheduledPost::query()
            ->with('connectedAccount')
            ->find($this->scheduledPostId);

        if (! $post instanceof ScheduledPost) {
            return;
        }

        if (
            $post->status === ScheduledPost::STATUS_CANCELLED
            || $post->status === ScheduledPost::STATUS_PUBLISHED
        ) {
            return;
        }

        $post->update([
            'status' => ScheduledPost::STATUS_PUBLISHING,
            'error_message' => null,
        ]);

        try {
            $result = $linkedInPostService->publish($post);

            $metadata = is_array($post->metadata) ? $post->metadata : [];
            $metadata['last_publish_response'] = $result['response'];

            $post->update([
                'status' => ScheduledPost::STATUS_PUBLISHED,
                'published_at' => now(),
                'provider_post_id' => $result['provider_post_id'],
                'error_message' => null,
                'metadata' => $metadata,
            ]);
        } catch (Throwable $exception) {
            $post->update([
                'status' => ScheduledPost::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);
        }
    }
}
