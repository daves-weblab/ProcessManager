<?php

namespace Elements\Bundle\ProcessManagerBundle\Controller;

use Elements\Bundle\ProcessManagerBundle\Executor\Action\AbstractAction;
use Elements\Bundle\ProcessManagerBundle\Executor\Logger\AbstractLogger;
use Elements\Bundle\ProcessManagerBundle\Executor\Logger\Application;
use Elements\Bundle\ProcessManagerBundle\Executor\Logger\File;
use Elements\Bundle\ProcessManagerBundle\Model\Configuration;
use Elements\Bundle\ProcessManagerBundle\Model\MonitoringItem;
use Pimcore\Bundle\AdminBundle\Controller\AdminController;
use Pimcore\Controller\Configuration\TemplatePhp;
use Pimcore\Templating\Model\ViewModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/elementsprocessmanager/monitoring-item")
 */
class MonitoringItemController extends AdminController
{

    /**
     * @Route("/list")
     * @param Request $request
     * @return JsonResponse
     */
    public function listAction(Request $request)
    {
        $this->checkPermission('plugin_pm_permission_view');
        $data = [];
        $list = new MonitoringItem\Listing();
        $list->setOrder('DESC');
        $list->setOrderKey('id');
        $list->setLimit($request->get('limit', 25));
        $list->setUser($this->getUser());

        $list->setOffset($request->get("start"));

        $allParams = array_merge($request->request->all(), $request->query->all());
        $sortingSettings = \Pimcore\Admin\Helper\QueryParams::extractSortingSettings($allParams);
        if ($sortingSettings['orderKey'] && $sortingSettings['order']) {
            $list->setOrderKey($sortingSettings['orderKey']);
            $list->setOrder($sortingSettings['order']);
        }


        $callbacks = [
            'executedByUser' => function ($f) {
                $db = \Pimcore\Db::get();
                $ids = $db->fetchCol("SELECT id FROM users where name LIKE ".$db->quote("%".$f->value."%")) ?: [0];

                return ' executedByUser IN( '.implode(',', $ids).') ';
            },
        ];
        if ($filterCondition = \Pimcore\Admin\Helper\QueryParams::getFilterCondition(
            $request->get('filter'),
            ['id', 'o_id', 'pid'],
            true,
            $callbacks
        )
        ) {
            $list->setCondition($filterCondition);
        }

        $total = $list->getTotalCount();


        foreach ($list->load() as $item) {
            $tmp = $item->getObjectVars();
            $tmp['steps'] = '-';
            if ($item->getTotalSteps() > 0 || $item->getCurrentStep()) {
                $tmp['steps'] = $item->getCurrentStep().'/'.$item->getTotalSteps();
            }
            $tmp['duration'] = $item->getDuration() ?: '-';
            $tmp['progress'] = 0;


            if ($tmp['executedByUser']) {
                $user = \Pimcore\Model\User::getById($tmp['executedByUser']);
                if ($user) {
                    $tmp['executedByUser'] = $user->getName();
                } else {
                    $tmp['executedByUser'] = 'User id: '.$tmp['executedByUser'];
                }
            } else {
                $tmp['executedByUser'] = 'System';
            }

            $logFile = 0;
            /* if(is_readable($item->getLogFile())){
                 $content = trim(file_get_contents($item->getLogFile()));
                 if($content){
                     $logFile = 1;
                 }
             }*/
            $tmp['action'] = '';

            if ($actions = $item->getActions()) {
                foreach ($actions as $action) {
                    /**
                     * @var $class AbstractAction
                     */
                    $class = new $action['class'];
                    if ($s = $class->getGridActionHtml($item, $action)) {
                        $tmp['action'] .= $s;
                    }
                }
            }

            $tmp['logger'] = '';
            if ($loggers = $item->getLoggers()) {
                foreach ((array)$loggers as $i => $logger) {
                    /**
                     * @var $class AbstractLogger
                     */
                    $class = new $logger['class'];
                    if (\Pimcore\Tool::classExists(get_class($class))) {
                        $logger['index'] = $i;
                        if ($s = $class->getGridLoggerHtml($item, $logger)) {
                            $tmp['logger'] .= $s;
                        }
                    }
                }
            }

            $tmp['retry'] = 1;
            if ($item->isAlive()) {
                $tmp['retry'] = 0;
            }

            if ($tmp['retry'] == 1) {
                $config = Configuration::getById($item->getConfigurationId());
                if ($config) {
                    if ($config->getActive() == 0) {
                        $tmp['retry'] = 0;
                    } else {
                        if ($config->getExecutorClassObject()->getValues()['uniqueExecution']) {
                            $runningProcesses = $config->getRunningProcesses();
                            if (!empty($runningProcesses)) {
                                $tmp['retry'] = 0;
                            }
                        }
                    }

                }
            }
            $tmp['isAlive'] = $item->isAlive();

            $tmp['progress'] = '-';
            if ($item->getCurrentWorkload() && $item->getTotalWorkload()) {
                $progress = $item->getProgressPercentage();
                $tmp['progress'] = '<div class="x-progress x-progress-default x-border-box" style="width:100%;"><div class="x-progress-text x-progress-text-back">'.$progress.'%</div><div class="x-progress-bar x-progress-bar-default" style="width:'.$progress.'%"><div class="x-progress-text"><div>'.$progress.'%</div></div></div></div>';
            }

            $tmp['callbackSettingsString'] = json_encode($item->getCallbackSettings());
            $tmp['callbackSettings'] = $item->getCallbackSettingsForGrid();
            #$tmp['callbackSettings'] = '<table><tr><td><th>Key</th><th>Value</th></td></tr><tr><td>name:</td><td>testaa</td></tr></table>';
            $data[] = $tmp;
        }

        return $this->json(['success' => true, 'total' => $total, 'data' => $data]);
    }

