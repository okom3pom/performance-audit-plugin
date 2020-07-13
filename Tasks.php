<?php
/**
 * Matomo - free/libre analytics platform
 *
 * @link https://matomo.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\PerformanceAudit;

require PIWIK_INCLUDE_PATH . '/plugins/PerformanceAudit/vendor/autoload.php';

use ArrayObject;
use CallbackFilterIterator;
use Exception;
use FilesystemIterator;
use OutOfBoundsException;
use Piwik\API\Request;
use Piwik\Common;
use Piwik\DataTable\Map;
use Piwik\Date;
use Piwik\Db;
use Piwik\Log;
use Piwik\Option;
use Piwik\Piwik;
use Piwik\Plugin\Tasks as BaseTasks;
use Piwik\Plugins\PerformanceAudit\Columns\Metrics\Audit;
use Piwik\Plugins\PerformanceAudit\Exceptions\AuditFailedException;
use Piwik\Site;
use Piwik\Tracker\Action;
use Piwik\Tracker\Db\DbException;
use RecursiveArrayIterator;
use RecursiveIteratorIterator;

class Tasks extends BaseTasks
{
    /**
     * Folder name where audit files will be stored.
     */
    private const AUDIT_FOLDER = 'Audits';

    /**
     * Lighthouse instances indexed by site ID.
     *
     * @var Lighthouse[]
     */
    private static $lighthouse;

    /**
     * Schedule tasks.
     *
     * @return void
     */
    public function schedule()
    {
        foreach (Site::getSites() as $site) {
            $this->daily('auditSite', (int) $site['idsite'], self::LOW_PRIORITY);
        }
    }

    /**
     * Runs performance audit for specified site.
     *
     * @param int $idSite
     * @return void
     * @throws Exception
     */
    public function auditSite(int $idSite)
    {
        if ($this->hasAnyTaskStartedToday()) {
            Log::info('Performance Audit tasks have been already started today');
            return;
        }
        Log::info('Performance Audit task for site ' . $idSite . ' will be started now');
        $this->markTaskAsStarted($idSite);
        $siteSettings = new MeasurableSettings($idSite);

        $urls = $this->getPageUrls($idSite, 'last30');
        $runs = range(1, (int) $siteSettings->getSetting('run_count')->getValue());
        $emulatedDevices = EmulatedDevice::getList($siteSettings->getSetting('emulated_device')->getValue());

        $this->performAudits($idSite, $urls, $emulatedDevices, $runs);
        $auditFileCount = iterator_count($this->getAuditFiles($idSite));
        Log::debug('Audit file count: ' . $auditFileCount);
        if ($auditFileCount > 0) {
            $this->storeResultsInDatabase($idSite, $this->processAuditFiles($idSite));
            $this->removeAuditFiles($idSite);
        }
        Log::info('Performance Audit task for site ' . $idSite . ' has finished');
    }

    /**
     * Check if any task has started today.
     *
     * @return bool
     * @throws Exception
     */
    private function hasAnyTaskStartedToday()
    {
        $tasksStartedToday = array_map(function($site) {
            return $this->hasTaskStartedToday((int) $site['idsite']);
        }, Site::getSites());

        return in_array(true, $tasksStartedToday);
    }

    /**
     * Check if this task has started today.
     *
     * @param int $idSite
     * @return bool
     * @throws Exception
     */
    private function hasTaskStartedToday(int $idSite)
    {
        Option::clearCachedOption($this->lastRunKey($idSite));
        $lastRun = Option::get($this->lastRunKey($idSite));
        if (!$lastRun) {
            return false;
        }

        return Date::factory((int) $lastRun)->isToday();
    }

    /**
     * Marks this task as started in DB.
     *
     * @param int $idSite
     * @return void
     * @throws Exception
     */
    private function markTaskAsStarted(int $idSite)
    {
        Log::debug('Mark task for site ' . $idSite . ' as started');
        Option::set($this->lastRunKey($idSite), Date::factory('today')->getTimestamp());
    }

    /**
     * Returns the option name of the option that stores the time for this tasks last execution.
     *
     * @param int $idSite
     * @return string
     */
    private static function lastRunKey($idSite)
    {
        return 'lastRunPerformanceAuditTask_' . $idSite;
    }

    /**
     * Return instance of Lighthouse class (singleton).
     *
     * @param int $idSite
     * @return Lighthouse
     * @throws Exception
     */
    private static function getLighthouse(int $idSite)
    {
        if (!isset(self::$lighthouse[$idSite])) {
            self::$lighthouse[$idSite] = (new Lighthouse())->performance();
            Audit::enableEachLighthouseAudit(self::$lighthouse[$idSite]);

            $siteSettings = new MeasurableSettings($idSite);
            if ($siteSettings->getSetting('has_extra_http_header')->getValue()) {
                self::$lighthouse[$idSite]->setHeaders([
                    $siteSettings->getSetting('extra_http_header_key')->getValue() => $siteSettings->getSetting('extra_http_header_value')->getValue()
                ]);
            }
        }

        return self::$lighthouse[$idSite];
    }

    /**
     * Return all page urls of site with given ID for given date.
     *
     * @param int $idSite
     * @param string $date
     * @return array
     */
    private function getPageUrls(int $idSite, string $date)
    {
        /** @var $dataTables Map */
        $dataTables = Request::processRequest('Actions.getPageUrls', [
            'idSite' => $idSite,
            'date' => $date,
            'period' => 'day',
            'expanded' => 0,
            'depth' => PHP_INT_MAX,
            'flat' => 1,
            'enable_filter_excludelowpop' => 0,
            'include_aggregate_rows' => 0,
            'keep_totals_row' => 0,
            'filter_by' => 'all',
            'filter_offset' => 0,
            'filter_limit' => -1,
            'disable_generic_filters' => 1,
            'format' => 'original'
        ]);

        $urls = [];
        foreach ($dataTables->getDataTables() as $dataTable) {
            foreach ($dataTable->getRows() as $row) {
                $url = $row->getMetadata('url');
                // Push only URLs with HTTP or HTTPS protocol
                if (substr($url, 0, 4) === 'http') {
                    array_push($urls, $url);
                }
            }
        }

        return array_values(array_unique($urls, SORT_STRING));
    }

    /**
     * Perform audits for every combination of urls,
     * emulated devices and amount of runs.
     *
     * @param int $idSite
     * @param array $urls
     * @param array $emulatedDevices
     * @param array $runs
     * @return void
     * @throws Exception
     */
    private function performAudits(int $idSite, array $urls, array $emulatedDevices, array $runs)
    {
        Piwik::postEvent('Performance.performAudit', [$idSite, $urls, $emulatedDevices, $runs]);
        Log::debug('Performing audit for (site ID, URLs, URL count, emulated devices, runs): ' . json_encode([$idSite, $urls, count($urls), $emulatedDevices, $runs]));

        foreach ($urls as $url) {
            foreach ($emulatedDevices as $emulatedDevice) {
                foreach ($runs as $run) {
                    try {
                        Log::info('Performing scheduled audit [' . $run . '/'. count($runs) . '] of site ' . $idSite . ' (device: ' . $emulatedDevice . ') for URL: ' . $url);

                        self::getLighthouse($idSite)
                            ->setOutput(sprintf(
                                '%s%s%d-%s-%s-%d.json',
                                __DIR__ . DIRECTORY_SEPARATOR,
                                self::AUDIT_FOLDER . DIRECTORY_SEPARATOR,
                                $idSite,
                                $emulatedDevice,
                                sha1($this->getUrlWithoutProtocolAndSubdomain($url, 'www')),
                                $run
                            ))
                            ->setEmulatedDevice($emulatedDevice)
                            ->audit($url);
                    } catch (AuditFailedException $exception) {
                        echo $exception->getMessage();
                    }
                }
            }
        }

        Piwik::postEvent('Performance.performAudit.end', [$idSite, $urls, $emulatedDevices, $runs]);
    }

    /**
     * Returns iterator for all JSON audit files of specific site.
     *
     * @param int $idSite
     * @return CallbackFilterIterator
     */
    private function getAuditFiles(int $idSite)
    {
        return new CallbackFilterIterator(
            new FilesystemIterator(
                __DIR__ . DIRECTORY_SEPARATOR . self::AUDIT_FOLDER,
                FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS
            ),
            function ($file) use ($idSite) {
                $idSiteFile = intval(current(explode('-', $file->getFilename(), 2)));
                return $file->getExtension() === 'json' && $idSiteFile === $idSite;
            }
        );
    }

    /**
     * Do the heavy lifting part here: Parse all audit files,
     * calculate their mean values by groups and return results.
     *
     * @param int $idSite
     * @return array
     */
    private function processAuditFiles(int $idSite)
    {
        $auditFiles = $this->getAuditFiles($idSite);
        Log::debug('Process Audit files: ' . json_encode(iterator_to_array($auditFiles)));
        $temporaryResults = [];
        foreach ($auditFiles as $auditFile) {
            $auditFileBasename = $auditFile->getBasename('.' . $auditFile->getExtension());
            [$auditIdSite, $auditEmulatedDevice, $auditUrl, ] = explode('-', $auditFileBasename);

            $audit = json_decode(file_get_contents($auditFile->getPathname()), true);
            $metrics = $audit['audits']['metrics'];
            $score = $audit['categories']['performance']['score'];
            if (!isset($metrics['details']['items'][0])) continue;

            $metricItems = array_intersect_key(
                array_merge($metrics['details']['items'][0], ['score' => intval($score * 100)]),
                array_flip(array_values(Audit::METRICS))
            );

            $currentAudit = &$temporaryResults[$auditIdSite][$auditUrl][$auditEmulatedDevice];
            $this->appendMetricValues($currentAudit, $metricItems);
        }
        Log::debug('Audit files processed as: ' . json_encode($temporaryResults));

        if (empty($temporaryResults)) {
            Log::warning('Audit files result is empty!');

            return [];
        }

        $results = $this->calculateMetricMinMaxMeanValuesAtDepth($temporaryResults, 3);
        Log::debug('Final audit values: ' . json_encode($results));

        return $results;
    }

    /**
     * Append metric values to current audit array object
     * or create one if not present yet.
     *
     * @param ArrayObject|null $currentAudit
     * @param array $metricItems
     * @return void
     */
    private function appendMetricValues(&$currentAudit, array $metricItems)
    {
        if (empty($currentAudit)) {
            $currentAudit = new ArrayObject(array_map(function ($item) {
                return new ArrayObject([$item]);
            }, $metricItems));
        } else {
            foreach ($currentAudit as $metricName => $metricValue) {
                $metricValue->append($metricItems[$metricName]);
            }
        }
    }

    /**
     * Recursively iterate through metric array
     * and calculate min, max and rounded mean values at given depth.
     *
     * @param array $metrics
     * @param int $depth
     * @return array
     */
    private function calculateMetricMinMaxMeanValuesAtDepth(array $metrics, int $depth)
    {
        $recursiveMetricIterator = new RecursiveIteratorIterator(
            new RecursiveArrayIterator($metrics),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $recursiveMetricIterator->setMaxDepth($depth);

        foreach ($recursiveMetricIterator as $key => $item) {
            if ($recursiveMetricIterator->getDepth() === $depth) {
                $recursiveMetricIterator->getInnerIterator()->offsetSet(
                    $key,
                    [
                        intval(min($item->getArrayCopy())),
                        intval(round($this->calculateMean($item->getArrayCopy()), 0)),
                        intval(max($item->getArrayCopy()))
                    ]
                );
            }
        }

        return $recursiveMetricIterator->getInnerIterator()->getArrayCopy();
    }

    /**
     * Returns mean for array values.
     *
     * @param array $values
     * @return float|int
     */
    private function calculateMean(array $values)
    {
        $count = count($values);
        if ($count < 1) {
            return 0;
        }
        sort($values, SORT_NUMERIC);
        $middleIndex = floor(($count - 1) / 2);
        $middleIndexNext = $middleIndex + 1 - ($count % 2);

        return ($values[$middleIndex] + $values[$middleIndexNext]) / 2;
    }

    /**
     * Removes all audit files for specific site.
     *
     * @param int $idSite
     * @return void
     */
    private function removeAuditFiles(int $idSite)
    {
        $auditFiles = $this->getAuditFiles($idSite);
        foreach ($auditFiles as $auditFile) {
            unlink($auditFile->getPathname());
        }
    }

    /**
     * Returns url without HTTP(S) protocol and given subdomain.
     *
     * @param string $url
     * @param string $subdomain
     * @return string|null
     */
    private function getUrlWithoutProtocolAndSubdomain(string $url, string $subdomain)
    {
        return preg_replace('(^https?://(' . preg_quote($subdomain) . '\.)?)', '', $url);
    }

    /**
     * Stores results in database.
     *
     * @param int $idSite
     * @param array $results
     * @return void
     * @throws Exception
     */
    private function storeResultsInDatabase(int $idSite, array $results)
    {
        if (!isset($results[$idSite])) {
            Log::warning('Results for database storage is either empty or site results is not available');

            return;
        }

        $siteResult = $results[$idSite];
        $urls = array_keys($siteResult);
        $actionIdLookupTable = $this->getActionLookupTable($urls, 'id');
        $today = Date::factory('today')->getDatetime();

        $rowsInserted = 0;
        foreach ($siteResult as $url => $emulatedDevices) {
            foreach ($emulatedDevices as $emulatedDevice => $metrics) {
                foreach ($metrics as $key => $values) {
                    [$min, $median, $max] = $values;

                    $result = Db::get()->query('
                        INSERT INTO `' . Common::prefixTable('log_performance') . '`
                        (`idsite`, `emulated_device`, `idaction`, `key`, `min`, `median`, `max`, `created_at`)
                        VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?)
                    ', [
                        $idSite,
                        EmulatedDevice::getIdFor($emulatedDevice),
                        $actionIdLookupTable[$url],
                        $key,
                        $min,
                        $median,
                        $max,
                        $today
                    ]);
                    $rowsInserted += Db::get()->rowCount($result);
                }
            }
        }
        Log::debug('Stored ' . $rowsInserted . ' entries in database');
    }

    /**
     * Create lookup table for action information based on
     * their sha1 hash value of its URL.
     *
     * @param array $urls
     * @param string $type
     * @return array
     * @throws DbException
     */
    private function getActionLookupTable(array $urls, string $type)
    {
        if (!in_array($type, ['id', 'url', 'url_prefix'])) {
            throw new OutOfBoundsException($type . ' is invalid value for action lookup table.');
        }

        $whereNamePlaceholder = implode(',', array_fill(0, count($urls), '?'));
        $actionInformation = Db::getReader()->fetchAll('
            SELECT
                `idaction` AS `id`,
                `name` AS `url`,
                `url_prefix`,
                SHA1(`name`) AS `hash`
            FROM ' . Common::prefixTable('log_action') . '
            WHERE
                `type` = ? AND
                SHA1(`name`) IN (' . $whereNamePlaceholder . ')
        ', array_merge(
            [Action::TYPE_PAGE_URL],
            $urls
        ));

        $actionLookupTable = [];
        foreach ($actionInformation as $action) {
            $actionLookupTable[$action['hash']] = $action[$type];
        }
        Log::debug('Action IDs lookup table with URLs: ' . json_encode([$actionLookupTable, $urls]));

        return $actionLookupTable;
    }
}