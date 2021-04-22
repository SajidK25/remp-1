<?php

namespace App\Jobs;

use App\CampaignSegment;
use App\Contracts\SegmentAggregator;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Redis;

class CacheSegmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $campaignSegment;

    private $force;

    /**
     * Create a new job instance.
     *
     * @param CampaignSegment $campaignSegment
     * @param bool $force
     */
    public function __construct(CampaignSegment $campaignSegment, $force = false)
    {
        $this->campaignSegment = $campaignSegment;
        $this->force = $force;
    }

    /**
     * Execute the job.
     *
     * @param SegmentAggregator $segmentAggregator
     */
    public function handle(SegmentAggregator $segmentAggregator)
    {
        if (!$this->force && Redis::connection()->scard($this->key())) {
            return;
        }

        if (!$segmentAggregator->cacheEnabled($this->campaignSegment)) {
            return;
        }

        try {
            $users = $segmentAggregator->users($this->campaignSegment);
        } catch (\Exception $e) {
            \Log::error('unable to cache users from segment', [
                'provider' => $this->campaignSegment->provider,
                'code' => $this->campaignSegment->code,
            ]);
            return;
        }

        Redis::connection()->del([$this->key()]);
        if ($users->isNotEmpty()) {
            Redis::connection()->sadd($this->key(), $users->toArray());
            Redis::connection()->expire($this->key(), 60*60*24);
        }
    }

    /**
     * Key returns unique key under which the data for given campaignSegment are cached.
     *
     * @return string
     */
    public function key(): string
    {
        return "{$this->campaignSegment->provider}|{$this->campaignSegment->code}";
    }
}
