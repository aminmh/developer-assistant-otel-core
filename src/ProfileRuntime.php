<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

use Throwable;

/** Joins method counts and one sampled profile to the active request/job span. */
final class ProfileRuntime
{
    private ?ExcimerProfileSession $session = null;

    /** @param list<string> $applicationNamespaces */
    public function start(array $applicationNamespaces = ['App'], ?string $rootSymbolId = null): void
    {
        MethodCallRecorder::reset();
        // The profile starts when routing picks the controller, before route middleware runs. A hooked
        // controller is pushed by its own hook when it is actually called; pre-entering it here would
        // make the middleware look like it was called by the controller. Only an unhooked controller is
        // entered up front, so the methods it calls still have a caller.
        if (is_string($rootSymbolId) && !MethodHookRegistry::isHooked($rootSymbolId)
            && preg_match('/\\A[A-Za-z_][A-Za-z0-9_\\\\]*::[A-Za-z_][A-Za-z0-9_]*\\z/D', $rootSymbolId)) {
            MethodCallRecorder::enter($rootSymbolId);
        }
        $this->session = new ExcimerProfileSession($applicationNamespaces);
        $this->session->start();
    }

    public function finish(?ActiveSpan $rootSpan, int $statusCode, ?string $projectRoot = null): void
    {
        $session = $this->session;
        $this->session = null;
        $context = null;
        try {
            $context = $rootSpan?->context();
        } catch (Throwable) {
        }
        $resources = (new ResourceDetector($projectRoot))->detect();
        $profile = $session?->stop(
            $context?->getTraceId() ?? '',
            $context?->getSpanId() ?? '',
            $statusCode,
            $resources,
        );

        $hookCounts = MethodCallRecorder::drain();
        $droppedCalls = MethodCallRecorder::lastDroppedCalls();
        if ($hookCounts !== []) {
            try {
                $serialized = json_encode($hookCounts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                if (strlen($serialized) <= 32768) {
                    $rootSpan?->enrich(['indexer.method_hook_counts' => $serialized]);
                } else {
                    $droppedCalls += array_sum(array_column($hookCounts, 'calls'));
                }
            } catch (Throwable) {
            }
        }
        if ($droppedCalls > 0) {
            // Lets the Runtime view say that method activity for this request is incomplete.
            $rootSpan?->enrich(['indexer.dropped.hook_calls' => $droppedCalls]);
        }
        if ($profile !== null) {
            $rootSpan?->enrich([
                'profiler.profile_id' => $profile['profile_id'],
                'profiler.top_frames' => $profile['top_frames'],
                'profiler.sample_period_ms' => $profile['sample_period_ms'],
            ]);
            ProfileExportQueue::enqueue($profile);
        }
    }

    public function reset(): void
    {
        $this->session?->reset();
        $this->session = null;
        MethodCallRecorder::reset();
    }
}
