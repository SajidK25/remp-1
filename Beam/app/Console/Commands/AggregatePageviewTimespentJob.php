<?php

namespace App\Console\Commands;

use App\Article;
use App\ArticleTimespent;
use App\Helpers\DebugProxy;
use App\Console\Command;
use Illuminate\Support\Carbon;
use Remp\Journal\AggregateRequest;
use Remp\Journal\JournalContract;
use Symfony\Component\Console\Helper\ProgressBar;

class AggregatePageviewTimespentJob extends Command
{
    const COMMAND = 'pageviews:aggregate-timespent';

    protected $signature = self::COMMAND . ' {--now=} {--debug} {--article_id=}';

    protected $description = 'Reads pageview/timespent data from journal and stores aggregated data';

    public function handle(JournalContract $journalContract)
    {
        $debug = $this->option('debug') ?? false;
        $articleId = $this->option('article_id') ?? null;
        $now = $this->option('now') ? Carbon::parse($this->option('now')) : Carbon::now();

        $timeBefore = $now->floorMinutes(20);
        $timeAfter = (clone $timeBefore)->subMinutes(20);

        $this->line(sprintf("Fetching aggregated timespent data from <info>%s</info> to <info>%s</info>.", $timeAfter, $timeBefore));

        $request = new AggregateRequest('pageviews', 'timespent');
        $request->setTimeAfter($timeAfter);
        $request->setTimeBefore($timeBefore);
        if ($articleId) {
            $request->addFilter('article_id', $articleId);
        }
        $request->addGroup('article_id', 'signed_in', 'subscriber');
        $records = collect($journalContract->sum($request));

        if (count($records) === 1 && !isset($records[0]->tags->article_id)) {
            $this->line("No articles to process, exiting.");
            return 0;
        }

        /** @var ProgressBar $bar */
        $bar = new DebugProxy($this->output->createProgressBar(count($records)), $debug);
        $bar->setFormat('%message%: %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $bar->setMessage('Processing pageviews');

        $all = [];
        $signedIn = [];
        $subscribers = [];

        foreach ($records as $record) {
            if (empty($record->tags->article_id)) {
                $bar->advance();
                continue;
            }
            $bar->setMessage(sprintf("Processing pageviews for article ID: <info>%s</info>", $record->tags->article_id));

            $articleId = $record->tags->article_id;

            $all[$articleId] = $all[$articleId] ?? 0;
            $signedIn[$articleId] = $signedIn[$articleId] ?? 0;
            $subscribers[$articleId] = $subscribers[$articleId] ?? 0;

            $all[$articleId] += $record->sum;
            if (filter_var($record->tags->signed_in, FILTER_VALIDATE_BOOLEAN) === true) {
                $signedIn[$articleId] += $record->sum;
            }
            if (filter_var($record->tags->subscriber, FILTER_VALIDATE_BOOLEAN) === true) {
                $subscribers[$articleId] += $record->sum;
            }

            $bar->advance();
        }
        $bar->finish();
        $this->line("\n<info>Pageviews loaded from Journal API</info>");

        if (count($all) === 0) {
            $this->line("No data to store for articles, exiting.");
            return 0;
        }

        $externalIdsChunks =  array_chunk(array_keys($all), 200);

        /** @var ProgressBar $bar */
        $bar = new DebugProxy($this->output->createProgressBar(count($externalIdsChunks)), $debug);
        $bar->setFormat('%message%: [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $bar->setMessage('Storing aggregated data');

        $processedArticles = 0;
        foreach ($externalIdsChunks as $externalIdsChunk) {
            $articles = Article::whereIn(
                'external_id',
                array_map('strval', $externalIdsChunk)
            )->get();

            foreach ($articles as $article) {
                $at = ArticleTimespent::firstOrNew([
                    'article_id' => $article->id,
                    'time_from' => $timeAfter,
                    'time_to' => $timeBefore,
                ]);

                $at->sum = $all[$article->external_id];
                $at->signed_in = $signedIn[$article->external_id];
                $at->subscribers = $subscribers[$article->external_id];
                $at->save();

                $article->timespent_all = $article->timespent()->sum('sum');
                $article->timespent_subscribers = $article->timespent()->sum('subscribers');
                $article->timespent_signed_in = $article->timespent()->sum('signed_in');
                $article->save();
            }

            $processedArticles += count($externalIdsChunk);
            $bar->setMessage(sprintf('Storing aggregated data (<info>%s/%s</info> articles)', $processedArticles, count($all)));
            $bar->advance();
        }
        $bar->finish();
        $this->line(' <info>OK!</info>');
        return 0;
    }
}
