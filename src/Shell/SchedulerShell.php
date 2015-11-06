<?php


class SchedulerShell extends AppShell {

    public $tasks = array();

    /*
        The array of scheduled tasks.
    */
    private $schedule = array();


    private $activeThreads = array();

    /*
        The key which you set Configure::read() for your jobs
    */
    private $configKey = 'SchedulerShell';

    /*
        The path where the store file is placed. null will store in Config folder
    */
    private $storePath = null;

    /*
        The file name of the store
    */
    private $storeFile = 'cron_scheduler.json';


    /*
        The main method which you want to schedule for the most frequent interval
    */
    public function main() {

        // read in the config
        if ($config = Configure::read($this->configKey)) {

            if (isset($config['storePath']))
                $this->storePath = $config['storePath'];

            if (isset($config['storeFile']))
                $this->storeFile = $config['storeFile'];

            // read in the jobs from the config
            if (isset($config['jobs'])) {
                foreach ($config['jobs'] as $k => $v) {
                    $v = $v + array('action' => 'execute', 'pass' => array());
                    $this->connect($k, $v['interval'], $v['task'], $v['action'], $v['pass']);
                }
            }
        }

        // ok, run them when they're ready
        $this->runjobs();
    }

    /*
        The connect method adds tasks to the schedule
        @name string - unique name for this job, isn't bound to anything and doesn't matter what it is
        @interval string - date interval string "PT5M" (every 5 min) or a relative Date string "next day 10:00"
        @task string - name of the cake task to call
        @action string - name of the method within the task to call
        @pass - array of arguments to pass to the method
    */
    public function connect($name, $interval, $task, $action = 'execute', $pass = array()) {
        $this->schedule[$name] = array(
            'name' => $name,
            'interval' => $interval,
            'task' => $task,
            'action' => $action,
            'args' => $pass,
            'lastRun' => null,
            'runningPID' => null,
            'lastResult' => ''

        );
    }

    /*
        Process the tasks when they need to run
    */
    private function runjobs() {
        if (!$this->storePath)
            $this->storePath = TMP;

        // look for a store of the previous run
        $store = "";
        $storeFilePath = $this->storePath . $this->storeFile;
        if (file_exists($storeFilePath))
            $store = file_get_contents($storeFilePath);
        $this->out(date(DATE_SQL) . ' Reading from: ' . $storeFilePath);

        // build or rebuild the store
        if ($store != '')
            $store = json_decode($store, true);
        else $store = $this->schedule;

        App::import('Vendor', 'Fork', array('file' => 'duncan3dc/fork-helper/src/Fork.php'));

        $fork = new \duncan3dc\Helpers\Fork;

        //debug($this->schedule);
        //debug($store);

        // run the jobs that need to be run, record the time
        foreach ($this->schedule as $name => $job) {
            $now = new DateTime();
            $task = $job['task'];
            $action = $job['action'];

            // if the job has never been run before, create it
            if (!isset($store[$name]))
                $store[$name] = $job;

            // figure out the last run date
            $tmptime = $store[$name]['lastRun'];
            if ($tmptime == null)
                $tmptime = new DateTime("1969-01-01 00:00:00");
            elseif (is_array($tmptime))
                $tmptime = new DateTime($tmptime['date'], new DateTimeZone($tmptime['timezone']));
            elseif (is_string($tmptime))
                $tmptime = new DateTime($tmptime);

            
            $runNow=false;
            // determine the next run time based on the last            
            if (substr($job['interval'], 0, 1) === 'P'){
                $tmptime->add(new DateInterval($job['interval'])); // "P10DT4H" http://www.php.net/manual/en/class.dateinterval.php
                $runNow=$tmptime <= $now;
            }elseif(strtotime($job['interval'])!==false){
                $tmptime->modify($job['interval']);    // "next day 10:30" http://www.php.net/manual/en/datetime.formats.relative.php
                $runNow=$tmptime <= $now;
            }else{ //cron expression
                //https://github.com/mtdowling/cron-expression
                $cron=Cron\CronExpression::factory($job['interval']);                

                $runNow=$cron->getNextRunDate($tmptime) <= $now;
                
                $tmptime=$cron->getNextRunDate(); //override for beter log readingz
            }

            $this->out('JOB ' . $job['name'] . ' next run time: ' . $tmptime->format(DATE_SQL));

            // is it time to run? has it never been run before? //aditionaly, is it currently running
            if ($runNow && empty($store[$name]['runningPID'])) {
                $this->out(date(DATE_SQL) . " Running $name --------------------------------------- ");

                if (!isset($this->$task)) {
                    $this->$task = $this->Tasks->load($task);

                    // load models if they aren't already
                    foreach ($this->$task->uses as $mk => $mv) {
                        if (!isset($this->$task->$mv)) {
                            App::uses('AppModel', 'Model');
                            App::uses($mv, 'Model');
                            $this->$task->$mv = new $mv();
                        }
                    }
                }

                // grab the entire schedule record incase it was updated..
                $store[$name] = $this->schedule[$name];

                // execute the task and store the result
                //$store[$name]['lastResult'] = call_user_func_array(array($this->$task, $action), $job['args']);


                //$reportInvoicesTask = $this->Tasks->load('ReportInvoices');
                //$fork->call(array($reportInvoicesTask,"execute"),array('2014-01-01 00:00:00','2016-01-01 00:00:00',"invoiceDaily"));

                //$store[$name]['lastResult'] = $fork->call(array($this->$task, $action), $job['args']);
                $store[$name]['runningPID'] = $fork->call(array($this->$task, $action), $job['args']);
                $this->out(date(DATE_SQL) . " Started $name as PID: " . $store[$name]['runningPID']);
                $this->activeThreads[] = $store[$name]['runningPID'];
                //unset($reportInvoicesTask);

                // assign it the current time
                $now = new DateTime();
                $store[$name]['lastRun'] = $now->format('Y-m-d H:i:s');
            }
        }

        // write the store back to the file
        file_put_contents($this->storePath . $this->storeFile, json_encode($store));

        $this->out(date(DATE_SQL) . ' All pending tasks are no longer pending');

        //$runningProcesses=Hash::extract($store,"{s}.runningPID");

        //debug($runningProcesses);

        for ($pcnt = 0; $pcnt < count($this->activeThreads); $pcnt++) {
            try {
                $endedPID = $fork->waitAny();

                $store = file_get_contents($storeFilePath);
                $store = json_decode($store, true);

                foreach($store as $v) {
                    if($v['runningPID'] == $endedPID) {
                        $finishedTask = $v;
                        break;
                    }
                }

                $this->out(date(DATE_SQL) . " Ended " . $finishedTask['name'] . " as PID: " . $endedPID);

                $store[$finishedTask['name']]['runningPID'] = null;

                file_put_contents($this->storePath . $this->storeFile, json_encode($store));

            } catch (Exception $e) {
                $message = $e->getMessage();
                $endedPID = $e->getCode();

                $store = file_get_contents($storeFilePath);
                $store = json_decode($store, true);

                foreach($store as $v) {
                    if($v['runningPID'] == $endedPID) {
                        $finishedTask = $v;
                        break;
                    }
                }

                $this->out(date(DATE_SQL) . " Ended " . $finishedTask['name'] . " as PID: " . $endedPID . " with EXCEPTION: \n" . $message);

                $store[$finishedTask['name']]['runningPID'] = null;
                $store[$finishedTask['name']]['lastResult'] = $message;

                file_put_contents($this->storePath . $this->storeFile, json_encode($store));
            }
        }
    }

}