    /**
     * @Route("/log-application-logger")
     * @param Request $request
     * @return JsonResponse
     */
    public function logApplicationLoggerAction(Request $request)
    {
        try {
            $monitoringItem = MonitoringItem::getById($request->get('id'));

            if (!$monitoringItem) {
                throw new \Exception('Monitoring Item with id'.$request->get('id').' not found');
            }
            $loggerIndex = $request->get('loggerIndex');
            if ($loggers = $monitoringItem->getLoggers()) {
                foreach ((array)$loggers as $i => $config) {
                    /**
                     * @var $class AbstractLogger
                     * @var $logger Application
                     */
                    $class = new $config['class'];
                    if (\Pimcore\Tool::classExists(get_class($class))) {

                        if ($i == $loggerIndex) {
                            $logger = $class;
                            if (!$config['logLevel']) {
                                $config['logLevel'] = 'DEBUG';
                            }
                            break;
                        }
                    }
                }
            }

            $result = $monitoringItem->getObjectVars();
            $result['logLevel'] = strtolower($config['logLevel']);

            return $this->json(['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * @Route("/log-file-logger")
     * @TemplatePhp()
     * @param Request $request
     * @return ViewModel
     */
    public function logFileLoggerAction(Request $request)
    {
        $viewData = array();
        $monitoringItem = MonitoringItem::getById($request->get('id'));

        $loggerIndex = $request->get('loggerIndex');
        if ($loggers = $monitoringItem->getLoggers()) {
            foreach ((array)$loggers as $i => $config) {
                /**
                 * @var $class AbstractLogger
                 * @var $logger File
                 */
                $class = new $config['class'];
                if (\Pimcore\Tool::classExists(get_class($class))) {

                    if ($i == $loggerIndex) {
                        $logger = $class;
                        $logFile = $logger->getLogFile($config, $monitoringItem);
                        if (!$config['logLevel']) {
                            $config['logLevel'] = 'DEBUG';
                        }
                        break;
                    }
                }
            }
        }
        $viewData["logLevel"] = $config['logLevel'];
        $viewData["logFile"] = $logFile;

        if (is_readable($logFile)) {

            $data = file_get_contents($logFile);

            $data = explode("\n", $data);
            foreach ($data as $i => $row) {
                if ($row) {
                    if (strpos($row, '.WARNING')) {
                        $data[$i] = '<span style="color:#ffb13b">'.$row.'</span>';
                    }
                    if (strpos($row, '.ERROR')) {
                        $data[$i] = '<span style="color:#ff131c">'.$row.'</span>';
                    }
                    if (strpos($row, 'dev-server > ') === 0 || strpos($row, 'production-server > ') === 0) {
                        $data[$i] = '<span style="color:#35ad33">'.$row.'</span>';
                    }
                    foreach (['[echo]', '[mkdir]', '[delete]', '[copy]'] as $k) {
                        if (strpos($row, $k)) {
                            $data[$i] = '<span style="color:#49b7d4">'.$row.'</span>';
                        }
                    }
                }
            }
        } else {
            $data = ["Log file doesn't exist. ".$logFile];
        }
        $data = implode("\n", $data);

        $viewData["data"] = $data;
        $viewData["monitoringItem"] = $monitoringItem;

        $viewModel = new ViewModel($viewData);

        return $viewModel;

    }

    /**
     * @Route("/delete")
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteAction(Request $request)
    {
        $entry = MonitoringItem::getById($request->get('id'));
        if ($entry) {
            $entry->delete();

            return $this->json(['success' => true]);
        }

        return $this->json(['success' => false, 'message' => "Couldn't delete entry"]);
    }

    /**
     * @Route("/delete-batch")
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteBatchAction(Request $request)
    {
        $logLevels = array_filter(explode(',', $request->get('logLevels')));
        if (!empty($logLevels)) {
            $list = new MonitoringItem\Listing();
            $conditions = [];
            foreach ($logLevels as $loglevel) {
                $conditions[] = ' status ="'.$loglevel.'" ';
            }
            $condition = implode(' OR ', $conditions);
            $list->setCondition($condition);
            $items = $list->load();
            foreach ($items as $item) {
                $item->delete();
            }

            return $this->json(['success' => true]);
        } else {
            return $this->json(
                [
                    'success' => false,
                    'message' => 'No statuses -> didn\'t deleted logs. Please select at least one status',
                ]
            );
        }
    }

    /**
     * @Route("/cancel")
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelAction(Request $request)
    {
        $monitoringItem = MonitoringItem::getById($request->get('id'));
        try {
            $pid = $monitoringItem->getPid();
            if ($pid) {
                $message = 'Process with PID "'.$pid.'" killed by Backend User: '.$request->getUser()->getName();
                $monitoringItem->getLogger()->warning($message);
                $monitoringItem->setPid(null)->setStatus($monitoringItem::STATUS_FAILED)->save();
                \Pimcore\Tool\Console::exec('kill -9 '.$pid);
            }

            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }

    }

    /**
     * @Route("/restart")
     * @param Request $request
     * @return JsonResponse
     */
    public function restartAction(Request $request)
    {
        try {
            $monitoringItem = MonitoringItem::getById($request->get('id'));
            $monitoringItem->deleteLogFile()->resetState()->save();
            \Pimcore\Tool\Console::execInBackground($monitoringItem->getCommand(), $monitoringItem->getLogFile());

            return $this->json(['success' => true]);
        } catch (\Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}