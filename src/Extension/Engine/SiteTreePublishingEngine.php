<?php

namespace SilverStripe\StaticPublishQueue\Extension\Engine;

use SilverStripe\CMS\Model\SiteTreeExtension;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\StaticPublishQueue\Contract\StaticPublishingTrigger;
use SilverStripe\StaticPublishQueue\Extension\Publishable\PublishableSiteTree;
use SilverStripe\StaticPublishQueue\Job\DeleteStaticCacheJob;
use SilverStripe\StaticPublishQueue\Job\GenerateStaticCacheJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use SilverStripe\Core\Injector\Injector;

/**
 * This extension couples to the StaticallyPublishable and StaticPublishingTrigger implementations
 * on the SiteTree objects and makes sure the actual change to SiteTree is triggered/enqueued.
 *
 * Provides the following information as a context to StaticPublishingTrigger:
 * * action - name of the executed action: publish or unpublish
 *
 * @see PublishableSiteTree
 */
class SiteTreePublishingEngine extends SiteTreeExtension
{
    use Configurable;

    /**
     * Maximum limit of processed urls per job
     * this is useful when too many urls in a job make the job too demanding to run
     * setting this to 0 (default) will leave number of urls unlimited, which will put all urls into a single job
     * This does not apply to cache deletion, as that work is much lighter.
     *
     * @config
     * @var int
     */
    private static $max_urls_per_job = 0;

    /**
     * Queues the urls to be flushed into the queue.
     *
     * @var array
     */
    private $toUpdate = array();

    /**
     * Queues the urls to be deleted as part of a next flush operation.
     *
     * @var array
     */
    private $toDelete = array();

    /**
     * @return array
     */
    public function getToUpdate()
    {
        return $this->toUpdate;
    }

    /**
     * @return array
     */
    public function getToDelete()
    {
        return $this->toDelete;
    }

    /**
     * @param array $toUpdate
     * @return $this
     */
    public function setToUpdate($toUpdate)
    {
        $this->toUpdate = $toUpdate;
        return $this;
    }

    /**
     * @param $toDelete
     * @return $this
     */
    public function setToDelete($toDelete)
    {
        $this->toDelete = $toDelete;
        return $this;
    }

    /**
     * @param \SilverStripe\CMS\Model\SiteTree|null $original
     */
    public function onAfterPublish(&$original)
    {
        // if the site tree has been "reorganised" (ie: the parentID has changed)
        // then this is eht equivalent of an unpublish and publish as far as the
        // static publisher is concerned
        if ($original && (
                $original->ParentID != $this->getOwner()->ParentID
                || $original->URLSegment != $this->getOwner()->URLSegment
            )
        ) {
            $context = [
                'action' => 'unpublish',
            ];
            $original->collectChanges($context);
            $original->flushChanges();
        }
        $context = [
            'action' => 'publish',
        ];
        $this->collectChanges($context);
        $this->flushChanges();
    }

    public function onBeforeUnpublish()
    {
        $context = array(
            'action' => 'unpublish'
        );
        $this->collectChanges($context);
    }

    public function onAfterUnpublish()
    {
        $this->flushChanges();
    }

    /**
     * Collect all changes for the given context.
     *
     * @param array $context
     */
    public function collectChanges($context)
    {
        Environment::increaseMemoryLimitTo();
        Environment::increaseTimeLimitTo();

        if ($this->getOwner()->hasExtension(PublishableSiteTree::class)
            || $this->getOwner() instanceof StaticPublishingTrigger
        ) {
            $toUpdate = $this->getOwner()->objectsToUpdate($context);
            $this->setToUpdate($toUpdate);

            $toDelete = $this->getOwner()->objectsToDelete($context);
            $this->setToDelete($toDelete);
        }
    }

    /**
     * Execute URL deletions, enqueue URL updates.
     */
    public function flushChanges()
    {
        $queue = QueuedJobService::singleton();
        if (!empty($this->toUpdate)) {
            foreach ($this->toUpdate as $queueItem) {
                $urls = $queueItem->urlsToCache();
                $urlsLimit = static::config()->get('max_urls_per_job');
                $urlsLimit = ($urlsLimit) ? (int) $urlsLimit : 0;
                $batches = ($urlsLimit > 0) ? $batches = array_chunk($urls, $urlsLimit, true) : [$urls];

                foreach ($batches as $urls) {
                    $job = Injector::inst()->create(GenerateStaticCacheJob::class);

                    $jobData = new \stdClass();

                    ksort($urls);
                    $jobData->URLsToProcess = $urls;

                    $job->setJobData(0, 0, false, $jobData, [
                        'Building URLs: ' . var_export(array_keys($jobData->URLsToProcess), true)
                    ]);

                    $queue->queueJob($job);
                }
            }
            $this->toUpdate = array();
        }

        if (!empty($this->toDelete)) {
            foreach ($this->toDelete as $queueItem) {
                $job = Injector::inst()->create(DeleteStaticCacheJob::class);

                $jobData = new \stdClass();
                $urls = $queueItem->urlsToCache();
                ksort($urls);
                $jobData->URLsToProcess = $urls;

                $job->setJobData(0, 0, false, $jobData, [
                    'Purging URLs: ' . var_export(array_keys($jobData->URLsToProcess), true)
                ]);

                $queue->queueJob($job);
            }
            $this->toDelete = array();
        }
    }
}
